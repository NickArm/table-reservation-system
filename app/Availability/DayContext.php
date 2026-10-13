<?php

declare(strict_types=1);

namespace App\Availability;

use App\Models\Area;
use App\Models\BookingType;
use App\Models\Resource;
use App\Pricing\PriceBook;
use Illuminate\Support\Collection;

/**
 * Everything one date needs, loaded once.
 *
 * The engine answers dozens of questions about a single day — every time
 * against every spot — and all of them read from here rather than the
 * database.
 */
final readonly class DayContext
{
    /**
     * @param  Collection<int, Area>  $areas
     * @param  Collection<int, Collection<int, Resource>>  $resourcesByArea
     */
    public function __construct(
        public BookingType $bookingType,
        public BusinessDay $day,
        public string $timezone,
        public Collection $areas,
        public Collection $resourcesByArea,
        public ClosureSet $closures,
        public BusyIndex $busy,
        public PriceBook $prices,
    ) {}

    /**
     * @return Collection<int, Resource>
     */
    public function resourcesIn(Area $area): Collection
    {
        return $this->resourcesByArea->get($area->id) ?? new Collection;
    }

    public function totalResources(): int
    {
        return $this->resourcesByArea->sum(fn (Collection $resources): int => $resources->count());
    }

    public function bufferFor(Area $area): int
    {
        return $area->effectiveBufferMinutes($this->bookingType);
    }
}
