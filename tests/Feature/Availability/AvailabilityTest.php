<?php

declare(strict_types=1);

namespace Tests\Feature\Availability;

use App\Availability\AreaOffer;
use App\Availability\DayStatus;
use App\Availability\TimeOffer;
use App\Enums\OnlineStatus;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\Resource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\Concerns\BuildsVenueStructure;
use Tests\TestCase;

/**
 * What is free, and what quietly must not be (SPEC §6, §25.1).
 */
final class AvailabilityTest extends TestCase
{
    use ActsAsVenueOwner;
    use BuildsVenueStructure;
    use RefreshDatabase;

    private const DATE = '2026-07-04';

    protected function setUp(): void
    {
        parent::setUp();

        // A fixed Wednesday in high season, so "tomorrow" and "next week"
        // always mean the same thing.
        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00', 'Europe/Athens'));

        $this->signInAsOwner();
    }

    #[Test]
    public function an_empty_beach_offers_every_set_at_every_arrival_time(): void
    {
        $bookingType = $this->asTenant(function () {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');
            $this->resourcesIn($area, 'A', 4);

            return $type;
        });

        $times = $this->asTenant(fn () => $this->engine()->times($bookingType, self::DATE, 2));

        $this->assertCount(7, $times);            // 10:00 to 13:00, every half hour

        foreach ($times as $time) {
            $this->assertSame(4, $time->available);
        }
    }

    #[Test]
    public function a_booked_set_is_not_offered_again_that_day(): void
    {
        [$bookingType, $resources] = $this->asTenant(function (): array {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');

            return [$type, $this->resourcesIn($area, 'A', 3)];
        });

        $this->asTenant(fn () => Reservation::factory()
            ->forResource($resources->first())
            ->at($this->athens(self::DATE, '10:00'), $this->athens(self::DATE, '19:00'))
            ->create());

        $times = $this->asTenant(fn () => $this->engine()->times($bookingType, self::DATE, 2));

        // Whole-day occupancy: that set is gone for every arrival time.
        foreach ($times as $time) {
            $this->assertSame(2, $time->available);
        }
    }

    #[Test]
    public function a_cancelled_booking_frees_its_spot_again(): void
    {
        [$bookingType, $resource] = $this->asTenant(function (): array {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');

            return [$type, $this->resourcesIn($area, 'A', 1)->first()];
        });

        $reservation = $this->asTenant(fn () => Reservation::factory()
            ->forResource($resource)
            ->at($this->athens(self::DATE, '10:00'), $this->athens(self::DATE, '19:00'))
            ->create());

        $this->assertSame([], $this->asTenant(fn () => $this->engine()->times($bookingType, self::DATE, 2)));

        $this->asTenant(fn () => $reservation->forceFill([
            'status' => ReservationStatus::CancelledByVenue,
        ])->save());

        $this->assertNotSame([], $this->asTenant(fn () => $this->engine()->times($bookingType, self::DATE, 2)));
    }

    /**
     * A no-show still held the table. Freeing it after the fact would rewrite
     * the evening.
     */
    #[Test]
    public function a_no_show_still_blocks(): void
    {
        [$bookingType, $resource] = $this->asTenant(function (): array {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');

            return [$type, $this->resourcesIn($area, 'A', 1)->first()];
        });

        $this->asTenant(fn () => Reservation::factory()
            ->forResource($resource)
            ->noShow()
            ->at($this->athens(self::DATE, '10:00'), $this->athens(self::DATE, '19:00'))
            ->create());

        $this->assertSame([], $this->asTenant(fn () => $this->engine()->times($bookingType, self::DATE, 2)));
    }

    #[Test]
    public function the_turnover_buffer_pushes_the_next_sitting_back(): void
    {
        [$bookingType, $resource] = $this->asTenant(function (): array {
            $type = $this->tableType(['turnover_buffer_minutes' => 30]);
            $area = $this->areaIn($type, 'Terrace');

            return [$type, $this->resourcesIn($area, 'T', 1)->first()];
        });

        $this->asTenant(fn () => Reservation::factory()
            ->forResource($resource)
            ->at($this->athens(self::DATE, '19:00'), $this->athens(self::DATE, '20:30'))
            ->create());

        $labels = array_map(
            static fn (TimeOffer $offer): string => $offer->label(),
            $this->asTenant(fn () => $this->engine()->times($bookingType, self::DATE, 2)),
        );

        // 20:30 finish plus a 30 minute turnaround: nothing before 21:00.
        $this->assertNotContains('19:00', $labels);
        $this->assertNotContains('20:30', $labels);
        $this->assertContains('21:00', $labels);
    }

