<?php

declare(strict_types=1);

namespace App\Actions\Reservations;

use App\Actions\Payments\RefundPayment;
use App\Availability\AvailabilityEngine;
use App\Enums\ReservationStatus;
use App\Events\ReservationWasCancelled;
use App\Models\Payment;
use App\Models\Reservation;
use App\Payments\RefundResult;
use Illuminate\Support\Facades\DB;

/**
 * The venue cancels a booking, after a phone call (SPEC §8.3).
 *
 * Only the venue can do this — there is no self-service cancellation in v1,
 * on purpose. Real cancellations have shades a rule cannot catch: the family
 * whose flight was cancelled and the one that simply changed its mind are not
 * the same conversation, and a human should decide what each one is owed.
 *
 * Cancelling and refunding are separate choices. Cancelling without a refund
 * is perfectly valid, and so is refunding without cancelling.
 */
final readonly class CancelReservation
{
    public function __construct(
        private RefundPayment $refunds,
        private AvailabilityEngine $engine,
    ) {}

    public function handle(
        Reservation $reservation,
        string $reason,
        int $refundCents = 0,
        ?int $actorId = null,
    ): ?RefundResult {
        $refund = null;

        DB::transaction(function () use ($reservation, $reason, $refundCents, $actorId, &$refund): void {
            $reservation->forceFill([
                'status' => ReservationStatus::CancelledByVenue,
                'cancelled_at' => now(),
                'cancelled_by' => $actorId,
                'cancellation_reason' => $reason,
            ])->save();

            if ($refundCents <= 0) {
                return;
            }

            $payment = Payment::query()
                ->where('reservation_id', $reservation->id)
                ->latest('id')
                ->first();

            if ($payment === null || ! $payment->isPaid()) {
                return;
            }

            $refund = $this->refunds->handle($payment, $refundCents, $reason);
        });

        // The spot is free again from this moment.
        $this->engine->forget();

        // Told after the transaction, and with the amount the gateway actually
        // accepted rather than the amount asked for — a guest should never
        // read "refunded" about money that did not move (SPEC §8.3).
        event(ReservationWasCancelled::from(
            $reservation,
            $refund?->accepted === true ? $refund->amountCents : 0,
            $reason,
        ));

        return $refund;
    }
}
