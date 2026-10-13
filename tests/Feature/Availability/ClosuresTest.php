<?php

declare(strict_types=1);

namespace Tests\Feature\Availability;

use App\Actions\Closures\CloseBookings;
use App\Actions\Closures\ReopenBookings;
use App\Enums\ReservationStatus;
use App\Models\BookingClosure;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\Concerns\BuildsVenueStructure;
use Tests\TestCase;

/**
 * Closing looks forward and never touches what is already paid for
 * (SPEC §6.2, §24.5).
 */
final class ClosuresTest extends TestCase
{
    use ActsAsVenueOwner;
    use BuildsVenueStructure;
    use RefreshDatabase;

    private const DATE = '2026-07-04';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00', 'Europe/Athens'));

        $this->signInAsOwner();
    }

    #[Test]
    public function closing_a_booking_type_empties_the_day(): void
    {
        $bookingType = $this->asTenant(function () {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');
            $this->resourcesIn($area, 'A', 3);

            return $type;
        });

        $this->assertNotSame([], $this->asTenant(fn () => $this->engine()->times($bookingType, self::DATE, 2)));

        $this->asTenant(fn () => app(CloseBookings::class)->handle(
            subject: $bookingType,
            dateFrom: self::DATE,
            reason: 'Private event',
        ));

        $this->assertSame([], $this->asTenant(fn () => $this->engine()->times($bookingType, self::DATE, 2)));

        // And only that day.
        $this->assertNotSame([], $this->asTenant(fn () => $this->engine()->times($bookingType, '2026-07-05', 2)));
    }

    #[Test]
    public function closing_one_row_leaves_the_others_bookable(): void
    {
        [$bookingType, $garden] = $this->asTenant(function (): array {
            $type = $this->sunbedType();

            $front = $this->areaIn($type, 'Front row');
            $this->resourcesIn($front, 'A', 2);

            $garden = $this->areaIn($type, 'Garden row');
            $this->resourcesIn($garden, 'G', 2);

            return [$type, $garden];
        });

        $this->asTenant(fn () => app(CloseBookings::class)->handle(
            subject: $garden,
            dateFrom: self::DATE,
            reason: 'Tree work',
        ));

        $areas = $this->asTenant(fn () => $this->engine()->at(
            $bookingType,
            CarbonImmutable::parse(self::DATE.' 11:00', 'Europe/Athens')->utc(),
            2,
        ));

        $this->assertCount(1, $areas);
        $this->assertSame('Front row', $areas[0]->area->name);
    }

    #[Test]
    public function closing_one_spot_removes_only_that_spot(): void
    {
        [$bookingType, $resources] = $this->asTenant(function (): array {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');

            return [$type, $this->resourcesIn($area, 'A', 3)];
        });

        $this->asTenant(fn () => app(CloseBookings::class)->handle(
            subject: $resources->first(),
            dateFrom: self::DATE,
            reason: 'Broken umbrella',
        ));

        $times = $this->asTenant(fn () => $this->engine()->times($bookingType, self::DATE, 2));

        $this->assertSame(2, $times[0]->available);
    }

    #[Test]
    public function a_closure_for_part_of_the_day_only_removes_those_times(): void
    {
        $bookingType = $this->asTenant(function () {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');
            $this->resourcesIn($area, 'A', 2);

            return $type;
        });

        $this->asTenant(fn () => app(CloseBookings::class)->handle(
            subject: $bookingType,
            dateFrom: self::DATE,
            timeFrom: '09:00',
            timeTo: '11:00',
            reason: 'Deliveries',
        ));

        $labels = array_map(
            static fn ($offer): string => $offer->label(),
            $this->asTenant(fn () => $this->engine()->times($bookingType, self::DATE, 2)),
        );

        $this->assertNotContains('10:00', $labels);
        $this->assertNotContains('10:30', $labels);
        $this->assertContains('11:00', $labels);
    }

    #[Test]
    public function stop_bookings_for_today_is_one_tap_and_reversible(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-04 10:00', 'Europe/Athens'));

        $bookingType = $this->asTenant(function () {
            $type = $this->tableType();
            $area = $this->areaIn($type, 'Terrace');
            $this->resourcesIn($area, 'T', 4);

            return $type;
        });

        $this->assertNotSame([], $this->asTenant(fn () => $this->engine()->times($bookingType, self::DATE, 2)));

        $closure = $this->asTenant(fn () => app(CloseBookings::class)->today($bookingType, 'Kitchen is full'));

        $this->assertTrue($closure->is_quick_stop);
        $this->assertSame([], $this->asTenant(fn () => $this->engine()->times($bookingType, self::DATE, 2)));

        $this->asTenant(fn () => app(ReopenBookings::class)->today($bookingType));

        $this->assertNotSame([], $this->asTenant(fn () => $this->engine()->times($bookingType, self::DATE, 2)));
    }

    /**
     * Reopening the day must not undo a closure somebody set on purpose for
     * the whole week.
     */
    #[Test]
    public function reopening_today_leaves_a_deliberate_closure_alone(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-04 10:00', 'Europe/Athens'));

        $bookingType = $this->asTenant(function () {
            $type = $this->tableType();
            $area = $this->areaIn($type, 'Terrace');
            $this->resourcesIn($area, 'T', 2);

            return $type;
        });

        $this->asTenant(function () use ($bookingType): void {
            $closures = app(CloseBookings::class);

            $closures->handle(
                subject: $bookingType,
                dateFrom: '2026-07-01',
                dateTo: '2026-07-10',
                reason: 'Closed for a refit',
            );

            $closures->today($bookingType);
        });

        $this->asTenant(fn () => app(ReopenBookings::class)->today($bookingType));

        $this->assertSame(1, $this->asTenant(fn (): int => BookingClosure::query()->count()));
        $this->assertSame([], $this->asTenant(fn () => $this->engine()->times($bookingType, self::DATE, 2)));
    }

    /**
     * The rule that keeps the system trustworthy: a closure stops new
     * bookings and leaves paid ones exactly where they are (SPEC §24.5).
     */
    #[Test]
    public function closing_never_cancels_a_booking_that_was_paid_for(): void
    {
        [$bookingType, $resource] = $this->asTenant(function (): array {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');

            return [$type, $this->resourcesIn($area, 'A', 1)->first()];
        });

        $reservation = $this->asTenant(fn () => Reservation::factory()
            ->forResource($resource)
            ->at(
                CarbonImmutable::parse(self::DATE.' 10:00', 'Europe/Athens')->utc(),
                CarbonImmutable::parse(self::DATE.' 19:00', 'Europe/Athens')->utc(),
            )
            ->create());

        $closure = $this->asTenant(fn () => app(CloseBookings::class)->handle(
            subject: $bookingType,
            dateFrom: self::DATE,
            reason: 'Storm forecast',
        ));

        $reservation->refresh();

        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertNull($reservation->cancelled_at);

        // The owner is told how many people they need to ring.
        $this->assertSame(1, $this->asTenant(
            fn (): int => app(CloseBookings::class)->affectedReservations($closure)
        ));
    }

    #[Test]
    public function another_venues_closure_does_not_shut_this_one(): void
    {
        $bookingType = $this->asTenant(function () {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');
            $this->resourcesIn($area, 'A', 2);

            return $type;
        });

        $mine = $this->tenant;

        $this->signInAsOwner('Another Place');

        $this->asTenant(function (): void {
            $type = $this->sunbedType();
            app(CloseBookings::class)->handle(subject: $type, dateFrom: self::DATE);
        });

        $times = app(\App\Tenancy\TenantContext::class)->asTenant(
            $mine,
            fn () => $this->engine()->times($bookingType, self::DATE, 2),
        );

        $this->assertNotSame([], $times);
    }
}
