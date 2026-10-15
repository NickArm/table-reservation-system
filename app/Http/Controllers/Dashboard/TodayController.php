<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\BookingClosure;
use App\Models\BookingType;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Venue;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The screen an owner lands on. Answers the one question they ask twenty
 * times a day: what bookings do I have? (SPEC §13.3)
 */
final class TodayController extends Controller
{
    /**
     * A venue's day runs from one morning to the next, not from midnight to
     * midnight: a table booked for 01:00 belongs to the night before, and
     * that is how everyone working it thinks about it.
     */
    private const DAY_STARTS_AT = 6;

    public function index(Request $request, TenantContext $context): View
    {
        $tenant = $context->getOrFail();
        $venue = Venue::query()->first();
        $timezone = (string) ($venue?->timezone ?? $tenant->timezone);

        $range = (string) $request->string('when', 'today');
        [$from, $to, $heading] = $this->range($range, $timezone);

        $reservations = Reservation::query()
            ->where('starts_at', '>=', $from)
            ->where('starts_at', '<', $to)
            ->whereNotIn('status', [
                ReservationStatus::CancelledByVenue->value,
                ReservationStatus::Expired->value,
            ])
            ->orderBy('starts_at')
            ->get();

        $bookingTypes = BookingType::query()->active()->get();

        return view('dashboard.today', [
            'tenant' => $tenant,
            'venue' => $venue,
            'timezone' => $timezone,
            'completeness' => $venue?->profileCompleteness(),
            'hasStructure' => $bookingTypes->isNotEmpty(),
            'bookingTypes' => $bookingTypes,
            'stoppedToday' => $this->stoppedToday($bookingTypes, $timezone),
            'reservations' => $reservations,
            'range' => $range,
            'heading' => $heading,
            'stats' => $this->stats($reservations, $timezone),
            'areaNames' => $this->areaNames($reservations),
            // "3rd visit", "2 no-shows" — the two notes that change how a
            // table is handled, next to the name (SPEC §28.3).
            'guests' => $this->guests($reservations),
        ]);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function range(string $range, string $timezone): array
    {
        $dayStart = CarbonImmutable::now($timezone)->setTime(self::DAY_STARTS_AT, 0);

        // Before six in the morning, the venue's "today" is still yesterday.
        if (CarbonImmutable::now($timezone)->hour < self::DAY_STARTS_AT) {
            $dayStart = $dayStart->subDay();
        }

        return match ($range) {
            'tomorrow' => [
                $dayStart->addDay()->utc(),
                $dayStart->addDays(2)->utc(),
                $dayStart->addDay()->format('l j F'),
            ],
            'week' => [
                $dayStart->utc(),
                $dayStart->addDays(7)->utc(),
                'The next seven days',
            ],
            'upcoming' => [
                $dayStart->utc(),
                $dayStart->addYear()->utc(),
                'Everything still to come',
            ],
            default => [
                $dayStart->utc(),
                $dayStart->addDay()->utc(),
                $dayStart->format('l j F'),
            ],
        };
    }

    /**
     * @param  Collection<int, Reservation>  $reservations
     * @return array{bookings: int, guests: int, prepaid: int, booked_today: int}
     */
    private function stats(Collection $reservations, string $timezone): array
    {
        $since = CarbonImmutable::now($timezone)->startOfDay()->utc();

        return [
            'bookings' => $reservations->count(),
            'guests' => (int) $reservations->sum('party_size'),
            'prepaid' => (int) $reservations
                ->filter(static fn (Reservation $reservation): bool => $reservation->payment_status === PaymentStatus::Paid)
                ->sum('amount_cents'),
            'booked_today' => $reservations
                ->filter(static fn (Reservation $reservation): bool => $reservation->created_at >= $since)
                ->count(),
        ];
    }

    /**
     * Which booking types the owner has stopped for today, so the screen can
     * offer to start them again.
     *
     * @param  Collection<int, BookingType>  $bookingTypes
     * @return array<int, true>
     */
    private function stoppedToday(Collection $bookingTypes, string $timezone): array
    {
        if ($bookingTypes->isEmpty()) {
            return [];
        }

        $today = CarbonImmutable::now($timezone)->toDateString();

        return BookingClosure::query()
            ->where('is_quick_stop', true)
            ->whereIn('scope_id', $bookingTypes->pluck('id'))
            ->whereDate('date_from', '<=', $today)
            ->whereDate('date_to', '>=', $today)
            ->pluck('scope_id')
            ->flip()
            ->map(static fn (): bool => true)
            ->all();
    }

    /**
     * The guests behind today's bookings, in one query.
     *
     * @param  Collection<int, Reservation>  $reservations
     * @return array<int, Guest>
     */
    private function guests(Collection $reservations): array
    {
        $ids = $reservations->pluck('guest_id')->filter()->unique();

        if ($ids->isEmpty()) {
            return [];
        }

        return Guest::query()->whereIn('id', $ids)->get()->keyBy('id')->all();
    }

    /**
     * Looked up once rather than per row: the list is read at a glance and
     * must not cost a query per line.
     *
     * @param  Collection<int, Reservation>  $reservations
     * @return array<int, string>
     */
    private function areaNames(Collection $reservations): array
    {
        $ids = $reservations->pluck('area_id')->filter()->unique();

        if ($ids->isEmpty()) {
            return [];
        }

        return Area::query()
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->all();
    }
}
