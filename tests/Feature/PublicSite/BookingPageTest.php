<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSite;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\Venue;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BookingPageTest extends TestCase
{
    use RefreshDatabase;

    private function venueFor(Tenant $tenant, bool $complete = true): Venue
    {
        return app(TenantContext::class)->asTenant($tenant, function () use ($complete): Venue {
            $factory = Venue::factory();

            return ($complete ? $factory->complete() : $factory)->create([
                'name' => 'Crystal Seaside',
            ]);
        });
    }

    #[Test]
    public function the_page_is_reachable_by_slug(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'crystal-seaside']);
        $this->venueFor($tenant);

        $this->get('/reservation/crystal-seaside')
            ->assertOk()
            ->assertSee('Crystal Seaside');
    }

    #[Test]
    public function an_unknown_slug_is_a_404(): void
    {
        $this->get('/reservation/nobody-here')->assertNotFound();
    }

    #[Test]
    public function a_deleted_account_does_not_resolve(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'gone']);
        $this->venueFor($tenant);
        $tenant->delete();

        $this->get('/reservation/gone')->assertNotFound();
    }

    #[Test]
    public function a_complete_profile_is_indexable_and_carries_structured_data(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'crystal-seaside']);
        $this->venueFor($tenant);

        $response = $this->get('/reservation/crystal-seaside');

        $response->assertOk();
        $response->assertDontSee('noindex', escape: false);
        $response->assertSee('application/ld+json', escape: false);
        $response->assertSee('ReserveAction', escape: false);
        $response->assertSee('"@type":"BarOrPub"', escape: false);
    }

    #[Test]
    public function a_thin_profile_is_kept_out_of_search_results(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'empty-place']);
        $this->venueFor($tenant, complete: false);

        // A near-empty page would be judged thin, and the penalty lands on the
        // whole domain — so it stays out until it is filled in (SPEC §33.1).
        $this->get('/reservation/empty-place')
            ->assertOk()
            ->assertSee('noindex', escape: false);
    }

    #[Test]
    public function an_expired_account_stops_taking_bookings_without_an_error_page(): void
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'lapsed',
            'status' => TenantStatus::Expired,
        ]);
        $this->venueFor($tenant);

        $this->get('/reservation/lapsed')->assertOk();
    }
}
