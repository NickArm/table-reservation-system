<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\Concerns\BuildsVenueStructure;
use Tests\TestCase;

/**
 * The shape of the week (SPEC §13.1).
 *
 * What this screen has to get right is the week boundary, in the venue's own
 * time. An owner looking at "this week" on a Sunday evening and being shown
 * next week would stop trusting the screen immediately.
 */
final class CalendarTest extends TestCase
{
    use ActsAsVenueOwner;
    use BuildsVenueStructure;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday, so the week runs Monday 13th to Sunday 19th.
        $this->travelTo(CarbonImmutable::parse('2026-07-15 15:00', 'Europe/Athens'));

        $this->signInAsOwner('Demo Beach Bar');

        $this->asTenant(function (): void {
            $type = $this->sunbedType(['name' => 'Sunbeds']);
            $area = $this->areaIn($type, 'Front row');
            $this->resourcesIn($area, 'A', 4, ['base_price_cents' => 2500]);
        });
    }

    #[Test]
    public function the_week_runs_monday_to_sunday_in_the_venues_own_time(): void
    {
        $this->booking('2026-07-13 10:00', 'Monday Guest');
        $this->booking('2026-07-19 10:00', 'Sunday Guest');

        // The Monday before and the Monday after are both outside it.
        $this->booking('2026-07-12 10:00', 'Last Week');
        $this->booking('2026-07-20 10:00', 'Next Week');

        $this->get(route('dashboard.calendar'))
            ->assertOk()
            ->assertSee('Monday Guest')
            ->assertSee('Sunday Guest')
            ->assertDontSee('Last Week')
            ->assertDontSee('Next Week');
    }

    #[Test]
    public function every_day_appears_even_the_empty_ones(): void
    {
        $this->booking('2026-07-15 10:00', 'Only Booking');

        // An empty Tuesday is information: it is what tells an owner to leave
        // the back row shut.
        $this->get(route('dashboard.calendar'))
            ->assertOk()
            ->assertSee('Only Booking')
            ->assertSeeInOrder(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'])
            ->assertSee('Nothing booked');
    }

    #[Test]
    public function a_cancelled_booking_is_not_counted_against_the_day(): void
    {
        $this->booking('2026-07-15 10:00', 'Real Booking');
        $this->booking('2026-07-15 11:00', 'Called Off', ['status' => ReservationStatus::CancelledByVenue]);

        $response = $this->get(route('dashboard.calendar'))->assertOk();

        $response->assertSee('Real Booking')->assertDontSee('Called Off');

        // One of four spots: the meter reads a quarter, not a half.
        $response->assertSee('1 of 4 spots booked');
    }

    #[Test]
    public function the_weeks_either_side_are_one_click_away(): void
    {
        $this->booking('2026-07-20 10:00', 'Next Week');

        $this->get(route('dashboard.calendar'))
            ->assertOk()
            // Already on this week, so there is nothing to go back to it with.
            ->assertDontSee('This week');

        $this->get(route('dashboard.calendar', ['week' => '2026-07-20']))
            ->assertOk()
            ->assertSee('Next Week')
            ->assertSee('This week');
    }

    #[Test]
    public function nonsense_in_the_address_bar_falls_back_to_this_week(): void
    {
        $this->booking('2026-07-15 10:00', 'This Week');

        $this->get(route('dashboard.calendar', ['week' => 'not-a-date']))
            ->assertOk()
            ->assertSee('This Week');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function booking(string $local, string $name, array $attributes = []): Reservation
    {
        return $this->asTenant(function () use ($local, $name, $attributes): Reservation {
            $startsAt = CarbonImmutable::parse($local, 'Europe/Athens');

            return Reservation::factory()
                ->at($startsAt->utc(), $startsAt->addHours(2)->utc())
                ->create([
                    'customer_name' => $name,
                    'amount_cents' => 2500,
                    'resource_snapshot' => ['label' => 'A1', 'area' => 'Front row', 'components' => null],
                    ...$attributes,
                ]);
        });
    }
}
