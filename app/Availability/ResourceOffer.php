<?php

declare(strict_types=1);

namespace App\Availability;

use App\Models\Resource;
use App\Pricing\Price;

/**
 * One bookable thing, free at one time, for one price.
 */
final readonly class ResourceOffer
{
    public function __construct(
        public Resource $resource,
        public Slot $slot,
        public Price $price,
    ) {}

    public function label(): string
    {
        return $this->resource->label;
    }

    /** "2 Sunbeds · Umbrella · Side table", empty for a plain table. */
    public function contents(): string
    {
        return $this->resource->componentSummary();
    }

    public function priceCents(): int
    {
        return $this->price->totalCents;
    }
}
