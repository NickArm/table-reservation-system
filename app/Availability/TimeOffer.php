<?php

declare(strict_types=1);

namespace App\Availability;

/**
 * One time on the booking page, with enough information to render it without
 * a second request: how many spots are free and what the cheapest one costs.
 *
 * Unlike i-host, a time that has nothing free is not offered at all — a
 * dropdown full of dead options is the first thing people complain about
 * (SPEC §8.1).
 */
final readonly class TimeOffer
{
    public function __construct(
        public Slot $slot,
        public int $available,
        public ?int $fromCents,
    ) {}

    public function label(): string
    {
        return $this->slot->label();
    }

    public function isAvailable(): bool
    {
        return $this->available > 0;
    }
}
