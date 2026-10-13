<?php

declare(strict_types=1);

namespace Tests\Feature\Structure;

use App\Enums\BookingMode;
use App\Enums\Occupancy;
use App\Enums\PaymentRequirement;
use App\Enums\ResourceType;
use App\Models\BookingType;
use App\Models\OpeningHour;
use App\Support\BookingTypePresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

final class BookingTypeSetupTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    #[Test]
    public function the_setup_screen_asks_what_the_venue_takes_bookings_for(): void
    {
        $this->signInAsOwner();

        $this->get(route('dashboard.booking-types.index'))
            ->assertOk()
            ->assertSee('What do you take bookings for?')
            ->assertSee('Tables')
            ->assertSee('Sunbeds');
    }

    #[Test]
    public function the_sunbed_preset_produces_a_booking_type_that_behaves_like_sunbeds(): void
    {
        $this->signInAsOwner();

        $this->post(
            route('dashboard.booking-types.store'),
            BookingTypePresets::all()['sunbeds']['fields'],
        )->assertRedirect();

        $bookingType = $this->asTenant(static fn (): ?BookingType => BookingType::query()->first());

        $this->assertNotNull($bookingType);
        $this->assertSame('Sunbeds', $bookingType->name);
        $this->assertSame(ResourceType::SunbedSet, $bookingType->resource_type);
        $this->assertSame(BookingMode::ArrivalWindow, $bookingType->booking_mode);
        $this->assertSame(PaymentRequirement::Required, $bookingType->payment_requirement);

        // A sunbed is held for the day, not for ninety minutes.
        $this->assertSame(Occupancy::WholeDay, $bookingType->occupancy);
        $this->assertNull($bookingType->maxStayMinutes());
        $this->assertSame(2000, $bookingType->default_price_cents);
    }

    #[Test]
    public function the_table_preset_books_by_the_slot_and_takes_no_money(): void
    {
        $this->signInAsOwner();

        $this->post(
            route('dashboard.booking-types.store'),
            BookingTypePresets::all()['tables']['fields'],
        )->assertRedirect();

        $bookingType = $this->asTenant(static fn (): ?BookingType => BookingType::query()->first());

        $this->assertNotNull($bookingType);
        $this->assertSame(BookingMode::Slot, $bookingType->booking_mode);
        $this->assertSame(Occupancy::FixedDuration, $bookingType->occupancy);
        $this->assertSame(90, $bookingType->maxStayMinutes());
        $this->assertSame(PaymentRequirement::None, $bookingType->payment_requirement);
        $this->assertSame(0, $bookingType->default_price_cents);
    }

    /**
     * A booking type with no hours accepts nothing, which reads as a bug to
     * whoever just created it.
     */
    #[Test]
    public function a_new_booking_type_opens_every_day(): void
    {
        $this->signInAsOwner();

        $this->post(
            route('dashboard.booking-types.store'),
            BookingTypePresets::all()['sunbeds']['fields'],
        );

        $hours = $this->asTenant(static fn () => OpeningHour::query()->get());

        $this->assertCount(7, $hours);
        $this->assertSame([0, 1, 2, 3, 4, 5, 6], $hours->pluck('day_of_week')->sort()->values()->all());
        $this->assertSame(
            [OpeningHour::SCOPE_BOOKING_TYPE],
            $hours->pluck('scope')->unique()->values()->all(),
        );
    }

    #[Test]
    public function two_booking_types_with_the_same_name_get_different_slugs(): void
    {
        $this->signInAsOwner();

        $fields = BookingTypePresets::all()['tables']['fields'];

        $this->post(route('dashboard.booking-types.store'), $fields);
        $this->post(route('dashboard.booking-types.store'), $fields);

        $slugs = $this->asTenant(
            static fn () => BookingType::query()->orderBy('id')->pluck('slug')->all()
        );

        $this->assertSame(['tables', 'tables-2'], $slugs);
    }

    #[Test]
    public function the_price_is_stored_in_cents(): void
    {
        $this->signInAsOwner();

        $this->post(route('dashboard.booking-types.store'), [
            ...BookingTypePresets::all()['sunbeds']['fields'],
            'default_price' => '19.99',
        ]);

        $bookingType = $this->asTenant(static fn (): ?BookingType => BookingType::query()->first());

        $this->assertNotNull($bookingType);
        $this->assertSame(1999, $bookingType->default_price_cents);
    }

    #[Test]
    public function a_booking_type_needs_a_name_and_a_mode(): void
    {
        $this->signInAsOwner();

        $this->post(route('dashboard.booking-types.store'), [
            'name' => '',
            'resource_type' => 'table',
            'booking_mode' => 'whenever',
            'payment_requirement' => 'none',
            'default_price' => 0,
            'min_lead_time_minutes' => 60,
        ])->assertSessionHasErrors(['name', 'booking_mode']);

        $this->assertSame(0, $this->asTenant(static fn (): int => BookingType::query()->count()));
    }

    /**
     * Route model binding has to see the tenant, which means the dashboard's
     * tenant middleware runs before SubstituteBindings. Get that order wrong
     * and every screen with a model in its URL throws.
     */
    #[Test]
    public function a_booking_type_screen_loads_by_its_slug(): void
    {
        $this->signInAsOwner();

        $bookingType = $this->asTenant(
            static fn (): BookingType => BookingType::factory()->sunbeds()->create(['name' => 'Sunbeds'])
        );

        $this->get(route('dashboard.booking-types.show', $bookingType))
            ->assertOk()
            ->assertSee('Sunbeds')
            ->assertSee('Where are your sunbed sets?');
    }

    #[Test]
    public function another_venues_booking_type_is_a_404(): void
    {
        $this->signInAsOwner();

        $theirs = $this->asTenant(
            static fn (): BookingType => BookingType::factory()->create()
        );

        $this->signInAsOwner('Another Place');

        $this->get(route('dashboard.booking-types.show', $theirs))->assertNotFound();
    }

    #[Test]
    public function the_dashboard_is_closed_to_visitors(): void
    {
        $this->get(route('dashboard.booking-types.index'))->assertRedirect(route('login'));
    }
}