    #[Test]
    public function a_closed_spot_is_never_offered(): void
    {
        [$bookingType, $resources] = $this->asTenant(function (): array {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');

            return [$type, $this->resourcesIn($area, 'A', 3)];
        });

        $this->asTenant(fn () => $resources->first()->forceFill([
            'online_status' => OnlineStatus::Closed,
        ])->save());

        $this->asTenant(fn () => $resources->last()->forceFill(['is_active' => false])->save());

        $times = $this->asTenant(fn () => $this->engine()->times($bookingType, self::DATE, 2));

        $this->assertSame(1, $times[0]->available);
    }

    #[Test]
    public function a_party_that_does_not_fit_is_not_offered_the_spot(): void
    {
        $bookingType = $this->asTenant(function () {
            $type = $this->tableType();
            $area = $this->areaIn($type, 'Terrace');

            $this->resourcesIn($area, 'T', 2, ['capacity_min' => 1, 'capacity_max' => 2]);
            $this->resourcesIn($area, 'B', 1, ['capacity_min' => 5, 'capacity_max' => 8]);

            return $type;
        });

        $couple = $this->asTenant(fn () => $this->engine()->times($bookingType, self::DATE, 2));
        $party = $this->asTenant(fn () => $this->engine()->times($bookingType, self::DATE, 6));

        $this->assertSame(2, $couple[0]->available);
        $this->assertSame(1, $party[0]->available);
    }

    #[Test]
    public function bookings_stop_the_configured_time_before_arrival(): void
    {
        $bookingType = $this->asTenant(function () {
            $type = $this->tableType(['min_lead_time_minutes' => 120]);
            $area = $this->areaIn($type, 'Terrace');
            $this->resourcesIn($area, 'T', 2);

            return $type;
        });

        // Standing in the restaurant at 20:00 on the night itself.
        $this->travelTo(CarbonImmutable::parse('2026-07-04 20:00', 'Europe/Athens'));

        $labels = array_map(
            static fn (TimeOffer $offer): string => $offer->label(),
            $this->asTenant(fn () => $this->engine()->times($bookingType, self::DATE, 2)),
        );

        $this->assertNotContains('21:00', $labels);
        $this->assertNotContains('21:30', $labels);
        $this->assertSame([], $labels);
    }

    #[Test]
    public function a_date_beyond_the_booking_horizon_offers_nothing(): void
    {
        $bookingType = $this->asTenant(function () {
            $type = $this->sunbedType(['max_advance_days' => 7]);
            $area = $this->areaIn($type, 'Front row');
            $this->resourcesIn($area, 'A', 2);

            return $type;
        });

        $withinRange = CarbonImmutable::now('Europe/Athens')->addDays(5)->toDateString();
        $tooFar = CarbonImmutable::now('Europe/Athens')->addDays(30)->toDateString();
        $yesterday = CarbonImmutable::now('Europe/Athens')->subDay()->toDateString();

        $this->assertNotSame([], $this->asTenant(fn () => $this->engine()->times($bookingType, $withinRange, 2)));
        $this->assertSame([], $this->asTenant(fn () => $this->engine()->times($bookingType, $tooFar, 2)));
        $this->assertSame([], $this->asTenant(fn () => $this->engine()->times($bookingType, $yesterday, 2)));
    }

    #[Test]
    public function the_cheapest_free_spot_sets_the_headline_price(): void
    {
        $bookingType = $this->asTenant(function () {
            $type = $this->sunbedType();

            $front = $this->areaIn($type, 'Front row');
            $this->resourcesIn($front, 'A', 2, ['base_price_cents' => 3000]);

            $back = $this->areaIn($type, 'Back row');
            $this->resourcesIn($back, 'B', 2, ['base_price_cents' => 1600]);

            return $type;
        });

        $times = $this->asTenant(fn () => $this->engine()->times($bookingType, self::DATE, 2));

        $this->assertSame(1600, $times[0]->fromCents);
    }

