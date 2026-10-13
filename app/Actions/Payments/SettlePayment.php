<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Events\ReservationWasCancelled;
use App\Events\ReservationWasConfirmed;
use App\Models\Payment;
use App\Models\Reservation;
use App\Payments\GatewayManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Decides what a paid checkout actually means (SPEC §24.1, §24.2).
 *
 * Called from three places that must all agree: the webhook, the page the
 * guest comes back to, and the hourly reconciliation. It is therefore
 * idempotent — running it twice on the same payment changes nothing the
 * second time.
 *
 * The last line of defence lives here. Between the guest leaving for the
 * gateway and the money arriving, the spot may have gone. Rather than confirm
 * a booking that cannot be honoured, the payment is refunded in full and both
 * sides are told. We never keep money for a reservation that does not exist.
 */
final readonly class SettlePayment
{
    public function __construct(
        private GatewayManager $gateways,
        private RefundPayment $refunds,
    ) {}

    public function handle(Payment $payment): Payment
    {
        if ($payment->isPaid()) {
            return $payment;
        }

        [$gateway, $setting] = $this->gateways->resolve($payment->gateway);

        $result = $gateway->fetchPayment($setting, (string) $payment->session_id);

        if (! $result->isPaid()) {
            if ($result->status === PaymentStatus::Failed) {
                $payment->forceFill([
                    'status' => PaymentStatus::Failed,
                    'payload' => $result->payload,
                ])->save();
            }

            return $payment;
        }

        return DB::transaction(function () use ($payment, $result): Payment {
            $payment->forceFill([
                'status' => PaymentStatus::Paid,
                'gateway_payment_id' => $result->gatewayPaymentId,
                'paid_at' => now(),
                'payload' => $result->payload,
            ])->save();

            $reservation = Reservation::query()->lockForUpdate()->find($payment->reservation_id);

            if ($reservation === null) {
                return $payment;
            }

            if ($this->spotWasTakenMeanwhile($reservation)) {
                $this->apologise($payment, $reservation);

                return $payment;
            }

            $reservation->forceFill([
                'status' => ReservationStatus::Confirmed,
                'payment_status' => PaymentStatus::Paid,
            ])->save();

            // Now, and only now, is there something to send the guest. The
            // listener checks that no voucher has gone out already, because
            // the webhook, the return page and reconciliation can all arrive
            // here for the same payment (SPEC §24.2).
            event(ReservationWasConfirmed::from($reservation));

            return $payment;
        });
    }

    /**
     * Somebody else's confirmed booking now overlaps this one. Rare, and the
     * only honest answer is to give the money back.
     */
    private function spotWasTakenMeanwhile(Reservation $reservation): bool
    {
        if ($reservation->resource_id === null) {
            return false;
        }

        return Reservation::query()
            ->blocking()
            ->whereKeyNot($reservation->id)
            ->where('resource_id', $reservation->resource_id)
            ->overlapping($reservation->starts_at, $reservation->ends_at)
            ->exists();
    }

    private function apologise(Payment $payment, Reservation $reservation): void
    {
        $reason = 'The spot was taken while the payment was being made.';

        $reservation->forceFill([
            'status' => ReservationStatus::CancelledByVenue,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ])->save();

        $this->refunds->handle($payment, (int) $payment->amount_cents, 'Double booking — refunded in full');

        event(ReservationWasCancelled::from($reservation, (int) $payment->amount_cents, $reason));

        // Loud on purpose: the owner may well get a phone call about this
        // before they read any email.
        Log::warning('Refunded a payment for a spot that was taken meanwhile.', [
            'reservation' => $reservation->uuid,
            'payment' => $payment->id,
            'tenant' => $reservation->tenant_id,
        ]);
    }
}
