<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a guest picks a time (SPEC §6.2).
 */
enum BookingMode: string
{
    /** A table: arrive 19:00, stay 90 minutes. */
    case Slot = 'slot';

    /**
     * A sunbed: pick an arrival time between 10:00 and 12:30. How long the set
     * is then held is a separate question — see Occupancy.
     */
    case ArrivalWindow = 'arrival_window';

    /** Morning or afternoon. */
    case HalfDay = 'half_day';

    /** The whole day, no time to choose. */
    case FullDay = 'full_day';

    public function needsSlotLength(): bool
    {
        return $this === self::Slot;
    }

    public function needsArrivalWindow(): bool
    {
        return $this === self::ArrivalWindow;
    }

    public function letsGuestPickTime(): bool
    {
        return $this !== self::FullDay;
    }

    public function label(): string
    {
        return match ($this) {
            self::Slot => 'Time slots',
            self::ArrivalWindow => 'Arrival window',
            self::HalfDay => 'Half day',
            self::FullDay => 'Full day',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Slot => 'Guests book a start time and stay for a set length. Tables.',
            self::ArrivalWindow => 'Guests pick when they will arrive. Sunbeds.',
            self::HalfDay => 'Morning or afternoon.',
            self::FullDay => 'The whole day, with no time to choose.',
        };
    }
}
