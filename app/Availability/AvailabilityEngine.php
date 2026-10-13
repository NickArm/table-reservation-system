<?php

declare(strict_types=1);

namespace App\Availability;

use App\Enums\StructureScope;
use App\Models\Area;
use App\Models\BookingClosure;
use App\Models\BookingType;
use App\Models\PriceRule;
use App\Models\Reservation;
use App\Models\ReservationHold;
use App\Models\Resource;
use App\Models\Venue;
use App\Pricing\Price;
use App\Pricing\PriceBook;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Answers "what is free on Saturday?" (SPEC §6).
 *
 * The one piece of the system that must never be wrong: everything else can
 * be fixed with an apology, while a double booking means two families at one
 * table. It is therefore kept free of HTTP, of request input, and of anything
 * that could not be exercised from a test.
 */
final class AvailabilityEngine
{
    /** @var array<string, DayContext> */
    private array $contexts = [];

    private ?Venue $venue = null;

    public function __construct(
        private readonly OpeningHoursResolver $hours,
        private readonly SlotGenerator $slots,
    ) {}

    /**
     * The times a guest can choose on a date, each with how much is free.
     *
     * @return array<int, TimeOffer>
     */
    public function times(BookingType $bookingType, string $date, int $partySize = 1): array
    {
        $context = $this->context($bookingType, $date);

        if ($this->dateIsOutOfRange($bookingType, $context, $date)) {
            return [];
        }

        if ($context->closures->closesWholeDay(StructureScope::BookingType, $bookingType->id)) {
            return [];
        }

        $offers = [];

        foreach ($this->slots->generate($bookingType, $context->day) as $slot) {
            if (! $this->respectsLeadTime($bookingType, $slot)) {
                continue;
            }

            if ($context->closures->blocks(StructureScope::BookingType, $bookingType->id, $slot->startsAt, $slot->endsAt)) {
                continue;
            }

            $free = $this->freeAt($context, $slot->startsAt, $partySize);

            $count = array_sum(array_map(
                static fn (AreaOffer $area): int => $area->count(),
                $free,
            ));

            if ($count === 0) {
                continue;
            }

            $prices = array_filter(array_map(
                static fn (AreaOffer $area): ?int => $area->fromCents(),
                $free,
            ), static fn (?int $price): bool => $price !== null);

            $offers[] = new TimeOffer($slot, $count, $prices === [] ? null : min($prices));
        }

        return $offers;
    }

    /**
     * What is free at one particular time, grouped the way the booking page
     * shows it.
     *
     * @return array<int, AreaOffer>
     */
    public function at(
        BookingType $bookingType,
        CarbonImmutable $startsAt,
        int $partySize = 1,
    ): array {
        $context = $this->context($bookingType, $this->dateOf($bookingType, $startsAt));

        // Checked here as well as in times(), because this is the path a
        // hold takes and a timestamp can be typed straight into a URL.
        if ($this->dateIsOutOfRange($bookingType, $context, $context->day->date)) {
            return [];
        }

        if ($context->closures->closesWholeDay(StructureScope::BookingType, $bookingType->id)) {
            return [];
        }

        if (! $this->isOfferedTime($context, $startsAt)) {
            return [];
        }

        return $this->freeAt($context, $startsAt, $partySize);
    }

