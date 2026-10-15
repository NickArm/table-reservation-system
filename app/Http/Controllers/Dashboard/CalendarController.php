<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Enums\OnlineStatus;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\BookingType;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\Venue;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * The shape of the week (SPEC §13.1).
 *
 * The Today screen answers "what is happening now" and the list answers "where
 * is that booking". Neither answers the question this screen exists for, which
 * an owner asks on a Monday: **which days are heavy and which are empty?** —
 * because that is what decides whether to call in another pair of hands, and
 * whether to open the back row at all.
 *
 * So it is seven columns and a fill level, not a grid of hours. A beach where
 * everything is booked for the whole day has nothing to say in an hour grid,
 * and dragging a booking from Tuesday to Thursday is a Phase 2 want rather
 * than a daily need (§3.3).
 */
final class CalendarController extends Controller
{
    public function __invoke(Request $request, TenantContext $context): View
    {
        $venue = Venue::query()->first();
        $timezone = (string) ($venue?->timezone ?? $context->getOrFail()->timezone);

        $anchor = $this->anchor($request, $timezone);
        $start = $anchor->startOfWeek(CarbonImmutable::MONDAY);
        $end = $start->addDays(7);

        $reservations = Reservation::query()
            ->whereIn('status', ReservationStatus::blocking())
            ->where('starts_at', '>=', $start->utc())
            ->where('starts_at', '<', $end->utc())
            ->orderBy('starts_at')
            ->get();

        $byDay = $reservations->groupBy(
            fn (Reservation $reservation): string => $reservation->startsAtLocal($timezone)->toDateString()
        );

        return view('dashboard.calendar', [
            'tenant' => $context->getOrFail(),
            'venue' => $venue,
            'timezone' => $timezone,
            'start' => $start,
            'days' => $this->days($start, $byDay, $timezone),
            'previous' => $start->subWeek()->toDateString(),
            'next' => $start->addWeek()->toDateString(),
            'thisWeek' => CarbonImmutable::now($timezone)->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
            'areaNames' => $this->areaNames($reservations),
            'capacity' => $this->capacity(),
            'bookingTypes' => BookingType::query()->active()->orderBy('sort_order')->get(),
        ]);
    }

    /**
     * One entry per day of the week, whether or not anything is booked. An
     * empty Tuesday is information.
     *
     * @param  \Illuminate\Support\Collection<string, Collection<int, Reservation>>  $byDay
     * @return array<int, array<string, mixed>>
     */
    private function days(CarbonImmutable $start, $byDay, string $timezone): array
    {
        $today = CarbonImmutable::now($timezone)->toDateString();
        $days = [];

        for ($i = 0; $i < 7; $i++) {
            $date = $start->addDays($i);
            $key = $date->toDateString();

            /** @var Collection<int, Reservation> $bookings */
            $bookings = $byDay[$key] ?? new Collection;

            $days[] = [
                'date' => $date,
                'key' => $key,
                'isToday' => $key === $today,
                'isPast' => $key < $today,
                'bookings' => $bookings,
                'guests' => (int) $bookings->sum('party_size'),
                'money' => (int) $bookings->sum('amount_cents'),
            ];
        }

        return $days;
    }

    /**
     * How many spots exist, so a day's count can be read as a fill level
     * rather than a bare number. Bookable ones only: a table the owner has
     * switched off is not capacity they have.
     */
    private function capacity(): int
    {
        return Resource::query()
            ->where('is_active', true)
            ->where('online_status', OnlineStatus::Open)
            ->count();
    }

    /**
     * @param  Collection<int, Reservation>  $reservations
     * @return array<int, string>
     */
    private function areaNames(Collection $reservations): array
    {
        $ids = $reservations->pluck('area_id')->filter()->unique();

        if ($ids->isEmpty()) {
            return [];
        }

        return Area::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    private function anchor(Request $request, string $timezone): CarbonImmutable
    {
        $raw = (string) $request->string('week');

        if ($raw === '') {
            return CarbonImmutable::now($timezone);
        }

        try {
            return CarbonImmutable::parse($raw, $timezone);
        } catch (Throwable) {
            return CarbonImmutable::now($timezone);
        }
    }
}
