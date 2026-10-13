<?php

declare(strict_types=1);

namespace Tests\Feature\Structure;

use App\Enums\OnlineStatus;
use App\Models\Area;
use App\Models\BookingType;
use App\Models\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * Opening and closing spots: the one thing an owner does mid-service, so it
 * has to be a single tap and it has to be instant (SPEC §5.4).
 */
final class AvailabilityTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAsOwner();

        $this->area = $this->asTenant(static function (): Area {
            $bookingType = BookingType::factory()->sunbeds()->create();
            $area = Area::factory()->create(['booking_type_id' => $bookingType->id]);

            foreach (range(1, 10) as $number) {
                Resource::factory()->create([
                    'booking_type_id' => $bookingType->id,
                    'area_id' => $area->id,
                    'label' => 'A'.$number,
                    'sort_order' => $number,
                ]);
            }

            return $area;
        });
    }

    #[Test]
    public function one_tap_closes_a_spot_and_another_opens_it(): void
    {
        $resource = $this->asTenant(static fn (): Resource => Resource::query()->firstOrFail());

        $this->patch(route('dashboard.resources.toggle', $resource))->assertRedirect();
        $this->assertSame(OnlineStatus::Closed, $resource->fresh()->online_status);

        $this->patch(route('dashboard.resources.toggle', $resource))->assertRedirect();
        $this->assertSame(OnlineStatus::Open, $resource->fresh()->online_status);
    }

    #[Test]
    public function the_wind_picks_up_and_the_whole_row_closes(): void
    {
        $this->patch(route('dashboard.areas.availability', $this->area), ['status' => 'closed'])
            ->assertRedirect();

        $this->assertSame(0, $this->asTenant(static fn (): int => Resource::query()->bookable()->count()));

        $this->patch(route('dashboard.areas.availability', $this->area), ['status' => 'open']);

        $this->assertSame(10, $this->asTenant(static fn (): int => Resource::query()->bookable()->count()));
    }

    #[Test]
    public function half_the_row_goes_online_and_the_rest_is_kept_for_walk_ins(): void
    {
        $this->patch(route('dashboard.areas.availability', $this->area), ['open_first' => 4])
            ->assertRedirect();

        $open = $this->asTenant(
            static fn () => Resource::query()->bookable()->orderBy('sort_order')->pluck('label')->all()
        );

        $this->assertSame(['A1', 'A2', 'A3', 'A4'], $open);
    }

    #[Test]
    public function a_retired_spot_is_not_bookable_even_when_it_is_open(): void
    {
        $this->asTenant(static function (): void {
            Resource::query()->where('label', 'A1')->update([
                'is_active' => false,
                'online_status' => OnlineStatus::Open->value,
            ]);
        });

        $labels = $this->asTenant(static fn () => Resource::query()->bookable()->pluck('label')->all());

        $this->assertNotContains('A1', $labels);
        $this->assertCount(9, $labels);
    }

    #[Test]
    public function another_venue_cannot_close_these_spots(): void
    {
        $resource = $this->asTenant(static fn (): Resource => Resource::query()->firstOrFail());

        $this->signInAsOwner('Another Place');

        $this->patch(route('dashboard.resources.toggle', $resource))->assertNotFound();
        $this->patch(route('dashboard.areas.availability', $this->area), ['status' => 'closed'])
            ->assertNotFound();
    }
}
