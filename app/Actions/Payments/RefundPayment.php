<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Payment;
use App\Models\Reservation;
use App\Payments\GatewayManager;
use App\Payments\RefundResult;
use Illuminate\Support\Facades\DB;

/**
 * Money back (SPEC §8.3).
 *
 * The rules that matter, all of them learned the expensive way:
 *
 *  - an idempotency key per attempt, so a double-clicked button refunds once
 *  - never say "refunded" before the gateway does; `refund_pending` until then
 *  - the running total is checked here, so several partial refunds can never
 *    add up to more than the charge
 *  - a failure is reported, not swallowed: the money comes back to the venue
 *    and somebody has to arrange a transfer
 */
final readonly class RefundPayment
{
    public function __construct(private GatewayManager $gateways) {}

    public function handle(Payment $payment, int $amountCents, ?string $reason = null): RefundResult
    {
        if (! $payment->isPaid()) {
            return RefundResult::failed('That payment never completed, so there is nothing to refund.');
        }

        $refundable = $payment->refundableCents();

        if ($amountCents <= 0 || $amountCents > $refundable) {
            return RefundResult::failed(sprintf(
                'You can refund up to %s on this payment.',
                number_format($refundable / 100, 2),
            ));
        }

        [$gateway, $setting] = $this->gateways->resolve($payment->gateway);

        // Keyed on the amount already refunded, so a retry of the same
        // attempt is identical and a genuine second refund is not.
        $key = sprintf('refund:%d:%d', $payment->id, $payment->refunded_amount_cents);

        $result = $gateway->refund(
            $setting,
            (string) $payment->gateway_payment_id,
            $amountCents,
            $key,
        );

        DB::transaction(function () use ($payment, $result, $amountCents, $reason): void {
            if (! $result->accepted) {
                $payment->forceFill([
                    'refund_status' => 'failed',
                    'refund_failure_reason' => $result->failureReason,
                ])->save();

                return;
            }

            $refunded = (int) $payment->refunded_amount_cents + $amountCents;

            $payment->forceFill([
                'refunded_amount_cents' => $refunded,
                'refund_status' => $result->isPending ? 'pending' : 'completed',
                'refund_reference' => $result->reference ?? $payment->refund_reference,
                'refund_failure_reason' => null,
                'status' => $result->isPending
                    ? PaymentStatus::RefundPending
                    : $this->statusAfter($payment, $refunded),
            ])->save();

            $this->updateReservation($payment, $refunded, $result->isPending, $reason);
        });

        return $result;
    }

    private function statusAfter(Payment $payment, int $refunded): PaymentStatus
    {
        return $refunded >= (int) $payment->amount_cents
            ? PaymentStatus::Refunded
            : PaymentStatus::PartiallyRefunded;
    }

    private function updateReservation(Payment $payment, int $refunded, bool $pending, ?string $reason): void
    {
        $reservation = Reservation::query()->find($payment->reservation_id);

        if ($reservation === null) {
            return;
        }

        $full = $refunded >= (int) $payment->amount_cents;

        $reservation->forceFill([
            'payment_status' => $pending
                ? PaymentStatus::RefundPending
                : ($full ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded),

            // A refund does not cancel a booking on its own: a venue may well
            // refund a deposit and still expect the guest (SPEC §8.3).
            'status' => $reservation->status === ReservationStatus::CancelledByVenue && $full && ! $pending
                ? ReservationStatus::Refunded
                : $reservation->status,
        ])->save();

        if ($reason !== null && blank($reservation->cancellation_reason)) {
            $reservation->forceFill(['cancellation_reason' => $reason])->save();
        }
    }
}
