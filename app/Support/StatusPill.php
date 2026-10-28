<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ReservationStatus;

/**
 * Which pill a reservation status wears.
 *
 * Here rather than on the enum, because a CSS class is the stylesheet's
 * business and an enum that knows about stylesheets changes every time the
 * stylesheet does. Here rather than in a controller, because two screens
 * showing the same status in different colours is the kind of difference
 * nobody notices until somebody reads a cancelled booking as a live one.
 */
final class StatusPill
{
    /**
     * @return array<string, string>
     */
    public static function map(): array
    {
        return [
            ReservationStatus::Confirmed->value => 'pill--ok',
            ReservationStatus::CheckedIn->value => 'pill--ok',
            ReservationStatus::Completed->value => 'pill--muted',
            ReservationStatus::NoShow->value => 'pill--warn',
            ReservationStatus::PendingPayment->value => 'pill--warn',
            ReservationStatus::PartiallyRefunded->value => 'pill--warn',
            ReservationStatus::CancelledByVenue->value => 'pill--muted',
            ReservationStatus::Expired->value => 'pill--muted',
            ReservationStatus::Refunded->value => 'pill--muted',
        ];
    }
}
