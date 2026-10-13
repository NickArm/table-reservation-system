<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Reservation;

/**
 * The venue has cancelled a booking, and may have sent money back with it.
 */
final readonly class ReservationWasCancelled
{
    public function __construct(
        public int $tenantId,
        public int $reservationId,
        public int $refundedCents = 0,
        public ?string $reason = null,
    ) {}

    public static function from(Reservation $reservation, int $refundedCents = 0, ?string $reason = null): self
    {
        return new self(
            tenantId: (int) $reservation->tenant_id,
            reservationId: (int) $reservation->getKey(),
            refundedCents: $refundedCents,
            reason: $reason,
        );
    }
}
