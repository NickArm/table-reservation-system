<?php

declare(strict_types=1);

namespace App\Availability;

use App\Enums\BookingMode;
use App\Enums\Occupancy;
use App\Models\Area;
use App\Models\BookingType;
use Carbon\CarbonImmutable;

/**
 * Turns a business day into the times a guest can pick (SPEC §6.2).
 *
 * Slots are never stored — a stored slot is a slot that disagrees with the
 * settings the moment somebody edits them. Only the exceptions live in the
 * database.
 *
 * Everything walks forward in UTC. That is not pedantry: adding thirty
 * minutes to a wall clock across a daylight-saving change produces times that
 * either never happen or happen twice, while adding thirty minutes to an
 * instant is always exactly thirty minutes (SPEC §3.5).
 */
final class SlotGenerator
{
    /**
     * @return array<int, Slot>
     */
    public function generate(BookingType $bookingType, BusinessDay $day, ?Area $area = null): array
    {
        if ($day->isClosed()) {
            return [];
        }

        return match ($bookingType->booking_mode) {
            BookingMode::Slot => $this->slots($bookingType, $day, $area),
            BookingMode::ArrivalWindow => $this->arrivals($bookingType, $day, $area),
            BookingMode::HalfDay => $this->halves($day),
            BookingMode::FullDay => $this->fullDay($day),
        };
    }

    /**
     * How long a booking starting at this moment holds its spot.
     *
     * The area decides when it has an opinion, the booking type otherwise
     * (SPEC §6.2.1).
     */
    public function endsAt(
        BookingType $bookingType,
        ?Area $area,
        CarbonImmutable $startsAt,
        BusinessDay $day,
    ): CarbonImmutable {
        $occupancy = $area?->effectiveOccupancy($bookingType) ?? $bookingType->occupancy;

        if ($occupancy === Occupancy::WholeDay) {
            return $day->closesAt() ?? $startsAt->addDay()->startOfDay();
        }

        $minutes = $area?->effectiveMaxStayMinutes($bookingType)
            ?? $bookingType->maxStayMinutes()
            ?? $bookingType->slot_duration_minutes;

        return $startsAt->addMinutes(max(1, (int) $minutes));
    }

    /**
     * Tables: a start time every interval, for as long as a full sitting still
     * fits before closing.
     *
     * @return array<int, Slot>
     */
    private function slots(BookingType $bookingType, BusinessDay $day, ?Area $area): array
    {
        $interval = max(5, (int) $bookingType->slot_interval_minutes);
        $occupancy = $area?->effectiveOccupancy($bookingType) ?? $bookingType->occupancy;
        $slots = [];

        foreach ($day->windows as $window) {
            $cursor = $window->opensAt;

            while ($cursor < $window->closesAt) {
                $endsAt = $this->endsAt($bookingType, $area, $cursor, $day);

                // A sitting that would run past closing is not on offer.
                // Whole-day occupancy ends at closing by definition, so this
                // only ever trims fixed-length bookings.
                if ($occupancy === Occupancy::FixedDuration && $endsAt > $window->closesAt) {
                    break;
                }

                $slots[] = new Slot($cursor, $endsAt, $day->timezone);

                $cursor = $cursor->addMinutes($interval);
            }
        }

        return $slots;
    }

    /**
     * Sunbeds: pick when you will turn up. How long you keep the set is a
     * separate question, answered by the occupancy.
     *
     * @return array<int, Slot>
     */
    private function arrivals(BookingType $bookingType, BusinessDay $day, ?Area $area): array
    {
        $opensAt = $day->opensAt();
        $closesAt = $day->closesAt();

        if ($opensAt === null || $closesAt === null) {
            return [];
        }

        $interval = max(5, (int) $bookingType->slot_interval_minutes);

        $windowStart = $this->boundary($bookingType->arrival_window_start, $day) ?? $opensAt;
        $windowEnd = $this->boundary($bookingType->arrival_window_end, $day) ?? $closesAt;

        $first = $windowStart->max($opensAt);
        $last = $windowEnd->min($closesAt);

        $slots = [];
        $cursor = $first;

        while ($cursor <= $last) {
            $slots[] = new Slot($cursor, $this->endsAt($bookingType, $area, $cursor, $day), $day->timezone);

            $cursor = $cursor->addMinutes($interval);
        }

        return $slots;
    }

    /**
     * Morning or afternoon, split down the middle of the open hours.
     *
     * @return array<int, Slot>
     */
    private function halves(BusinessDay $day): array
    {
        $opensAt = $day->opensAt();
        $closesAt = $day->closesAt();

        if ($opensAt === null || $closesAt === null) {
            return [];
        }

        $midpoint = $opensAt->addMinutes((int) ($opensAt->diffInMinutes($closesAt) / 2));

        return [
            new Slot($opensAt, $midpoint, $day->timezone, 'Morning'),
            new Slot($midpoint, $closesAt, $day->timezone, 'Afternoon'),
        ];
    }

    /**
     * @return array<int, Slot>
     */
    private function fullDay(BusinessDay $day): array
    {
        $opensAt = $day->opensAt();
        $closesAt = $day->closesAt();

        if ($opensAt === null || $closesAt === null) {
            return [];
        }

        return [new Slot($opensAt, $closesAt, $day->timezone, 'All day')];
    }

    private function boundary(?string $time, BusinessDay $day): ?CarbonImmutable
    {
        return blank($time)
            ? null
            : LocalTime::normalized($day->date, $time, $day->timezone);
    }
}
