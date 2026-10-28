<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\BookingType;
use App\Models\Reservation;
use App\Models\Resource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\Concerns\BuildsVenueStructure;
use Tests\TestCase;

/**
 * The screen an owner opens five times a day (SPEC §13.3).
 */
final class TodayScreenTest extends TestCase
{
    use ActsAsVenueOwner;
    use BuildsVenueStructure;
    use RefreshDatabase;

    private BookingType $bookingType;

    private Resource $resource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-04 10:00', 'Europe/Athens'));

        $this->signInAsOwner();

        [$this->bookingType, $this->resource] = $this->asTenant(function (): array {
            $type = $this->tableType();
            $area = $this->areaIn($type, 'Terrace');

            return [$type, $this->resourcesIn($area, 'T', 4)->first()];
        });
    }

    #[Test]
    public function todays_bookings_are_listed_in_order_with_the_totals(): void
    {
        $this->asTenant(function (): void {
            $this->booking('21:00', 'Anna Muller', 6);
            $this->booking('19:00', 'Maria Rossi', 4);
        });

        $this->get(route('dashboard.today'))
            ->assertOk()
            ->assertSeeInOrder(['Maria Rossi', 'Anna Muller'])
            ->assertSee('Terrace')
            ->assertSee('<b>2</b>', false)       // two bookings
            ->assertSee('<b>10</b>', false);     // ten guests
    }

    #[Test]
    public function a_late_sitting_belongs_to_the_night_it_started(): void
    {
        $this->asTenant(function (): void {
            // Half past midnight, which is still Saturday night to everyone
            // working it.
            $startsAt = CarbonImmutable::parse('2026-07-05 00:30', 'Europe/Athens')->utc();

            Reservation::factory()
                ->forResource($this->resource)
                ->at($startsAt, $startsAt->addMinutes(90))
                ->create(['customer_name' => 'Late Arrival']);
        });

        $this->get(route('dashboard.today'))->assertSee('Late Arrival');
        $this->get(route('dashboard.today', ['when' => 'tomorrow']))->assertDontSee('Late Arrival');
    }

    #[Test]
    public function a_cancelled_booking_is_not_in_the_list(): void
    {
        $this->asTenant(function (): void {
            Reservation::factory()
                ->forResource($this->resource)
                ->cancelled()
                ->at(
                    CarbonImmutable::parse('2026-07-04 19:00', 'Europe/Athens')->utc(),
                    CarbonImmutable::parse('2026-07-04 20:30', 'Europe/Athens')->utc(),
                )
                ->create(['customer_name' => 'Gone Away']);
        });

        $this->get(route('dashboard.today'))->assertDontSee('Gone Away');
    }

    #[Test]
    public function tomorrow_and_the_week_are_one_tap_away(): void
    {
        $this->asTenant(function (): void {
            $startsAt = CarbonImmutable::parse('2026-07-06 19:00', 'Europe/Athens')->utc();

            Reservation::factory()
                ->forResource($this->resource)
                ->at($startsAt, $startsAt->addMinutes(90))
                ->create(['customer_name' => 'Monday Guest']);
        });

        $this->get(route('dashboard.today'))->assertDontSee('Monday Guest');
        $this->get(route('dashboard.today', ['when' => 'week']))->assertSee('Monday Guest');
    }

    #[Test]
    public function stopping_bookings_for_today_is_one_tap_from_this_screen(): void
    {
        $this->patch(route('dashboard.closures.stop-today', $this->bookingType))
            ->assertRedirect();

        $this->get(route('dashboard.today'))
            ->assertSee('Stopped for today')
            ->assertSee('Start again');

        $this->assertSame([], $this->asTenant(
            fn () => $this->engine()->times($this->bookingType, '2026-07-04', 2)
        ));

        $this->patch(route('dashboard.closures.resume-today', $this->bookingType))
            ->assertRedirect();

        $this->assertNotSame([], $this->asTenant(
            fn () => $this->engine()->times($this->bookingType, '2026-07-04', 2)
        ));
    }

    #[Test]
    public function a_guest_is_seated_without_leaving_this_screen(): void
    {
        $reservation = $this->asTenant(fn (): Reservation => $this->booking('19:00', 'Maria Rossi', 4));

        $this->get(route('dashboard.today'))
            ->assertOk()
            ->assertSee('Seat');

        /*
         * Walking somebody to a table and then opening their booking to say
         * so is two jobs for one event, and the second one is the one that
         * gets skipped on a full Saturday.
         */
        $this->patch(route('dashboard.reservations.check-in', $reservation))->assertRedirect();

        $this->get(route('dashboard.today'))
            ->assertOk()
            ->assertSee('Seated');

        $this->assertNotNull(
            $this->asTenant(static fn (): Reservation => Reservation::query()->firstOrFail())->checked_in_at
        );
    }

    #[Test]
    public function a_note_is_spelled_out_on_the_row(): void
    {
        $this->asTenant(function (): void {
            $this->booking('19:00', 'Maria Rossi', 4)
                ->forceFill(['notes' => 'Allergic to shellfish'])
                ->save();
        });

        // Not behind a pill with a title attribute: a phone has no hover,
        // and this is the one line the kitchen has to see.
        $this->get(route('dashboard.today'))
            ->assertOk()
            ->assertSee('Allergic to shellfish');
    }

    #[Test]
    public function somebody_standing_at_the_door_is_raised_on_the_screen(): void
    {
        $this->asTenant(function (): void {
            // Booked for half past nine, and it is now ten.
            $this->booking('09:30', 'Hunter Pappas', 2);
        });

        $this->get(route('dashboard.today'))
            ->assertOk()
            ->assertSee('Needs you')
            ->assertSee('Hunter Pappas is 30 minutes late');
    }

    #[Test]
    public function a_guest_four_minutes_late_is_simply_a_guest(): void
    {
        $this->asTenant(fn () => $this->booking('09:56', 'Maria Rossi', 2));

        // A table is held for a while on purpose. An alarm at four minutes
        // is an alarm nobody reads by the end of the week.
        $this->get(route('dashboard.today'))
            ->assertOk()
            ->assertDontSee('Needs you');
    }

    #[Test]
    public function covers_are_counted_against_the_size_of_the_room(): void
    {
        $this->asTenant(fn () => $this->booking('19:00', 'Maria Rossi', 4));

        // "4 covers" says nothing on its own; "4 of 16 seats" is the number
        // an owner can act on.
        $this->get(route('dashboard.today'))
            ->assertOk()
            ->assertSee('of 16 seats');
    }

    #[Test]
    public function another_venues_bookings_never_appear(): void
    {
        $this->asTenant(fn () => $this->booking('19:00', 'Mine Only', 2));

        $this->signInAsOwner('Another Place');

        $this->get(route('dashboard.today'))->assertDontSee('Mine Only');
    }

    private function booking(string $time, string $name, int $partySize): Reservation
    {
        $startsAt = CarbonImmutable::parse('2026-07-04 '.$time, 'Europe/Athens')->utc();

        return Reservation::factory()
            ->forResource($this->resource)
            ->at($startsAt, $startsAt->addMinutes(90))
            ->create([
                'customer_name' => $name,
                'party_size' => $partySize,
            ]);
    }
}
