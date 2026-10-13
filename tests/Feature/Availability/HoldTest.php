<?php

declare(strict_types=1);

namespace Tests\Feature\Availability;

use App\Actions\Reservations\HoldResource;
use App\Actions\Reservations\ReleaseExpiredHolds;
use App\Availability\Exceptions\SpotNotAvailable;
use App\Models\BookingType;
use App\Models\ReservationHold;
use App\Models\Resource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\Concerns\BuildsVenueStructure;
use Tests\TestCase;

/**
 * Holding a spot while somebody pays (SPEC §6.4, §24.1).
 *
 * The rules that stop two families arriving for one table: a hold blocks
 * everyone else, a lapsed hold blocks nobody, and the same spot can never be
 * held twice.
 */
final class HoldTest extends TestCase
{
    use ActsAsVenueOwner;
    use BuildsVenueStructure;
    use RefreshDatabase;

    private const DATE = '2026-07-04';

    private BookingType $bookingType;

    private Resource $resource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00', 'Europe/Athens'));

        $this->signInAsOwner();

        [$this->bookingType, $this->resource] = $this->asTenant(function (): array {
            $type = $this->sunbedType(['hold_duration_minutes' => 15]);
            $area = $this->areaIn($type, 'Front row');

            return [$type, $this->resourcesIn($area, 'A', 1)->first()];
        });
    }

    #[Test]
    public function a_hold_takes_the_spot_off_the_board(): void
    {
        $this->assertNotSame([], $this->asTenant(fn () => $this->engine()->times($this->bookingType, self::DATE, 2)));

        $hold = $this->asTenant(fn () => $this->hold('session-one'));

        $this->assertSame($this->resource->id, $hold->resource_id);
        $this->assertSame('19:00', $hold->ends_at->setTimezone('Europe/Athens')->format('H:i'));
        $this->assertSame(15 * 60, $hold->secondsLeft());

        $this->assertSame([], $this->asTenant(fn () => $this->engine()->times($this->bookingType, self::DATE, 2)));
    }

    #[Test]
    public function the_same_spot_cannot_be_held_twice(): void
    {
        $this->asTenant(fn () => $this->hold('session-one'));

        $this->expectException(SpotNotAvailable::class);

        $this->asTenant(fn () => $this->hold('session-two'));
    }

    #[Test]
    public function a_hold_that_has_run_out_blocks_nobody(): void
    {
        $this->asTenant(fn () => $this->hold('session-one'));

        $this->travel(16)->minutes();

        // Still in the table — the cleanup job has not run yet — and still
        // not in anybody's way.
        $this->assertSame(1, $this->asTenant(fn (): int => ReservationHold::query()->count()));
        $this->assertNotSame([], $this->asTenant(fn () => $this->engine()->times($this->bookingType, self::DATE, 2)));

        $this->asTenant(fn () => $this->hold('session-two'));

        $this->assertSame(1, $this->asTenant(fn (): int => ReservationHold::query()->count()));
    }

    #[Test]
    public function releasing_a_hold_puts_the_spot_back(): void
    {
        $hold = $this->asTenant(fn () => $this->hold('session-one'));

        $this->asTenant(fn () => app(HoldResource::class)->release($hold));

        $this->assertNotSame([], $this->asTenant(fn () => $this->engine()->times($this->bookingType, self::DATE, 2)));
    }

    #[Test]
    public function the_cleanup_job_only_takes_what_has_expired(): void
    {
        $live = $this->asTenant(fn () => ReservationHold::factory()
            ->forResource($this->resource)
            ->create(['starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(2)]));

        $dead = $this->asTenant(fn () => ReservationHold::factory()
            ->forResource($this->resource)
            ->expired()
            ->create(['starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHours(2)]));

        $released = app(ReleaseExpiredHolds::class)->handle();

        $this->assertSame(1, $released);
        $this->assertNotNull($this->asTenant(fn () => ReservationHold::query()->find($live->id)));
        $this->assertNull($this->asTenant(fn () => ReservationHold::query()->find($dead->id)));
    }

    #[Test]
    public function the_console_command_reports_what_it_released(): void
    {
        $this->asTenant(fn () => ReservationHold::factory()
            ->forResource($this->resource)
            ->expired()
            ->create());

        $this->artisan('holds:release')
            ->expectsOutputToContain('Released 1 expired hold.')
            ->assertSuccessful();
    }

    #[Test]
    public function a_time_outside_opening_hours_is_never_held(): void
    {
        $this->expectException(SpotNotAvailable::class);

        $this->asTenant(fn () => app(HoldResource::class)->handle(
            resource: $this->resource,
            startsAt: CarbonImmutable::parse(self::DATE.' 03:00', 'Europe/Athens')->utc(),
            partySize: 2,
            sessionToken: 'session-one',
        ));
    }

    #[Test]
    public function another_venue_cannot_hold_this_venues_spot(): void
    {
        $theirs = $this->resource;

        $this->signInAsOwner('Another Place');

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->asTenant(fn () => app(HoldResource::class)->handle(
            resource: $theirs,
            startsAt: CarbonImmutable::parse(self::DATE.' 11:00', 'Europe/Athens')->utc(),
            partySize: 2,
            sessionToken: 'session-one',
        ));
    }

    private function hold(string $token): ReservationHold
    {
        return app(HoldResource::class)->handle(
            resource: $this->resource,
            startsAt: CarbonImmutable::parse(self::DATE.' 11:00', 'Europe/Athens')->utc(),
            partySize: 2,
            sessionToken: $token,
        );
    }
}
