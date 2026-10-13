<?php

declare(strict_types=1);

namespace App\Availability;

use Carbon\CarbonImmutable;

/**
 * One offer on the booking page: arrive at this time, keep the spot until
 * that one.
 *
 * Instants are UTC; the label is the venue's local clock, which is the only
 * time a guest ever sees (SPEC §3.5).
 */
final readonly class Slot
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public string $timezone,
        public ?string $name = null,
    ) {}

    public function label(): string
    {
        return $this->startsAt->setTimezone($this->timezone)->format('H:i');
    }

    public function endLabel(): string
    {
        return $this->endsAt->setTimezone($this->timezone)->format('H:i');
    }

    public function minutes(): int
    {
        return (int) $this->startsAt->diffInMinutes($this->endsAt);
    }

    /**
     * "10:30 → 14:30 · 4 hours", so nobody thinks they have the sunbed all
     * day when they have it for four hours (SPEC §6.2.1).
     */
    public function describe(): string
    {
        if ($this->name !== null) {
            return $this->name.' · '.$this->label().' → '.$this->endLabel();
        }

        $hours = $this->minutes() / 60;
        $duration = fmod($hours, 1.0) === 0.0
            ? sprintf('%d %s', (int) $hours, (int) $hours === 1 ? 'hour' : 'hours')
            : sprintf('%d min', $this->minutes());

        return $this->label().' → '.$this->endLabel().' · '.$duration;
    }

    public function key(): string
    {
        return $this->startsAt->toIso8601String();
    }

    public function overlaps(CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        return $this->startsAt < $endsAt && $this->endsAt > $startsAt;
    }

    /**
     * The same arrival time, held for a different length — how an area's
     * occupancy override is applied to a booking type's slots.
     */
    public function endingAt(CarbonImmutable $endsAt): self
    {
        return new self($this->startsAt, $endsAt, $this->timezone, $this->name);
    }
}
