<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\BookingType;
use App\Models\Payment;
use App\Models\Reservation;
use App\Payments\GatewayManager;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lets go of bookings nobody paid for (SPEC §24.1).
 *
 * A reservation waiting for payment holds its spot, which is right while
 * somebody is typing a card number and wrong fifteen minutes later. This
 * closes the gateway's checkout as well as releasing the spot, so a guest who
 * comes back to a stale tab is told the session expired rather than paying
 * for something that is gone.
 */
final readonly class ExpireUnpaidReservations
{
    public function __construct(
        private TenantContext $context,
        private GatewayManager $gateways,
    ) {}

    public function handle(): int
    {
        return $this->context->withoutScoping(function (): int {
            $expired = 0;

            $stale = Reservation::query()
                ->where('status', ReservationStatus::PendingPayment->value)
                ->where('created_at', '<', now()->subMinutes($this->grace()))
                ->get();

            foreach ($stale as $reservation) {
                $expired += $this->expire($reservation) ? 1 : 0;
            }

            return $expired;
        });
    }

    private function expire(Reservation $reservation): bool
    {
        $payment = Payment::query()
            ->where('reservation_id', $reservation->id)
            ->latest('id')
            ->first();

        // Paid after all, and the webhook simply has not landed: leave it for
        // reconciliation rather than cancelling a booking somebody paid for.
        if ($payment !== null && $payment->isPaid()) {
            return false;
        }

        if ($payment !== null) {
            $this->closeCheckout($payment, $reservation);

            $payment->forceFill(['status' => PaymentStatus::Failed])->save();
        }

        $reservation->forceFill([
            'status' => ReservationStatus::Expired,
            'payment_status' => PaymentStatus::Failed,
        ])->save();

        return true;
    }

    private function closeCheckout(Payment $payment, Reservation $reservation): void
    {
        try {
            $this->context->asTenant($reservation->tenant()->first(), function () use ($payment): void {
                [$gateway, $setting] = $this->gateways->resolve($payment->gateway);

                $gateway->cancelCheckout($setting, (string) $payment->session_id);
            });
        } catch (Throwable $exception) {
            /*
             * A gateway that will not close a session is not a reason to keep
             * a sunbed off the market. The availability re-check before
             * confirming covers the rest (SPEC §24.1).
             *
             * Logged rather than ignored: a session left open at the provider
             * is a guest who can still be charged for a spot that has gone
             * back on sale, and the only way anybody finds out is this line.
             */
            Log::warning('Could not close a checkout session at the gateway.', [
                'payment' => $payment->getKey(),
                'reservation' => $reservation->code,
                'gateway' => $payment->gateway->value,
                'error' => $exception->getMessage(),
                'at' => $exception->getFile().':'.$exception->getLine(),
            ]);
        }
    }

    /**
     * The hold's own length plus a few minutes, so a guest who is genuinely
     * mid-payment is never cut off by a rounding error.
     */
    private function grace(): int
    {
        $longest = (int) BookingType::query()->acrossTenants()->max('hold_duration_minutes');

        return max(15, $longest) + 5;
    }
}