    /**
     * Can this exact spot be booked at this exact time?
     *
     * The question asked immediately before a hold is taken, and again inside
     * the transaction that takes it.
     */
    public function canBook(Resource $resource, CarbonImmutable $startsAt, int $partySize = 1): bool
    {
        if (! $resource->isBookable() || ! $resource->fitsParty($partySize)) {
            return false;
        }

        $bookingType = BookingType::query()->find($resource->booking_type_id);

        if ($bookingType === null || ! $bookingType->is_active) {
            return false;
        }

        foreach ($this->at($bookingType, $startsAt, $partySize) as $area) {
            foreach ($area->offers as $offer) {
                if ($offer->resource->id === $resource->id) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A month at a glance, for colouring a date picker (SPEC §15.2).
     *
     * @return array<string, DayStatus>
     */
    public function month(BookingType $bookingType, string $month, int $partySize = 1): array
    {
        $first = CarbonImmutable::parse($month.'-01');
        $statuses = [];

        for ($date = $first; $date->month === $first->month; $date = $date->addDay()) {
            $key = $date->toDateString();
            $context = $this->context($bookingType, $key);

            $total = $context->totalResources();

            if ($total === 0 || $context->day->isClosed()) {
                $statuses[$key] = DayStatus::Closed;

                continue;
            }

            $times = $this->times($bookingType, $key, $partySize);

            if ($times === []) {
                $statuses[$key] = $context->closures->closesWholeDay(StructureScope::BookingType, $bookingType->id)
                    ? DayStatus::Closed
                    : DayStatus::Full;

                continue;
            }

            $best = max(array_map(
                static fn (TimeOffer $offer): int => $offer->available,
                $times,
            ));

            $statuses[$key] = DayStatus::fromCounts($best, $total);
        }

        return $statuses;
    }

    /**
     * The slots an area offers, which differ from the booking type's own when
     * the area holds its spots for a different length (SPEC §6.2.1).
     *
     * @return array<int, Slot>
     */
    public function slotsFor(BookingType $bookingType, Area $area, string $date): array
    {
        $context = $this->context($bookingType, $date);

        return $this->slots->generate($bookingType, $context->day, $area);
    }

    /**
     * When a booking starting at this moment would end — the one calculation
     * that must agree everywhere, because a hold, a reservation and the
     * availability check all have to describe the same stretch of time.
     */
    public function endsAtFor(
        BookingType $bookingType,
        ?Area $area,
        CarbonImmutable $startsAt,
    ): CarbonImmutable {
        $timezone = $this->timezone();

        $day = $this->hours->resolve(
            $bookingType,
            $this->dateOf($bookingType, $startsAt),
            $timezone,
        );

        return $this->slots->endsAt($bookingType, $area, $startsAt, $day);
    }

    /**
     * What one spot costs at one time — the only answer that counts.
     *
     * The price a browser sends is never trusted: it is recalculated here
     * before a hold, before a payment, and again when the webhook lands
     * (SPEC §7.2, §25.1).
     */
    public function priceFor(
        Resource $resource,
        CarbonImmutable $startsAt,
        int $partySize = 1,
    ): Price {
        $bookingType = BookingType::query()->findOrFail($resource->booking_type_id);
        $context = $this->context($bookingType, $this->dateOf($bookingType, $startsAt));
        $area = $context->areas->firstWhere('id', $resource->area_id);

        return $context->prices->priceFor($resource, $area, $startsAt, $partySize);
    }

    /**
     * Drops the cached day, so a test or a long-running command sees writes
     * made since the last question.
     */
    public function forget(): void
    {
        $this->contexts = [];
        $this->venue = null;
    }

    /**
     * @return array<int, AreaOffer>
     */
    private function freeAt(DayContext $context, CarbonImmutable $startsAt, int $partySize): array
    {
        $areas = [];

        foreach ($context->areas as $area) {
            if ($context->closures->closesWholeDay(StructureScope::Area, $area->id)) {
                continue;
            }

            $endsAt = $this->slots->endsAt($context->bookingType, $area, $startsAt, $context->day);

            if ($context->closures->blocks(StructureScope::Area, $area->id, $startsAt, $endsAt)) {
                continue;
            }

            $buffer = $context->bufferFor($area);
            $slot = new Slot($startsAt, $endsAt, $context->timezone);
            $offers = [];

            foreach ($context->resourcesIn($area) as $resource) {
                if (! $resource->fitsParty($partySize)) {
                    continue;
                }

                if ($context->closures->blocks(StructureScope::Resource, $resource->id, $startsAt, $endsAt)) {
                    continue;
                }

                if ($context->busy->isBusy($resource->id, $startsAt, $endsAt, $buffer)) {
                    continue;
                }

                $offers[] = new ResourceOffer(
                    $resource,
                    $slot,
                    $context->prices->priceFor($resource, $area, $startsAt, $partySize),
                );
            }

            if ($offers !== []) {
                $areas[] = new AreaOffer($area, $offers);
            }
        }

        return $areas;
    }

    /**
     * A time is only bookable if the generator actually offers it — otherwise
     * a guessed timestamp in a URL could book outside opening hours.
     */
    private function isOfferedTime(DayContext $context, CarbonImmutable $startsAt): bool
    {
        if (! $this->respectsLeadTimeAt($context->bookingType, $startsAt)) {
            return false;
        }

        foreach ($this->slots->generate($context->bookingType, $context->day) as $slot) {
            if ($slot->startsAt->equalTo($startsAt)) {
                return true;
            }
        }

        return false;
    }

    private function respectsLeadTime(BookingType $bookingType, Slot $slot): bool
    {
        return $this->respectsLeadTimeAt($bookingType, $slot->startsAt);
    }

    private function respectsLeadTimeAt(BookingType $bookingType, CarbonImmutable $startsAt): bool
    {
        return $startsAt >= now()->addMinutes((int) $bookingType->min_lead_time_minutes);
    }

    private function dateIsOutOfRange(BookingType $bookingType, DayContext $context, string $date): bool
    {
        $today = CarbonImmutable::now($context->timezone)->startOfDay();
        $target = CarbonImmutable::parse($date, $context->timezone)->startOfDay();

        if ($target->lessThan($today)) {
            return true;
        }

        return $target->greaterThan($today->addDays((int) $bookingType->max_advance_days));
    }

    private function dateOf(BookingType $bookingType, CarbonImmutable $startsAt): string
    {
        $timezone = $this->timezone();
        $local = $startsAt->setTimezone($timezone);

        // Before the small hours close, a booking still belongs to the night
        // before — the same business day its opening hours came from.
        $candidate = $local->toDateString();
        $previous = $local->subDay()->toDateString();

        foreach ([$candidate, $previous] as $date) {
            $day = $this->hours->resolve($bookingType, $date, $timezone);

            if ($day->contains($startsAt)) {
                return $date;
            }
        }

        return $candidate;
    }

    private function context(BookingType $bookingType, string $date): DayContext
    {
        $key = $bookingType->id.'@'.$date;

        if (isset($this->contexts[$key])) {
            return $this->contexts[$key];
        }

        $timezone = $this->timezone();
        $day = $this->hours->resolve($bookingType, $date, $timezone);

        $areas = Area::query()
            ->where('booking_type_id', $bookingType->id)
            ->orderBy('sort_order')
            ->get();

        $resources = Resource::query()
            ->bookable()
            ->where('booking_type_id', $bookingType->id)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();

        $closures = $this->closuresFor($bookingType, $areas, $resources, $date, $timezone);
        $busy = $this->busyFor($bookingType, $day);
        $prices = $this->pricesFor($bookingType, $areas, $resources, $timezone);

        return $this->contexts[$key] = new DayContext(
            bookingType: $bookingType,
            day: $day,
            timezone: $timezone,
            areas: $areas,
            resourcesByArea: $resources->groupBy('area_id'),
            closures: $closures,
            busy: $busy,
            prices: $prices,
        );
    }

    /**
     * @param  Collection<int, Area>  $areas
     * @param  Collection<int, Resource>  $resources
     */
    private function closuresFor(
        BookingType $bookingType,
        Collection $areas,
        Collection $resources,
        string $date,
        string $timezone,
    ): ClosureSet {
        $rows = BookingClosure::query()
            ->covering(
                $bookingType->id,
                $areas->pluck('id')->all(),
                $resources->pluck('id')->all(),
            )
            ->onDate($date)
            ->get();

        return new ClosureSet($rows, $date, $timezone);
    }

    /**
     * @param  Collection<int, Area>  $areas
     * @param  Collection<int, Resource>  $resources
     */
    private function pricesFor(
        BookingType $bookingType,
        Collection $areas,
        Collection $resources,
        string $timezone,
    ): PriceBook {
        $rules = PriceRule::query()
            ->covering(
                $bookingType->id,
                $areas->pluck('id')->all(),
                $resources->pluck('id')->all(),
            )
            ->orderByDesc('priority')
            ->get();

        return new PriceBook($rules, $timezone);
    }

    private function busyFor(BookingType $bookingType, BusinessDay $day): BusyIndex
    {
        $index = new BusyIndex;

        // Widened by a generous margin so that a booking starting the evening
        // before, or a buffer reaching back into the day, is still seen.
        $from = $day->startOfDay()->subDay();
        $to = $day->endOfDay()->addDay();

        Reservation::query()
            ->blocking()
            ->where('booking_type_id', $bookingType->id)
            ->whereNotNull('resource_id')
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->get(['resource_id', 'starts_at', 'ends_at'])
            ->each(static function (Reservation $reservation) use ($index): void {
                $index->add(
                    (int) $reservation->resource_id,
                    $reservation->starts_at,
                    $reservation->ends_at,
                );
            });

        ReservationHold::query()
            ->active()
            ->where('booking_type_id', $bookingType->id)
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->get(['resource_id', 'starts_at', 'ends_at'])
            ->each(static function (ReservationHold $hold) use ($index): void {
                $index->add((int) $hold->resource_id, $hold->starts_at, $hold->ends_at);
            });

        return $index;
    }

    private function timezone(): string
    {
        $this->venue ??= Venue::query()->firstOrFail();

        return (string) $this->venue->timezone;
    }
}
