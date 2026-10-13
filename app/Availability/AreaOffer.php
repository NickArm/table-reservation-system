<?php

declare(strict_types=1);

namespace App\Availability;

use App\Models\Area;

/**
 * What one row or room has free at a given time — the shape the booking page
 * renders as an accordion section (SPEC §8).
 */
final readonly class AreaOffer
{
    /**
     * @param  array<int, ResourceOffer>  $offers
     */
    public function __construct(
        public Area $area,
        public array $offers,
    ) {}

    public function count(): int
    {
        return count($this->offers);
    }

    public function isEmpty(): bool
    {
        return $this->offers === [];
    }

    /**
     * The cheapest thing free here, which is what the badge on the section
     * header shows.
     */
    public function fromCents(): ?int
    {
        if ($this->offers === []) {
            return null;
        }

        return min(array_map(
            static fn (ResourceOffer $offer): int => $offer->priceCents(),
            $this->offers,
        ));
    }
}
