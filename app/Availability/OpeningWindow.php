<?php

declare(strict_types=1);

namespace App\Availability;

use Carbon\CarbonImmutable;

/**
 * One stretch of a day when a booking type is open, as instants in UTC.
 *
 * A venue can have two in a day — lunch and dinner — and one of them can run
 * past midnight (SPEC §6.1).
 */
final readonly class OpeningWindow
{
    public function __construct(
        public CarbonImmutable $opensAt,
        public CarbonImmutable $closesAt,
    ) {}

    public function minutes(): int
    {
        return (int) $this->opensAt->diffInMinutes($this->closesAt);
    }

    public function contains(CarbonImmutable $instant): bool
    {
        return $instant >= $this->opensAt && $instant < $this->closesAt;
    }

    /**
     * The whole of [$startsAt, $endsAt) fits inside this window.
     */
    public function covers(CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        return $startsAt >= $this->opensAt && $endsAt <= $this->closesAt;
    }

    public function isUsable(): bool
    {
        return $this->closesAt > $this->opensAt;
    }
}
