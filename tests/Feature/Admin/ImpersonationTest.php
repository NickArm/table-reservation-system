<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AuditAction;
use App\Models\AuditEntry;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsPlatformAdmin;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * Being the venue for a while (SPEC §2.1).
 *
 * The most dangerous feature in the application, so it gets the most
 * suspicious tests. Everything done while impersonating looks, in every other
 * table, exactly like the owner doing it — the audit log is the only thing
 * that can ever say otherwise, so both ends of the window have to be written
 * down, and there has to be a way back that always works.
 */
final class ImpersonationTest extends TestCase
{
    use ActsAsPlatformAdmin;
    use ActsAsVenueOwner;
    use RefreshDatabase;

    private Tenant $account;

    private User $venueOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->venueOwner = $this->signInAsOwner('Demo Beach Bar');
        $this->account = $this->tenant;

        $this->signInAsPlatformAdmin();
    }

    #[Test]
    public function it_signs_the_admin_in_as_the_owner_and_says_so_on_every_page(): void
    {
        $this->post(route('admin.tenants.impersonate', $this->account), [
            'reason' => 'Cannot find a booking they rang about.',
        ])->assertRedirect(route('dashboard.today'));

        $this->assertAuthenticatedAs($this->venueOwner);

        // Loud, at the top, on every screen. The one mistake worth designing
        // against is an admin forgetting whose dashboard they are in.
        $this->get(route('dashboard.today'))
            ->assertOk()
            ->assertSee('You are signed in as', false)
            ->assertSee('Back to the platform');
    }

    #[Test]
    public function it_needs_a_reason(): void
    {
        $this->post(route('admin.tenants.impersonate', $this->account), [])
            ->assertSessionHasErrors('reason');

        $this->assertAuthenticatedAs($this->admin);
        $this->assertSame(0, AuditEntry::query()->count());
    }

    #[Test]
    public function both_ends_of_the_window_are_written_down(): void
    {
        $this->post(route('admin.tenants.impersonate', $this->account), [
            'reason' => 'Support call about a missing voucher.',
        ])->assertRedirect();

        $this->post(route('impersonate.stop'))->assertRedirect(route('admin.overview'));

        $entries = AuditEntry::query()->orderBy('id')->get();

        $this->assertSame(AuditAction::ImpersonationStarted, $entries[0]->action);
        $this->assertSame('Support call about a missing voucher.', $entries[0]->reason);
        $this->assertSame($this->admin->name, $entries[0]->actor_name);
        $this->assertSame('Demo Beach Bar', $entries[0]->affected_tenant_name);

        // Without the closing line there is no way to say how long somebody
        // was inside an account, which is the question that gets asked.
        $this->assertSame(AuditAction::ImpersonationEnded, $entries[1]->action);

        $this->assertAuthenticatedAs($this->admin);
    }

    #[Test]
    public function the_platform_screens_are_closed_while_impersonating(): void
    {
        $this->post(route('admin.tenants.impersonate', $this->account), [
            'reason' => 'Looking at their calendar.',
        ])->assertRedirect();

        /*
         * One identity at a time. A session that was both an admin and a
         * venue would mean a screen reading across accounts with a tenant
         * bound, which is the most confusing possible state to debug — and
         * the route that ends it is deliberately outside the admin group so
         * there is still a way out.
         */
        $this->get(route('admin.overview'))->assertNotFound();
        $this->get(route('admin.tenants.index'))->assertNotFound();

        $this->post(route('impersonate.stop'))->assertRedirect(route('admin.overview'));
        $this->get(route('admin.overview'))->assertOk();
    }

    #[Test]
    public function a_platform_admin_cannot_be_impersonated(): void
    {
        $other = $this->signInAsPlatformAdmin('Second Admin');

        // Made the owner of an account, which is not a state the application
        // creates but is one a database fix could.
        $this->account->users()->attach($other, ['role' => 'owner']);
        $this->account->users()->detach($this->venueOwner);

        $this->signInAsPlatformAdmin('First Admin');

        $this->post(route('admin.tenants.impersonate', $this->account), [
            'reason' => 'Trying it on.',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertAuthenticatedAs($this->admin);
    }

    #[Test]
    public function a_venue_owner_cannot_impersonate_anybody(): void
    {
        $this->actingAs($this->venueOwner);

        $this->post(route('admin.tenants.impersonate', $this->account), [
            'reason' => 'Trying it on.',
        ])->assertNotFound();
    }

    #[Test]
    public function stopping_when_nothing_is_being_impersonated_does_nothing(): void
    {
        $this->actingAs($this->venueOwner);

        // An owner who finds the address and posts to it must not end up
        // somewhere they should not be, and must not be signed out either.
        $this->post(route('impersonate.stop'))->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($this->venueOwner);

        $this->assertSame(0, AuditEntry::query()->count());
    }
}
