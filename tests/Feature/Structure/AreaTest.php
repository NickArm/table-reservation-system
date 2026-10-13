<?php

declare(strict_types=1);

namespace Tests\Feature\Structure;

use App\Enums\Occupancy;
use App\Models\Area;
use App\Models\BookingType;
use App\Models\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

final class AreaTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    private BookingType $bookingType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAsOwner();

        $this->bookingType = $this->asTenant(
            static fn (): BookingType => BookingType::factory()->sunbeds()->create()
        );
    }

    #[Test]
    public function an_area_is_added_from_the_setup_screen(): void
    {
        $this->post(route('dashboard.booking-types.areas.store', $this->bookingType), [
            'name' => 'Front row',
            'description' => 'Feet in the sand.',
        ])->assertRedirect();

        $area = $this->asTenant(static fn (): ?Area => Area::query()->first());

        $this->assertNotNull($area);
        $this->assertSame('Front row', $area->name);
        $this->assertSame($this->bookingType->id, $area->booking_type_id);

        // No override unless one was asked for.
        $this->assertNull($area->occupancy);
    }

    /**
     * The whole point of the override: the front row sells for the day while
     * the garden row turns over at lunchtime (SPEC §6.2.1).
     */
    #[Test]
    public function an_area_can_hold_a_spot_for_a_different_length_of_time(): void
    {
        $this->asTenant(fn () => $this->bookingType->forceFill([
            'occupancy' => Occupancy::WholeDay,
            'turnover_buffer_minutes' => 30,
        ])->save());

        $front = $this->asTenant(fn (): Area => Area::factory()->create([
            'booking_type_id' => $this->bookingType->id,
            'occupancy' => null,
        ]));

        $garden = $this->asTenant(fn (): Area => Area::factory()->create([
            'booking_type_id' => $this->bookingType->id,
            'occupancy' => Occupancy::FixedDuration,
            'max_stay_minutes' => 300,
            'turnover_buffer_minutes' => 45,
        ]));

        $this->assertSame(Occupancy::WholeDay, $front->effectiveOccupancy($this->bookingType));
        $this->assertNull($front->effectiveMaxStayMinutes($this->bookingType));
        $this->assertSame(30, $front->effectiveBufferMinutes($this->bookingType));

        $this->assertSame(Occupancy::FixedDuration, $garden->effectiveOccupancy($this->bookingType));
        $this->assertSame(300, $garden->effectiveMaxStayMinutes($this->bookingType));
        $this->assertSame(45, $garden->effectiveBufferMinutes($this->bookingType));
    }

    #[Test]
    public function the_override_can_be_set_and_cleared_from_the_dashboard(): void
    {
        $area = $this->asTenant(fn (): Area => Area::factory()->create([
            'booking_type_id' => $this->bookingType->id,
        ]));

        $this->patch(route('dashboard.areas.update', $area), [
            'name' => $area->name,
            'occupancy' => Occupancy::FixedDuration->value,
            'max_stay_minutes' => 240,
        ])->assertRedirect();

        $this->assertSame(Occupancy::FixedDuration, $area->fresh()->occupancy);

        $this->patch(route('dashboard.areas.update', $area), [
            'name' => $area->name,
            'occupancy' => '',
        ]);

        $this->assertNull($area->fresh()->occupancy);
    }

    #[Test]
    public function an_area_with_spots_in_it_is_not_deleted_by_accident(): void
    {
        $area = $this->asTenant(fn (): Area => Area::factory()->create([
            'booking_type_id' => $this->bookingType->id,
        ]));

        $this->asTenant(fn () => Resource::factory()->create([
            'booking_type_id' => $this->bookingType->id,
            'area_id' => $area->id,
        ]));

        $this->delete(route('dashboard.areas.destroy', $area))
            ->assertSessionHasErrors('area');

        $this->assertNotNull($area->fresh());
    }

    #[Test]
    public function an_empty_area_can_be_removed(): void
    {
        $area = $this->asTenant(fn (): Area => Area::factory()->create([
            'booking_type_id' => $this->bookingType->id,
        ]));

        $this->delete(route('dashboard.areas.destroy', $area))->assertRedirect();

        $this->assertNull($this->asTenant(static fn (): ?Area => Area::query()->first()));
    }
}
