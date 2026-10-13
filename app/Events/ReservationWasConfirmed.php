<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Reservation;

/**
 * A booking is now real: the spot is held and, if money was due online, it has
 * arrived.
 *
 * Raised from two places that must agree — the moment of booking when nothing
 * is paid online, and the settlement of a payment when something is. Anything
 * that should follow a confirmation listens here rather than being wired into
 * both (SPEC §31.4).
 *
 * It carries identifiers, never models: listeners run on the queue, where a
 * tenant-scoped model cannot be restored until a tenant is bound.
 */
final readonly class ReservationWasConfirmed
{
    public function __construct(
        public int $tenantId,
        public int $reservationId,
        public string $uuid,
    ) {}

    public static function from(Reservation $reservation): self
    {
        return new self(
            tenantId: (int) $reservation->tenant_id,
            reservationId: (int) $reservation->getKey(),
            uuid: (string) $reservation->uuid,
        );
    }
}