    #[Test]
    public function asking_about_one_time_groups_what_is_free_by_area(): void
    {
        $bookingType = $this->asTenant(function () {
            $type = $this->sunbedType();

            $front = $this->areaIn($type, 'Front row');
            $this->resourcesIn($front, 'A', 2);

            $back = $this->areaIn($type, 'Back row');
            $this->resourcesIn($back, 'B', 3);

            return $type;
        });

        $areas = $this->asTenant(
            fn () => $this->engine()->at($bookingType, $this->athens(self::DATE, '11:00'), 2)
        );

        $this->assertCount(2, $areas);
        $this->assertSame(['Front row', 'Back row'], array_map(
            static fn (AreaOffer $area): string => $area->area->name,
            $areas,
        ));
        $this->assertSame(2, $areas[0]->count());
        $this->assertSame(3, $areas[1]->count());
    }

    /**
     * A timestamp typed into a URL must not get past opening hours.
     */
    #[Test]
    public function a_time_the_venue_never_offered_is_refused(): void
    {
        [$bookingType, $resource] = $this->asTenant(function (): array {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');

            return [$type, $this->resourcesIn($area, 'A', 1)->first()];
        });

        $this->assertSame([], $this->asTenant(
            fn () => $this->engine()->at($bookingType, $this->athens(self::DATE, '03:00'), 2)
        ));

        $this->assertFalse($this->asTenant(
            fn () => $this->engine()->canBook($resource, $this->athens(self::DATE, '10:17'), 2)
        ));

        $this->assertTrue($this->asTenant(
            fn () => $this->engine()->canBook($resource, $this->athens(self::DATE, '10:30'), 2)
        ));
    }

    #[Test]
    public function a_month_comes_back_in_one_pass_for_the_date_picker(): void
    {
        $bookingType = $this->asTenant(function () {
            $type = $this->sunbedType(['max_advance_days' => 365]);
            $area = $this->areaIn($type, 'Front row');
            $this->resourcesIn($area, 'A', 4);

            return $type;
        });

        // Every set taken on one day, so the calendar has something to say.
        $this->asTenant(function () use ($bookingType): void {
            foreach (Resource::query()->where('booking_type_id', $bookingType->id)->get() as $resource) {
                Reservation::factory()
                    ->forResource($resource)
                    ->at($this->athens('2026-07-11', '10:00'), $this->athens('2026-07-11', '19:00'))
                    ->create();
            }
        });

        $month = $this->asTenant(fn () => $this->engine()->month($bookingType, '2026-07', 2));

        $this->assertCount(31, $month);
        $this->assertSame(DayStatus::Available, $month['2026-07-04']);
        $this->assertSame(DayStatus::Full, $month['2026-07-11']);
        $this->assertTrue($month['2026-07-04']->isBookable());
        $this->assertFalse($month['2026-07-11']->isBookable());
    }

    #[Test]
    public function one_venue_never_sees_another_ones_bookings(): void
    {
        $bookingType = $this->asTenant(function () {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');
            $this->resourcesIn($area, 'A', 1);

            return $type;
        });

        $mine = $this->tenant;

        // A second venue books its own sunbed at the same time.
        $this->signInAsOwner('Another Place');

        $this->asTenant(function (): void {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');
            $theirs = $this->resourcesIn($area, 'A', 1)->first();

            Reservation::factory()
                ->forResource($theirs)
                ->at($this->athens(self::DATE, '10:00'), $this->athens(self::DATE, '19:00'))
                ->create();
        });

        $times = app(\App\Tenancy\TenantContext::class)->asTenant(
            $mine,
            fn () => $this->engine()->times($bookingType, self::DATE, 2),
        );

        $this->assertNotSame([], $times, 'Another venue\'s booking blocked this one.');
        $this->assertSame(1, $times[0]->available);
    }

    private function athens(string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' '.$time, 'Europe/Athens')->utc();
    }
}
