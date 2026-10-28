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
use App\Models\Resource;
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
            'seats' => $this->seats(),
            // What is going wrong right now, in the order it will cost
            // something: somebody standing at the door beats a thin profile.
            'attention' => $this->attention($reservations, $timezone),
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
     * @return array{bookings: int, guests: int, prepaid: int, booked_today: int, seated: int, soon: int}
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
            // On the floor now, and about to walk in: the two numbers that
            // decide whether the owner can leave the pass.
            'seated' => $reservations
                ->filter(static fn (Reservation $reservation): bool => $reservation->status === ReservationStatus::CheckedIn)
                ->count(),
            'soon' => $reservations
                ->filter(static function (Reservation $reservation) use ($timezone): bool {
                    $now = CarbonImmutable::now($timezone)->utc();

                    return $reservation->status === ReservationStatus::Confirmed
                        && $reservation->starts_at->gte($now)
                        && $reservation->starts_at->lte($now->addMinutes(30));
                })
                ->count(),
        ];
    }

    /**
     * How many people the place seats when everything is open.
     *
     * The denominator behind "28 of 36". A bare "28 covers" tells an owner
     * nothing — whether that is a good day depends entirely on the room.
     */
    private function seats(): int
    {
        return (int) Resource::query()->where('is_active', true)->sum('capacity_max');
    }

    /**
     * The three things that cost money if nobody looks at them (SPEC §13.3).
     *
     * Only ever about today, whatever range the list is showing: a guest
     * standing at the door is not a next-week problem, and a screen that
     * raises next week's alarms while somebody waits is noise.
     *
     * @param  Collection<int, Reservation>  $reservations
     * @return list<array{kind: string, title: string, body: string, href: string, action: string}>
     */
    private function attention(Collection $reservations, string $timezone): array
    {
        $now = CarbonImmutable::now($timezone)->utc();
        $items = [];

        /*
         * Fifteen minutes, not five. A table is held for a while on purpose
         * and a guest who is four minutes late is simply a guest.
         */
        $late = $reservations->filter(
            static fn (Reservation $reservation): bool => $reservation->status === ReservationStatus::Confirmed
                && $reservation->starts_at->lt($now->subMinutes(15))
                && $reservation->starts_at->gt($now->subHours(3))
        );

        foreach ($late->take(3) as $reservation) {
            $minutes = (int) $reservation->starts_at->diffInMinutes($now);

            $items[] = [
                'kind' => 'late',
                'title' => $reservation->customer_name.' is '.$minutes.' minutes late',
                'body' => filled($reservation->customer_phone)
                    ? 'Ring them on '.$reservation->customer_phone.', or give the table up.'
                    : 'No phone number on this booking.',
                'href' => route('dashboard.reservations.show', $reservation),
                'action' => 'Open',
            ];
        }

        $unassigned = $reservations->filter(
            static fn (Reservation $reservation): bool => $reservation->resource_id === null
                && $reservation->status->blocksAvailability()
                && $reservation->starts_at->gte($now)
        );

        foreach ($unassigned->take(2) as $reservation) {
            $items[] = [
                'kind' => 'table',
                'title' => $reservation->customer_name.' has no table',
                'body' => $reservation->party_size.' at '
                    .$reservation->startsAtLocal($timezone)->format('H:i')
                    .'. Give them one before they arrive.',
                'href' => route('dashboard.reservations.show', $reservation),
                'action' => 'Seat',
            ];
        }

        return $items;
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
