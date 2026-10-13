<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How long a booking holds its resource (SPEC §6.2.1).
 *
 * Set on the booking type and overridable per area, so the front row can sell
 * for the whole day while the second row turns over twice.
 */
enum Occupancy: string
{
    /** Held until closing. One booking a day. */
    case WholeDay = 'whole_day';

    /** Held for max_stay_minutes, then free again. */
    case FixedDuration = 'fixed_duration';

    public function needsMaxStay(): bool
    {
        return $this === self::FixedDuration;
    }

    public function label(): string
    {
        return match ($this) {
            self::WholeDay => 'Whole day',
            self::FixedDuration => 'Fixed length',
        };
    }
}
