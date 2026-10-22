<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsPlatformAdmin;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * Who may reach the platform screens (SPEC §2.1, §13.2).
 *
 * The first test in this directory on purpose. Everything else in the admin
 * area reads across every account in the database, so the only thing standing
 * between a venue owner and every other venue's data is this middleware.
 */
final class AccessTest extends TestCase
{
    use ActsAsPlatformAdmin;
    use ActsAsVenueOwner;
    use RefreshDatabase;

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function everyAdminRoute(): array
    {
        return [
            ['GET', 'admin.overview'],
            ['GET', 'admin.tenants.index'],
            ['GET', 'admin.plans.index'],
            ['GET', 'admin.plans.create'],
            ['GET', 'admin.audit'],
        ];
    }

    #[Test]
    public function a_venue_owner_is_told_the_admin_area_does_not_exist(): void
    {
        $this->signInAsOwner();

        foreach (self::everyAdminRoute() as [$method, $name]) {
            // 404, not 403. Confirming that an admin area exists is an
            // invitation, and there is nothing here an owner needs.
            $this->call($method, route($name))->assertNotFound();
        }

        $this->get(route('admin.tenants.show', $this->tenant))->assertNotFound();
    }

    #[Test]
    public function a_venue_owner_cannot_reach_the_screens_that_change_an_account(): void
    {
        $this->signInAsOwner();

        $this->post(route('admin.tenants.suspend', $this->tenant), ['reason' => 'because'])
            ->assertNotFound();
        $this->post(route('admin.tenants.extend', $this->tenant), [
            'until' => now()->addMonth()->format('Y-m-d'),
            'reason' => 'because',
        ])->assertNotFound();
        $this->post(route('admin.tenants.impersonate', $this->tenant), ['reason' => 'because'])
            ->assertNotFound();
        $this->post(route('admin.plans.store'), [])->assertNotFound();
    }

    #[Test]
    public function a_stranger_is_sent_to_sign_in(): void
    {
        // Not 404: the auth middleware runs first, and a redirect to the
        // login page says nothing about what is behind it.
        $this->get(route('admin.overview'))->assertRedirect(route('login'));
    }

    #[Test]
    public function a_platform_admin_sees_every_screen(): void
    {
        $this->signInAsOwner('Demo Beach Bar');
        Plan::factory()->create();

        $tenant = $this->tenant;

        $this->signInAsPlatformAdmin();

        foreach (self::everyAdminRoute() as [$method, $name]) {
            $this->call($method, route($name))->assertOk();
        }

        $this->get(route('admin.tenants.show', $tenant))->assertOk();
    }

    #[Test]
    public function a_platform_admin_signing_in_lands_somewhere_they_can_actually_be(): void
    {
        $admin = $this->signInAsPlatformAdmin();

        auth()->logout();

        /*
         * A platform admin belongs to no venue, so the usual landing page
         * refuses them: `IdentifyTenantFromUser` has nothing to bind and
         * answers 403. Without this one branch in the login controller the
         * whole admin area is unreachable by the only person who may use it.
         */
        $this->post(route('login'), [
            'email' => $admin->email,
            'password' => 'correct-horse-battery',
        ])->assertRedirect(route('admin.overview'));

        $this->get(route('admin.overview'))->assertOk();
    }

    #[Test]
    public function the_admin_screens_read_across_accounts_without_a_tenant_bound(): void
    {
        $this->signInAsOwner('First Place');
        $first = $this->tenant;

        $this->signInAsOwner('Second Place');
        $second = $this->tenant;

        $this->signInAsPlatformAdmin();

        /*
         * The point of the bypass. Without it `TenantScope` refuses any
         * tenant-scoped query with nothing bound — correctly everywhere else,
         * and useless on a screen whose whole job is to list everybody.
         */
        $this->get(route('admin.tenants.index'))
            ->assertOk()
            ->assertSee($first->name)
            ->assertSee($second->name);
    }
}
