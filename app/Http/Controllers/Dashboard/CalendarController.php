<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Enums\OnlineStatus;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\BookingClosure;
use App\Models\BookingType;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\Venue;
use App\Support\StatusPill;
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

        $bookingTypes = BookingType::query()->active()->orderBy('sort_order')->get();
        $capacities = $this->capacities($bookingTypes);
        $days = $this->days($start, $byDay, $timezone, $bookingTypes, $capacities);

        // Which day is open underneath. The current one when the week on
        // screen contains it, because that is the day being worked.
        $selectedKey = $this->selectedKey($request, $days, $timezone);
        $selected = collect($days)->firstWhere('key', $selectedKey) ?? $days[0];

        return view('dashboard.calendar', [
            'tenant' => $context->getOrFail(),
            'venue' => $venue,
            'timezone' => $timezone,
            'start' => $start,
            'days' => $days,
            'selected' => $selected,
            'arrivals' => $this->arrivals($selected['bookings'], $timezone),
            'closures' => $this->closures($start, $timezone),
            'weekTotals' => $this->weekTotals($days),
            'capacities' => $capacities,
            'statusSkins' => StatusPill::map(),
            'previous' => $start->subWeek()->toDateString(),
            'next' => $start->addWeek()->toDateString(),
            'thisWeek' => CarbonImmutable::now($timezone)->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
            'areaNames' => $this->areaNames($reservations),
            'capacity' => $this->capacity(),
            'bookingTypes' => $bookingTypes,
        ]);
    }

    /**
     * How many spots each booking type has, so a day can be read per type.
     *
     * A beach bar with twenty sunbeds and four tables fills them at different
     * speeds, and one bar across both says nothing useful about either.
     *
     * @param  Collection<int, BookingType>  $bookingTypes
     * @return array<int, int>
     */
    private function capacities(Collection $bookingTypes): array
    {
        if ($bookingTypes->isEmpty()) {
            return [];
        }

        return Resource::query()
            ->where('is_active', true)
            ->where('online_status', OnlineStatus::Open)
            ->whereIn('booking_type_id', $bookingTypes->modelKeys())
            ->toBase()
            ->selectRaw('booking_type_id, count(*) as total')
            ->groupBy('booking_type_id')
            ->pluck('total', 'booking_type_id')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();
    }

    /**
     * The day the panel underneath is showing.
     *
     * @param  array<int, array<string, mixed>>  $days
     */
    private function selectedKey(Request $request, array $days, string $timezone): string
    {
        $asked = (string) $request->string('day');
        $keys = array_column($days, 'key');

        if (in_array($asked, $keys, true)) {
            return $asked;
        }

        $today = CarbonImmutable::now($timezone)->toDateString();

        return in_array($today, $keys, true) ? $today : $keys[0];
    }

    /**
     * When people actually arrive, in half hours.
     *
     * The question underneath "is Saturday busy" is always "busy when" — a
     * day that is full at nine and empty at seven is a staffing decision, and
     * a bare total hides it.
     *
     * @param  Collection<int, Reservation>  $bookings
     * @return list<array{label: string, count: int, percent: int}>
     */
    private function arrivals(Collection $bookings, string $timezone): array
    {
        if ($bookings->isEmpty()) {
            return [];
        }

        $buckets = [];

        foreach ($bookings as $booking) {
            $local = $booking->startsAtLocal($timezone);
            $slot = $local->format('H').($local->minute < 30 ? ':00' : ':30');

            $buckets[$slot] = ($buckets[$slot] ?? 0) + 1;
        }

        ksort($buckets);
        $busiest = max($buckets);

        $out = [];

        foreach ($buckets as $label => $count) {
            $out[] = [
                'label' => $label,
                'count' => $count,
                'percent' => (int) round($count / $busiest * 100),
            ];
        }

        return $out;
    }

    /**
     * Closures that touch this week, so a quiet day reads as closed rather
     * than as nobody booking.
     *
     * @return Collection<int, BookingClosure>
     */
    private function closures(CarbonImmutable $start, string $timezone): Collection
    {
        return BookingClosure::query()
            ->where('date_from', '<=', $start->addDays(6)->toDateString())
            ->where('date_to', '>=', $start->toDateString())
            ->orderBy('date_from')
            ->get();
    }

    /**
     * @param  array<int, array<string, mixed>>  $days
     * @return array{bookings: int, guests: int, money: int, busiest: ?string}
     */
    private function weekTotals(array $days): array
    {
        $busiest = null;
        $most = 0;

        foreach ($days as $day) {
            $count = $day['bookings']->count();

            if ($count > $most) {
                $most = $count;
                $busiest = $day['date']->format('l');
            }
        }

        return [
            'bookings' => array_sum(array_map(static fn (array $day): int => $day['bookings']->count(), $days)),
            'guests' => array_sum(array_column($days, 'guests')),
            'money' => array_sum(array_column($days, 'money')),
            'busiest' => $busiest,
        ];
    }

    /**
     * One entry per day of the week, whether or not anything is booked. An
     * empty Tuesday is information.
     *
     * @param  \Illuminate\Support\Collection<string, Collection<int, Reservation>>  $byDay
     * @param  Collection<int, BookingType>  $bookingTypes
     * @param  array<int, int>  $capacities
     * @return array<int, array<string, mixed>>
     */
    private function days(
        CarbonImmutable $start,
        $byDay,
        string $timezone,
        Collection $bookingTypes,
        array $capacities,
    ): array {
        $today = CarbonImmutable::now($timezone)->toDateString();
        $days = [];

        for ($i = 0; $i < 7; $i++) {
            $date = $start->addDays($i);
            $key = $date->toDateString();

            /** @var Collection<int, Reservation> $bookings */
            $bookings = $byDay[$key] ?? new Collection;

            $perType = [];

            foreach ($bookingTypes as $bookingType) {
                $count = $bookings
                    ->where('booking_type_id', $bookingType->getKey())
                    ->count();
                $capacity = $capacities[$bookingType->getKey()] ?? 0;

                $perType[] = [
                    'name' => $bookingType->name,
                    'count' => $count,
                    'capacity' => $capacity,
                    'percent' => $capacity > 0 ? min(100, (int) round($count / $capacity * 100)) : 0,
                ];
            }

            $days[] = [
                'date' => $date,
                'key' => $key,
                'isToday' => $key === $today,
                'isPast' => $key < $today,
                'bookings' => $bookings,
                'guests' => (int) $bookings->sum('party_size'),
                'money' => (int) $bookings->sum('amount_cents'),
                'types' => $perType,
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
