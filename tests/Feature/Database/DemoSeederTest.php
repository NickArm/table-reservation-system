<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Availability\AvailabilityEngine;
use App\Enums\BookingMode;
use App\Enums\Gateway;
use App\Enums\Occupancy;
use App\Enums\PriceRuleType;
use App\Enums\ReservationStatus;
use App\Enums\StructureScope;
use App\Enums\TenantStatus;
use App\Models\Area;
use App\Models\BookingClosure;
use App\Models\BookingType;
use App\Models\OpeningHour;
use App\Models\PaymentGatewaySetting;
use App\Models\PriceRule;
use App\Models\Reservation;
use App\Models\ReservationHold;
use App\Models\Resource;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\Tenant;
use App\Models\Venue;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoRestaurantSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The demo is a sales tool, a development fixture and a test fixture at once
 * (SPEC §22), so it is checked like any other feature: if it stops looking
 * live, the suite says so.
 */
final class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $demo;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('media.disk'));

        // The plans first: without them the demo account gets no subscription
        // at all, which is how its billing state went unchecked for a while.
        $this->seed(PlanSeeder::class);
        $this->seed(DemoRestaurantSeeder::class);

        $demo = Tenant::query()->where('slug', 'demo')->first();
        $this->assertNotNull($demo);

        $this->demo = $demo;

        app(TenantContext::class)->set($this->demo);
    }

    #[Test]
    public function the_demo_account_is_a_paying_customer_not_an_expired_one(): void
    {
        $subscription = Subscription::query()->sole();

        // The period has to straddle today. It did not, once: the arithmetic
        // landed the end on today at midnight, so the demo came up read-only
        // with its public page closed — the whole product, demonstrating the
        // one state nobody wants to see.
        $this->assertTrue(
            $subscription->isCurrent(),
            'The demo subscription should still be running, not already over.'
        );

        $this->assertSame(TenantStatus::Active, $this->demo->fresh()?->status);
        $this->assertTrue($this->demo->fresh()?->acceptsBookings());

        // And a history that reads like an account with a past.
        $this->assertGreaterThanOrEqual(2, SubscriptionEvent::query()->count());
    }

    #[Test]
    public function the_demo_venue_has_a_complete_public_profile(): void
    {
        $venue = Venue::query()->firstOrFail();

        $this->assertSame('Demo Beach Bar', $venue->name);
        $this->assertSame(100, $venue->profileCompleteness()['percent']);
        $this->assertTrue($venue->shouldBeIndexed());
    }

    #[Test]
    public function it_publishes_a_hero_image_and_a_logo(): void
    {
        $venue = Venue::query()->firstOrFail();

        $disk = Storage::disk(config('media.disk'));

        $this->assertNotNull($venue->hero_path);
        $this->assertNotNull($venue->logo_path);
        $disk->assertExists($venue->hero_path);
        $disk->assertExists($venue->logo_path);
    }

    #[Test]
    public function it_sells_both_sunbeds_and_tables(): void
    {
        $types = BookingType::query()->orderBy('id')->get();

        $this->assertCount(2, $types);

        $sunbeds = $types->firstWhere('name', 'Sunbeds');
        $tables = $types->firstWhere('name', 'Tables');

        $this->assertNotNull($sunbeds);
        $this->assertNotNull($tables);
        $this->assertSame(BookingMode::ArrivalWindow, $sunbeds->booking_mode);
        $this->assertSame(BookingMode::Slot, $tables->booking_mode);
    }

    #[Test]
    public function every_booking_type_opens_every_day(): void
    {
        foreach (BookingType::query()->get() as $bookingType) {
            $this->assertCount(
                7,
                OpeningHour::query()->forBookingType($bookingType)->get(),
                "{$bookingType->name} is missing opening hours",
            );
        }
    }

    #[Test]
    public function the_beach_has_rows_at_different_prices(): void
    {
        $prices = Area::query()
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn (Area $area): array => [
                $area->name => (int) Resource::query()->where('area_id', $area->id)->min('base_price_cents'),
            ]);

        $this->assertSame(3000, $prices['Front row']);
        $this->assertSame(2200, $prices['Second row']);
        $this->assertSame(1600, $prices['Garden row']);
    }

    #[Test]
    public function the_garden_row_turns_over_while_the_front_row_does_not(): void
    {
        $sunbeds = BookingType::query()->where('name', 'Sunbeds')->firstOrFail();

        $front = Area::query()->where('name', 'Front row')->firstOrFail();
        $garden = Area::query()->where('name', 'Garden row')->firstOrFail();

        $this->assertSame(Occupancy::WholeDay, $front->effectiveOccupancy($sunbeds));
        $this->assertSame(Occupancy::FixedDuration, $garden->effectiveOccupancy($sunbeds));
        $this->assertSame(300, $garden->effectiveMaxStayMinutes($sunbeds));
    }

    #[Test]
    public function the_sets_are_made_of_real_things(): void
    {
        $set = Resource::query()->where('label', 'A1')->firstOrFail();

        $this->assertTrue($set->is_composite);
        $this->assertSame('2 Sunbeds · Umbrella · Side table', $set->componentSummary());

        $family = Resource::query()->where('label', 'AF1')->firstOrFail();

        $this->assertSame('4 Sunbeds · 2 Umbrellas · Side table', $family->componentSummary());
        $this->assertSame(5500, $family->base_price_cents);
    }

    #[Test]
    public function it_looks_like_a_working_tuesday_rather_than_a_blank_slate(): void
    {
        $this->assertSame(44, Resource::query()->count());

        // Two held back for walk-ins, one out of service.
        $this->assertSame(41, Resource::query()->bookable()->count());
        $this->assertSame(1, Resource::query()->where('is_active', false)->count());
    }

    /**
     * A demo with an empty diary does not look like a business.
     */
    #[Test]
    public function it_has_a_season_worth_of_bookings_around_today(): void
    {
        $this->assertGreaterThan(100, Reservation::query()->count());

        $past = Reservation::query()->where('starts_at', '<', now())->count();
        $future = Reservation::query()->where('starts_at', '>', now())->count();

        $this->assertGreaterThan(20, $past, 'The demo has no history.');
        $this->assertGreaterThan(20, $future, 'The demo has nothing coming up.');

        $statuses = Reservation::query()->pluck('status')->unique();

        $this->assertTrue($statuses->contains(ReservationStatus::Completed));
        $this->assertTrue($statuses->contains(ReservationStatus::Confirmed));
        $this->assertTrue($statuses->contains(ReservationStatus::NoShow));

        // Somebody is at the payment screen right now.
        $this->assertSame(1, ReservationHold::query()->active()->count());
    }

    #[Test]
    public function nobody_is_booked_into_the_same_spot_twice(): void
    {
        $clashes = 0;

        $byResource = Reservation::query()
            ->blocking()
            ->whereNotNull('resource_id')
            ->orderBy('starts_at')
            ->get()
            ->groupBy('resource_id');

        foreach ($byResource as $reservations) {
            $previous = null;

            foreach ($reservations as $reservation) {
                if ($previous !== null && $reservation->starts_at < $previous->ends_at) {
                    $clashes++;
                }

                $previous = $reservation;
            }
        }

        $this->assertSame(0, $clashes, 'The demo double-booked a spot.');
    }

    /**
     * The demo has to show that a Saturday costs more than a Tuesday, or the
     * feature may as well not exist (SPEC §22.3).
     */
    #[Test]
    public function the_weekend_costs_more_than_the_middle_of_the_week(): void
    {
        $sunbeds = BookingType::query()->where('name', 'Sunbeds')->firstOrFail();
        $front = Area::query()->where('name', 'Front row')->firstOrFail();

        $spot = Resource::query()
            ->where('area_id', $front->id)
            ->where('label', 'A1')
            ->firstOrFail();

        $engine = app(AvailabilityEngine::class);

        // A Saturday and a Tuesday outside the high-season rule's months.
        $saturday = CarbonImmutable::parse('2026-11-07 11:00', 'Europe/Athens')->utc();
        $tuesday = CarbonImmutable::parse('2026-11-10 11:00', 'Europe/Athens')->utc();

        $weekend = $engine->priceFor($spot, $saturday, 2);
        $engine->forget();
        $midweek = $engine->priceFor($spot, $tuesday, 2);

        $this->assertGreaterThan($midweek->totalCents, $weekend->totalCents);
        $this->assertSame('Weekend rate', $weekend->rule?->name);
        $this->assertNull($midweek->rule);
        $this->assertSame($sunbeds->id, $spot->booking_type_id);
    }

    #[Test]
    public function the_demo_has_a_rule_of_each_shape(): void
    {
        $rules = PriceRule::query()->get();

        $this->assertCount(3, $rules);
        $this->assertTrue($rules->contains(fn (PriceRule $rule): bool => $rule->days_of_week !== null));
        $this->assertTrue($rules->contains(fn (PriceRule $rule): bool => $rule->date_from !== null));
        $this->assertTrue($rules->contains(fn (PriceRule $rule): bool => $rule->time_from !== null));
        $this->assertTrue($rules->contains(
            fn (PriceRule $rule): bool => $rule->price_type === PriceRuleType::Fixed
        ));
    }

    /**
     * The demo takes bookings from a clean install, with nobody's keys in it.
     */
    #[Test]
    public function the_demo_settles_at_the_venue_and_shows_what_a_gateway_looks_like(): void
    {
        $active = PaymentGatewaySetting::query()->where('is_active', true)->get();

        $this->assertCount(1, $active);
        $this->assertSame(Gateway::CashOnArrival, $active->first()->gateway);

        $stripe = PaymentGatewaySetting::query()->where('gateway', Gateway::Stripe->value)->firstOrFail();

        $this->assertFalse($stripe->is_active);
        $this->assertTrue($stripe->isTestMode());
        $this->assertNotNull($stripe->mask('secret_key'));
    }

    #[Test]
    public function it_shows_all_three_kinds_of_closure(): void
    {
        $scopes = BookingClosure::query()->pluck('scope')->unique();

        $this->assertTrue($scopes->contains(StructureScope::BookingType));
        $this->assertTrue($scopes->contains(StructureScope::Area));
        $this->assertTrue($scopes->contains(StructureScope::Resource));

        // One of them closes a morning rather than a whole day.
        $this->assertSame(
            1,
            BookingClosure::query()->whereNotNull('time_from')->count(),
        );
    }

    #[Test]
    public function the_public_page_is_live(): void
    {
        app(TenantContext::class)->forget();

        $this->get(route('public.booking', $this->demo))
            ->assertOk()
            ->assertSee('Demo Beach Bar')
            ->assertSee('Sunbeds')
            ->assertSee('Tables')
            ->assertSee('from €16');
    }

    #[Test]
    public function running_it_twice_rebuilds_rather_than_duplicates(): void
    {
        app(TenantContext::class)->forget();

        $this->seed(DemoRestaurantSeeder::class);

        $this->assertSame(1, Tenant::query()->where('slug', 'demo')->count());

        $rebuilt = Tenant::query()->where('slug', 'demo')->firstOrFail();
        app(TenantContext::class)->set($rebuilt);

        $this->assertSame(44, Resource::query()->count());
    }
}
