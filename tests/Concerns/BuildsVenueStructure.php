<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Availability\AvailabilityEngine;
use App\Availability\OpeningHoursResolver;
use App\Availability\SlotGenerator;
use App\Enums\BookingMode;
use App\Enums\Occupancy;
use App\Models\Area;
use App\Models\BookingType;
use App\Models\OpeningHour;
use App\Models\Resource;
use Illuminate\Database\Eloquent\Collection;

/**
 * Builds the venue the availability tests argue about.
 *
 * Deliberately explicit rather than clever: a test that fails should point at
 * the rule it broke, not at a fixture nobody can read.
 */
trait BuildsVenueStructure
{
    /**
     * A fresh engine every time, because the real one caches a day for the
     * length of a request and a test arranges data between questions.
     */
    protected function engine(): AvailabilityEngine
    {
        return new AvailabilityEngine(new OpeningHoursResolver, new SlotGenerator);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function bookingTypeOpen(
        string $opensAt,
        string $closesAt,
        array $attributes = [],
        bool $closesNextDay = false,
    ): BookingType {
        $bookingType = BookingType::factory()->create($attributes);

        $this->setHours($bookingType, $opensAt, $closesAt, $closesNextDay);

        return $bookingType;
    }

    /**
     * Sunbeds: arrive between 10:00 and 13:00, keep the set until closing.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function sunbedType(array $attributes = []): BookingType
    {
        return $this->bookingTypeOpen('09:00', '19:00', [
            'booking_mode' => BookingMode::ArrivalWindow,
            'occupancy' => Occupancy::WholeDay,
            'max_stay_minutes' => null,
            'arrival_window_start' => '10:00',
            'arrival_window_end' => '13:00',
            'slot_interval_minutes' => 30,
            'turnover_buffer_minutes' => 0,
            'min_lead_time_minutes' => 0,
            ...$attributes,
        ]);
    }

    /**
     * Tables: a 90 minute sitting every half hour, dinner only.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function tableType(array $attributes = []): BookingType
    {
        return $this->bookingTypeOpen('19:00', '23:00', [
            'booking_mode' => BookingMode::Slot,
            'occupancy' => Occupancy::FixedDuration,
            'slot_duration_minutes' => 90,
            'max_stay_minutes' => 90,
            'slot_interval_minutes' => 30,
            'turnover_buffer_minutes' => 0,
            'min_lead_time_minutes' => 0,
            ...$attributes,
        ]);
    }

    protected function setHours(
        BookingType $bookingType,
        string $opensAt,
        string $closesAt,
        bool $closesNextDay = false,
    ): void {
        OpeningHour::query()
            ->where('scope', OpeningHour::SCOPE_BOOKING_TYPE)
            ->where('scope_id', $bookingType->id)
            ->delete();

        foreach (range(0, 6) as $day) {
            OpeningHour::create([
                'scope' => OpeningHour::SCOPE_BOOKING_TYPE,
                'scope_id' => $bookingType->id,
                'day_of_week' => $day,
                'opens_at' => $opensAt,
                'closes_at' => $closesAt,
                'closes_next_day' => $closesNextDay,
            ]);
        }
    }

    /**
     * A second stretch on the same day — lunch as well as dinner.
     */
    protected function addHours(BookingType $bookingType, string $opensAt, string $closesAt): void
    {
        foreach (range(0, 6) as $day) {
            OpeningHour::create([
                'scope' => OpeningHour::SCOPE_BOOKING_TYPE,
                'scope_id' => $bookingType->id,
                'day_of_week' => $day,
                'opens_at' => $opensAt,
                'closes_at' => $closesAt,
            ]);
        }
    }

    protected function closeOn(BookingType $bookingType, int $dayOfWeek): void
    {
        OpeningHour::query()
            ->where('scope', OpeningHour::SCOPE_BOOKING_TYPE)
            ->where('scope_id', $bookingType->id)
            ->where('day_of_week', $dayOfWeek)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function areaIn(BookingType $bookingType, string $name, array $attributes = []): Area
    {
        $position = Area::query()->where('booking_type_id', $bookingType->id)->count() + 1;

        return Area::factory()->create([
            'booking_type_id' => $bookingType->id,
            'name' => $name,
            'sort_order' => $position,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return Collection<int, Resource>
     */
    protected function resourcesIn(Area $area, string $prefix, int $count, array $attributes = []): Collection
    {
        $resources = new Collection;

        foreach (range(1, $count) as $number) {
            $resources->push(Resource::factory()->create([
                'booking_type_id' => $area->booking_type_id,
                'area_id' => $area->id,
                'label' => $prefix.$number,
                'sort_order' => $number,
                ...$attributes,
            ]));
        }

        return $resources;
    }
}
