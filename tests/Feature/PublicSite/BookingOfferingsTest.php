<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSite;

use App\Enums\OnlineStatus;
use App\Models\Area;
use App\Models\BookingType;
use App\Models\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * What the booking page offers is the venue's own setup and nothing else — the
 * sunbed button exists because somebody added sunbeds (SPEC §5.1).
 */
final class BookingOfferingsTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    #[Test]
    public function a_venue_with_nothing_set_up_offers_no_choice(): void
    {
        $this->signInAsOwner();

        $this->get(route('public.booking', $this->tenant))
            ->assertOk()
            ->assertDontSee('What would you like to book?');
    }

    #[Test]
    public function only_the_things_the_venue_configured_are_offered(): void
    {
        $this->signInAsOwner();

        $this->asTenant(static function (): void {
            BookingType::factory()->sunbeds()->create(['name' => 'Sunbeds']);
        });

        $this->get(route('public.booking', $this->tenant))
            ->assertOk()
            ->assertSee('Sunbeds')
            ->assertDontSee('Dining room');
    }

    #[Test]
    public function the_price_shown_is_the_cheapest_open_spot(): void
    {
        $this->signInAsOwner();

        $this->asTenant(static function (): void {
            $bookingType = BookingType::factory()->sunbeds()->create(['name' => 'Sunbeds']);
            $area = Area::factory()->create([
                'booking_type_id' => $bookingType->id,
                'name' => 'Front row',
            ]);

            Resource::factory()->create([
                'booking_type_id' => $bookingType->id,
                'area_id' => $area->id,
                'label' => 'A1',
                'base_price_cents' => 3000,
            ]);

            Resource::factory()->create([
                'booking_type_id' => $bookingType->id,
                'area_id' => $area->id,
                'label' => 'A2',
                'base_price_cents' => 2200,
            ]);

            // Cheaper, but closed — so it must not set the headline price.
            Resource::factory()->create([
                'booking_type_id' => $bookingType->id,
                'area_id' => $area->id,
                'label' => 'A3',
                'base_price_cents' => 900,
                'online_status' => OnlineStatus::Closed,
            ]);
        });

        $this->get(route('public.booking', $this->tenant))
            ->assertOk()
            ->assertSee('from €22')
            ->assertSee('Front row')
            ->assertDontSee('from €9');
    }

    #[Test]
    public function another_venues_setup_never_appears(): void
    {
        $this->signInAsOwner('Demo Beach Bar');
        $mine = $this->tenant;

        $this->asTenant(static function (): void {
            BookingType::factory()->sunbeds()->create(['name' => 'Sunbeds']);
        });

        $this->signInAsOwner('Another Place');

        $this->asTenant(static function (): void {
            BookingType::factory()->create(['name' => 'Private dining']);
        });

        $this->get(route('public.booking', $mine))
            ->assertOk()
            ->assertSee('Sunbeds')
            ->assertDontSee('Private dining');
    }
}
