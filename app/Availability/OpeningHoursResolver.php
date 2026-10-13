<?php

declare(strict_types=1);

namespace App\Availability;

use App\Models\BookingType;
use App\Models\OpeningHour;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Reads the opening hours of a booking type into business days (SPEC §6.1).
 *
 * Rows are cached per booking type for the length of the request, because a
 * calendar screen asks for thirty days in a row and the hours do not change
 * between them.
 */
final class OpeningHoursResolver
{
    /** @var array<int, Collection<int, OpeningHour>> */
    private array $cache = [];

    public function resolve(BookingType $bookingType, string $date, string $timezone): BusinessDay
    {
        $dayOfWeek = CarbonImmutable::parse($date, $timezone)->dayOfWeek;

        $windows = $this->rowsFor($bookingType)
            ->where('day_of_week', $dayOfWeek)
            ->map(fn (OpeningHour $hour): ?OpeningWindow => $this->toWindow($hour, $date, $timezone))
            ->filter()
            ->sortBy(fn (OpeningWindow $window): string => $window->opensAt->toIso8601String())
            ->values()
            ->all();

        return new BusinessDay($date, $timezone, $windows);
    }

    private function toWindow(OpeningHour $hour, string $date, string $timezone): ?OpeningWindow
    {
        $opensAt = LocalTime::normalized($date, (string) $hour->opens_at, $timezone);

        // A closing time at or before the opening time can only mean the next
        // morning, whether or not anyone ticked the box.
        $runsPastMidnight = $hour->closes_next_day
            || substr((string) $hour->closes_at, 0, 5) <= substr((string) $hour->opens_at, 0, 5);

        $closingDate = $runsPastMidnight
            ? CarbonImmutable::parse($date)->addDay()->toDateString()
            : $date;

        $closesAt = LocalTime::normalized($closingDate, (string) $hour->closes_at, $timezone);

        $window = new OpeningWindow($opensAt, $closesAt);

        return $window->isUsable() ? $window : null;
    }

    /**
     * @return Collection<int, OpeningHour>
     */
    private function rowsFor(BookingType $bookingType): Collection
    {
        return $this->cache[$bookingType->id] ??= OpeningHour::query()
            ->forBookingType($bookingType)
            ->get();
    }
}
