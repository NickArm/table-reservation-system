<?php

declare(strict_types=1);

namespace App\Availability;

/**
 * How a date looks on a calendar, in one word (SPEC §15.2).
 *
 * A month of these colours the date picker in a single request, instead of
 * thirty lookups as the guest taps through.
 */
enum DayStatus: string
{
    case Available = 'available';
    case Limited = 'limited';
    case Full = 'full';
    case Closed = 'closed';

    /**
     * Under a quarter left reads as "limited" — enough to create urgency
     * honestly, not so little that it cries wolf on a quiet Tuesday.
     */
    public static function fromCounts(int $free, int $total): self
    {
        if ($total === 0) {
            return self::Closed;
        }

        if ($free === 0) {
            return self::Full;
        }

        return $free / $total <= 0.25 ? self::Limited : self::Available;
    }

    public function isBookable(): bool
    {
        return $this === self::Available || $this === self::Limited;
    }
}
