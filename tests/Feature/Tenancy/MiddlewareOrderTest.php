<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\ReservationHold;
use App\Models\Resource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\Concerns\BuildsVenueStructure;
use Tests\TestCase;

/**
 * Route model binding has to see the tenant.
 *
 * Laravel runs the `web` group's middleware before a route's own, so without
 * an explicit priority SubstituteBindings resolves tenant-scoped models with
 * nothing bound — and TenantScope refuses, correctly and uselessly. This has
 * broken twice; it is tested so it cannot break a third time.
 */
final class MiddlewareOrderTest extends TestCase
{
    use ActsAsVenueOwner;
    use BuildsVenueStructure;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00', 'Europe/Athens'));
    }

    #[Test]
    public function a_dashboard_url_with_a_model_in_it_resolves(): void
    {
        $this->signInAsOwner();

        $bookingType = $this->asTenant(fn () => $this->sunbedType());

        $this->get(route('dashboard.booking-types.show', $bookingType))->assertOk();
    }

    #[Test]
    public function a_public_url_with_a_scoped_model_in_it_resolves(): void
    {
        $this->signInAsOwner();
        $tenant = $this->tenant;

        $hold = $this->asTenant(function (): ReservationHold {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');
            $resource = $this->resourcesIn($area, 'A', 1)->first();

            return ReservationHold::factory()->forResource($resource)->create([
                'session_token' => 'not-this-browser',
            ]);
        });

        // 403 rather than 500: the hold resolved, it simply is not ours.
        $this->get(route('public.book', [$tenant, $hold]))->assertForbidden();
    }

    #[Test]
    public function one_venues_url_cannot_carry_another_venues_model(): void
    {
        $this->signInAsOwner('First Place');
        $firstTenant = $this->tenant;

        $theirHold = $this->asTenant(function (): ReservationHold {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');

            return ReservationHold::factory()
                ->forResource($this->resourcesIn($area, 'A', 1)->first())
                ->create();
        });

        $this->signInAsOwner('Second Place');
        $secondTenant = $this->tenant;

        // The second venue's path with the first venue's hold id.
        $this->get(route('public.book', [$secondTenant, $theirHold]))->assertNotFound();

        $this->assertNotNull($theirHold->id);
        $this->assertNotSame($firstTenant->id, $secondTenant->id);
        $this->assertSame(0, Resource::query()->acrossTenants()->where('id', 0)->count());
    }
}
