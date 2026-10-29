<?php

declare(strict_types=1);

namespace App\Reports;

use App\Enums\Channel;
use App\Enums\GatewayMode;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\BookingClosure;
use App\Models\BookingType;
use App\Models\OpeningHour;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Resource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What a season came to (SPEC §36).
 *
 * This is the screen an owner opens in October, not in July — the question is
 * "how did it go", and the answers worth having are few: what came in, how
 * full the place was, which days carried it, who did not turn up, and where
 * the bookings came from. The last one is what decides a renewal.
 *
 * Every figure here is a counting query over an indexed range, never a loop
 * over reservations. A beach bar with twenty sunbeds books forty times a day;
 * a season is fifteen thousand rows and nobody waits for that in PHP.
 *
 * Money is read from payments rather than from reservations. An amount on a
 * reservation is what was asked for; a payment is what arrived — and after a
 * refund those two stop agreeing, which is exactly when somebody looks.
 */
final readonly class SeasonFigures
{
    public function __construct(
        private CarbonImmutable $from,
        private CarbonImmutable $to,
        private string $timezone,
    ) {}

    /**
     * Bookings that happened, in the window.
     *
     * Cancelled ones are left out of every count except the cancellation one:
     * an owner asking how full August was does not mean "including the forty
     * that cancelled".
     *
     * @return \Illuminate\Database\Eloquent\Builder<Reservation>
     */
    private function held(): \Illuminate\Database\Eloquent\Builder
    {
        return Reservation::query()
            ->whereIn('status', ReservationStatus::blocking())
            ->where('starts_at', '>=', $this->from->utc())
            ->where('starts_at', '<', $this->to->utc());
    }

    /**
     * @return array{bookings: int, guests: int, noShows: int, cancelled: int}
     */
    public function totals(): array
    {
        $held = $this->held()
            ->toBase()
            ->selectRaw('count(*) as bookings, coalesce(sum(party_size), 0) as guests')
            ->first();

        return [
            'bookings' => (int) ($held->bookings ?? 0),
            'guests' => (int) ($held->guests ?? 0),
            'noShows' => $this->countWithStatus([ReservationStatus::NoShow]),
            'cancelled' => $this->countWithStatus([
                ReservationStatus::CancelledByVenue,
                ReservationStatus::Refunded,
                ReservationStatus::Expired,
            ]),
        ];
    }

    /**
     * @param  array<int, ReservationStatus>  $statuses
     */
    private function countWithStatus(array $statuses): int
    {
        return Reservation::query()
            ->whereIn('status', array_map(static fn (ReservationStatus $s): string => $s->value, $statuses))
            ->where('starts_at', '>=', $this->from->utc())
            ->where('starts_at', '<', $this->to->utc())
            ->count();
    }

    /**
     * What actually arrived, net of refunds.
     *
     * Test-mode payments are excluded (§24.2): a venue that spent an evening
     * trying the checkout should not find its season inflated by pretend
     * money, and an owner cannot tell the difference by looking.
     *
     * @return array{taken: int, refunded: int, net: int, atVenue: int, paidBookings: int}
     */
    public function money(): array
    {
        $row = Payment::query()
            ->whereIn('status', [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value, PaymentStatus::Refunded->value])
            ->where('mode', GatewayMode::Live->value)
            ->whereIn('reservation_id', $this->held()->toBase()->select('id'))
            ->toBase()
            ->selectRaw('coalesce(sum(amount_cents), 0) as taken')
            ->selectRaw('coalesce(sum(refunded_amount_cents), 0) as refunded')
            ->selectRaw('count(*) as paid_bookings')
            ->first();

        $taken = (int) ($row->taken ?? 0);
        $refunded = (int) ($row->refunded ?? 0);

        return [
            'taken' => $taken,
            'refunded' => $refunded,
            'net' => $taken - $refunded,
            // Money the guest owes at the table. Not revenue yet, and saying
            // so is the difference between a report and a wish.
            'atVenue' => (int) $this->held()
                ->where('payment_status', PaymentStatus::PayOnArrival->value)
                ->sum('amount_cents'),
            'paidBookings' => (int) ($row->paid_bookings ?? 0),
        ];
    }

    /**
     * What the people who never turned up had been going to spend.
     */
    public function noShowCostCents(): int
    {
        return (int) Reservation::query()
            ->where('status', ReservationStatus::NoShow->value)
            ->where('starts_at', '>=', $this->from->utc())
            ->where('starts_at', '<', $this->to->utc())
            ->sum('amount_cents');
    }

    /**
     * Where the bookings came from.
     *
     * The number that decides a renewal: "the widget on my own site brought
     * four in ten of last season's bookings" is an argument, and a venue that
     * sees everything arriving by telephone has learned something too.
     *
     * @return list<array{label: string, count: int, percent: int, byStaff: bool}>
     */
    public function channels(): array
    {
        $counts = $this->held()
            ->toBase()
            ->selectRaw('channel, count(*) as total')
            ->groupBy('channel')
            ->pluck('total', 'channel');

        $all = (int) $counts->sum();

        if ($all === 0) {
            return [];
        }

        return $counts
            ->map(static fn (mixed $total, string $channel): array => [
                'label' => Channel::from($channel)->label(),
                'count' => (int) $total,
                'percent' => (int) round((int) $total / $all * 100),
                'byStaff' => Channel::from($channel)->isTakenByStaff(),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Which days of the week carried the season.
     *
     * Grouped in PHP from one dated query rather than with a database date
     * function: SQLite and MySQL spell weekday extraction differently, and
     * the suite would then be proving something about SQLite (§25).
     *
     * @return list<array{day: string, bookings: int, guests: int, percent: int}>
     */
    public function weekdays(): array
    {
        $rows = $this->held()
            ->toBase()
            ->select(['starts_at', 'party_size'])
            ->get();

        $buckets = [];

        for ($day = 1; $day <= 7; $day++) {
            $buckets[$day] = ['bookings' => 0, 'guests' => 0];
        }

        foreach ($rows as $row) {
            $local = CarbonImmutable::parse((string) $row->starts_at, 'UTC')->setTimezone($this->timezone);

            $buckets[$local->dayOfWeekIso]['bookings']++;
            $buckets[$local->dayOfWeekIso]['guests'] += (int) $row->party_size;
        }

        $busiest = max(array_column($buckets, 'bookings')) ?: 1;

        $out = [];

        foreach ($buckets as $iso => $bucket) {
            $out[] = [
                'day' => CarbonImmutable::now($this->timezone)->startOfWeek()->addDays($iso - 1)->format('D'),
                'bookings' => $bucket['bookings'],
                'guests' => $bucket['guests'],
                'percent' => (int) round($bucket['bookings'] / $busiest * 100),
            ];
        }

        return $out;
    }

    /**
     * How each booking type did.
     *
     * Separately, always: twenty sunbeds and four tables fill at different
     * speeds, and one figure across both describes neither.
     *
     * @param  Collection<int, BookingType>  $bookingTypes
     * @return list<array{name: string, bookings: int, guests: int, money: int, spots: int}>
     */
    public function byBookingType(Collection $bookingTypes): array
    {
        if ($bookingTypes->isEmpty()) {
            return [];
        }

        $rows = $this->held()
            ->toBase()
            ->selectRaw('booking_type_id, count(*) as bookings, coalesce(sum(party_size), 0) as guests, coalesce(sum(amount_cents), 0) as money')
            ->groupBy('booking_type_id')
            ->get()
            ->keyBy('booking_type_id');

        $spots = Resource::query()
            ->where('is_active', true)
            ->whereIn('booking_type_id', $bookingTypes->modelKeys())
            ->toBase()
            ->selectRaw('booking_type_id, count(*) as total')
            ->groupBy('booking_type_id')
            ->pluck('total', 'booking_type_id');

        return $bookingTypes
            ->map(static fn (BookingType $type): array => [
                'name' => $type->name,
                'bookings' => (int) ($rows[$type->getKey()]->bookings ?? 0),
                'guests' => (int) ($rows[$type->getKey()]->guests ?? 0),
                'money' => (int) ($rows[$type->getKey()]->money ?? 0),
                'spots' => (int) ($spots[$type->getKey()] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * How full the place was, stated as something true.
     *
     * Not a percentage of a theoretical maximum nobody could reach: on an
     * average day the venue was open, this many of its spots were booked at
     * least once. It undercounts a table that turns three times, which is
     * why the sentence says "at least once" rather than pretending to a
     * precision the data does not carry.
     *
     * @return array{spots: int, averageBooked: float, openDays: int, bestDay: ?string, bestBooked: int}
     */
    public function occupancy(): array
    {
        $spots = Resource::query()->where('is_active', true)->count();

        $perDay = $this->held()
            ->toBase()
            ->select(['starts_at', 'resource_id'])
            ->get()
            ->groupBy(fn (object $row): string => CarbonImmutable::parse((string) $row->starts_at, 'UTC')
                ->setTimezone($this->timezone)
                ->toDateString())
            ->map(static fn (Collection $rows): int => $rows->pluck('resource_id')->filter()->unique()->count());

        $openDays = $this->openDays();

        $best = $perDay->sortDesc()->take(1);

        return [
            'spots' => $spots,
            'averageBooked' => $openDays > 0 ? round($perDay->sum() / $openDays, 1) : 0.0,
            'openDays' => $openDays,
            'bestDay' => $best->keys()->first(),
            'bestBooked' => (int) ($best->first() ?? 0),
        ];
    }

    /**
     * Days in the window the venue was actually taking bookings.
     *
     * A closed Monday is not a Monday nobody booked, and averaging over the
     * calendar instead of over open days quietly halves the occupancy of a
     * place that shuts twice a week.
     */
    private function openDays(): int
    {
        $openWeekdays = OpeningHour::query()
            ->where('scope', 'venue')
            ->pluck('day_of_week')
            ->map(static fn (mixed $day): int => (int) $day)
            ->unique();

        $closures = BookingClosure::query()
            ->where('date_from', '<', $this->to->toDateString())
            ->where('date_to', '>=', $this->from->toDateString())
            ->get()
            ->filter(static fn (BookingClosure $closure): bool => $closure->isAllDay());

        $days = 0;

        for ($date = $this->from; $date->lt($this->to); $date = $date->addDay()) {
            // No opening hours set at all means the venue has not told us when
            // it is shut, so every day counts rather than none.
            if ($openWeekdays->isNotEmpty() && ! $openWeekdays->contains($date->dayOfWeek)) {
                continue;
            }

            $closed = $closures->contains(
                static fn (BookingClosure $closure): bool => $closure->date_from->lte($date)
                    && $closure->date_to->gte($date)
            );

            if ($closed) {
                continue;
            }

            $days++;
        }

        return $days;
    }
}
