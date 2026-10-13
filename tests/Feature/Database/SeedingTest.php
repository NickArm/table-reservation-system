<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Venue;
use App\Tenancy\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Seeding is how the demo gets built and how a new machine gets usable data,
 * so it is worth a test of its own.
 *
 * It also guards a trap that already caught us once: WithoutModelEvents on the
 * seeder silently disables the hooks that generate a tenant's uuid and stamp
 * tenant_id on new records.
 */
final class SeedingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_seeders_build_a_usable_demo(): void
    {
        $this->seed(DatabaseSeeder::class);

        $tenant = Tenant::firstWhere('slug', 'demo');

        $this->assertNotNull($tenant, 'The demo tenant was not created.');
        $this->assertNotEmpty($tenant->uuid, 'The tenant was created without a uuid.');

        $venue = app(TenantContext::class)->asTenant($tenant, fn () => Venue::query()->first());

        $this->assertNotNull($venue, 'The demo venue was not created.');
        $this->assertSame(
            $tenant->id,
            $venue->tenant_id,
            'The venue was not stamped with its tenant — model events were probably disabled.'
        );

        $this->assertTrue($venue->shouldBeIndexed(), 'The demo profile should be complete enough to index.');

        $this->assertNotNull(User::firstWhere('email', 'owner@demo.test'));
        $this->assertNotNull(User::firstWhere('email', 'staff@demo.test'));

        $admin = User::firstWhere('email', 'admin@tablereserved.test');
        $this->assertNotNull($admin);
        $this->assertTrue($admin->isSuperAdmin());
        $this->assertNotNull($admin->email_verified_at, 'forceCreate should have set the verification date.');
    }

    #[Test]
    public function seeding_twice_rebuilds_the_demo_instead_of_duplicating_it(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, Tenant::where('slug', 'demo')->count());
        $this->assertSame(1, User::where('email', 'owner@demo.test')->count());
    }
}
