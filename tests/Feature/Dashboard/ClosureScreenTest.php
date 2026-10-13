<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\Area;
use App\Models\BookingClosure;
use App\Models\BookingType;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\Concerns\BuildsVenueStructure;
use Tests\TestCase;

/**
 * Closing dates from the dashboard (SPEC §6.2, §24.5).
 */
final class ClosureScreenTest extends TestCase
{
    use ActsAsVenueOwner;
    use BuildsVenueStructure;
    use RefreshDatabase;

    private BookingType $bookingType;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00', 'Europe/Athens'));

        $this->signInAsOwner();

        [$this->bookingType, $this->area] = $this->asTenant(function (): array {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');
            $this->resourcesIn($area, 'A', 3);

            return [$type, $area];
        });
    }

    #[Test]
    public function a_date_is_closed_from_the_setup_screen(): void
    {
        $this->post(route('dashboard.closures.store'), [
            'target' => 'booking_type:'.$this->bookingType->id,
            'date_from' => '2026-07-04',
            'reason' => 'Private event',
        ])->assertRedirect();

        $closure = $this->asTenant(static fn (): ?BookingClosure => BookingClosure::query()->first());

        $this->assertNotNull($closure);
        $this->assertSame('Private event', $closure->reason);
        $this->assertTrue($closure->isAllDay());

        $this->get(route('dashboard.booking-types.show', $this->bookingType))
            ->assertSee('Private event')
            ->assertSee('Reopen');
    }

    #[Test]
    public function one_row_can_be_closed_on_its_own(): void
    {
        $this->post(route('dashboard.closures.store'), [
            'target' => 'area:'.$this->area->id,
            'date_from' => '2026-07-04',
            'date_to' => '2026-07-06',
            'reason' => 'Tree work',
        ])->assertRedirect();

        $this->get(route('dashboard.booking-types.show', $this->bookingType))
            ->assertSee('Front row')
            ->assertSee('Tree work');
    }

    #[Test]
    public function the_owner_is_told_how_many_guests_are_affected_but_never_blocked(): void
    {
        $resource = $this->asTenant(
            fn () => \App\Models\Resource::query()->where('label', 'A1')->firstOrFail()
        );

        $this->asTenant(fn () => Reservation::factory()
            ->forResource($resource)
            ->at(
                CarbonImmutable::parse('2026-07-04 10:00', 'Europe/Athens')->utc(),
                CarbonImmutable::parse('2026-07-04 19:00', 'Europe/Athens')->utc(),
            )
            ->create());

        $this->post(route('dashboard.closures.store'), [
            'target' => 'booking_type:'.$this->bookingType->id,
            'date_from' => '2026-07-04',
            'reason' => 'Storm',
        ])
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, '1 existing reservation'));

        $this->assertSame(1, $this->asTenant(static fn (): int => Reservation::query()->count()));
    }

    #[Test]
    public function reopening_removes_the_closure(): void
    {
        $this->post(route('dashboard.closures.store'), [
            'target' => 'booking_type:'.$this->bookingType->id,
            'date_from' => '2026-07-04',
        ]);

        $closure = $this->asTenant(static fn (): BookingClosure => BookingClosure::query()->firstOrFail());

        $this->delete(route('dashboard.closures.destroy', $closure))->assertRedirect();

        $this->assertSame(0, $this->asTenant(static fn (): int => BookingClosure::query()->count()));
    }

    #[Test]
    public function the_dates_have_to_make_sense(): void
    {
        $this->post(route('dashboard.closures.store'), [
            'target' => 'booking_type:'.$this->bookingType->id,
            'date_from' => '2026-07-10',
            'date_to' => '2026-07-04',
        ])->assertSessionHasErrors('date_to');

        $this->post(route('dashboard.closures.store'), [
            'target' => 'booking_type:'.$this->bookingType->id,
            'date_from' => '2026-07-04',
            'time_from' => '10:00',
        ])->assertSessionHasErrors('time_to');

        $this->post(route('dashboard.closures.store'), [
            'target' => 'nonsense',
            'date_from' => '2026-07-04',
        ])->assertSessionHasErrors('target');
    }

    #[Test]
    public function another_venue_cannot_close_this_ones_dates(): void
    {
        $mine = $this->bookingType;

        $this->signInAsOwner('Another Place');

        $this->post(route('dashboard.closures.store'), [
            'target' => 'booking_type:'.$mine->id,
            'date_from' => '2026-07-04',
        ])->assertNotFound();
    }
}
