<?php

declare(strict_types=1);

namespace App\Availability;

use Carbon\CarbonImmutable;

/**
 * A venue's day, which is not the same thing as a calendar day.
 *
 * A bar open until 02:00 is still working Saturday night at 01:30 on Sunday
 * morning, and a booking taken then belongs to Saturday (SPEC §6.1).
 */
final readonly class BusinessDay
{
    /**
     * @param  array<int, OpeningWindow>  $windows
     */
    public function __construct(
        public string $date,
        public string $timezone,
        public array $windows,
    ) {}

    public static function closed(string $date, string $timezone): self
    {
        return new self($date, $timezone, []);
    }

    public function isClosed(): bool
    {
        return $this->windows === [];
    }

    public function opensAt(): ?CarbonImmutable
    {
        return $this->windows === [] ? null : $this->windows[0]->opensAt;
    }

    /**
     * When the venue shuts for the night — which is what a whole-day booking
     * is held until (SPEC §6.2.1).
     */
    public function closesAt(): ?CarbonImmutable
    {
        if ($this->windows === []) {
            return null;
        }

        return $this->windows[count($this->windows) - 1]->closesAt;
    }

    public function contains(CarbonImmutable $instant): bool
    {
        foreach ($this->windows as $window) {
            if ($window->contains($instant)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The window a given moment belongs to, if any.
     */
    public function windowFor(CarbonImmutable $instant): ?OpeningWindow
    {
        foreach ($this->windows as $window) {
            if ($window->contains($instant)) {
                return $window;
            }
        }

        return null;
    }

    /**
     * Midnight at the start of the day, for queries that bracket it.
     */
    public function startOfDay(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->date, $this->timezone)->startOfDay()->utc();
    }

    public function endOfDay(): CarbonImmutable
    {
        return $this->closesAt()
            ?? CarbonImmutable::parse($this->date, $this->timezone)->endOfDay()->utc();
    }
}
