<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\TenantRole;
use App\Mail\AccountInvitation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsPlatformAdmin;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * The people on an account, from the platform side (SPEC §13.2, §2.3).
 *
 * What is being tested as much as the actions is what is *not* here: there
 * is no way for support to set somebody's password. Every door ends with the
 * owner choosing it, because support that can type a customer's password is
 * support that knows it, and from then on every action from that account is
 * arguable.
 */
final class AccountPeopleTest extends TestCase
{
    use ActsAsPlatformAdmin;
    use ActsAsVenueOwner;
    use RefreshDatabase;

    private Tenant $account;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();

        $this->signInAsOwner('Crystal Seaside');

        $this->account = $this->tenant;

        $this->staff = User::create([
            'name' => 'Floor Staff',
            'email' => 'floor@crystalseaside.test',
            'password' => 'correct-horse-battery',
        ])->refresh();

        $this->account->users()->attach($this->staff, ['role' => TenantRole::Staff->value]);

        $this->signInAsPlatformAdmin();
    }

    #[Test]
    public function the_screen_says_whether_they_have_ever_managed_to_get_in(): void
    {
        $this->get(route('admin.tenants.show', $this->account))
            ->assertOk()
            ->assertSee('The people on it')
            ->assertSee('floor@crystalseaside.test')
            // The first question on every support call, answered before it
            // is asked.
            ->assertSee('Has never signed in');
    }

    #[Test]
    public function a_sign_in_is_remembered(): void
    {
        $this->assertNull($this->staff->last_login_at);

        // The sign-in screen is behind `guest`, and setUp leaves a platform
        // admin signed in. Without this the POST is redirected and the test
        // passes or fails for a reason that has nothing to do with the
        // listener.
        auth()->logout();

        $this->post(route('login'), [
            'email' => 'floor@crystalseaside.test',
            'password' => 'correct-horse-battery',
        ]);

        $this->assertNotNull($this->staff->fresh()?->last_login_at);
    }

    #[Test]
    public function support_can_send_a_password_reset_but_never_set_one(): void
    {
        $this->post(route('admin.tenants.people.reset', [$this->account, $this->staff]))
            ->assertRedirect();

        Notification::assertSentTo($this->staff, ResetPassword::class);

        // The password itself is untouched: the owner picks the new one.
        $this->assertTrue(
            password_verify('correct-horse-battery', (string) $this->staff->fresh()?->password)
        );
    }

    #[Test]
    public function support_can_send_the_verification_again(): void
    {
        $this->staff->forceFill(['email_verified_at' => null])->save();

        $this->post(route('admin.tenants.people.verify', [$this->account, $this->staff]))
            ->assertRedirect();

        Notification::assertSentTo($this->staff, VerifyEmail::class);
    }

    #[Test]
    public function a_fresh_invitation_comes_back_on_the_screen_as_well_as_by_email(): void
    {
        $this->post(route('admin.tenants.people.invite', [$this->account, $this->staff]))
            ->assertRedirect()
            // Because the reason somebody is being re-invited is usually
            // that the email is not arriving.
            ->assertSessionHas('invitation');

        Mail::assertSent(AccountInvitation::class);

        $this->assertTrue($this->staff->fresh()?->hasOpenInvitation());
    }

    #[Test]
    public function a_role_can_be_changed_on_this_account(): void
    {
        $this->patch(route('admin.tenants.people.role', [$this->account, $this->staff]), [
            'role' => TenantRole::Owner->value,
        ])->assertRedirect();

        $this->assertSame(TenantRole::Owner, $this->staff->fresh()?->roleIn($this->account));
    }

    #[Test]
    public function the_last_owner_cannot_be_demoted(): void
    {
        $this->patch(route('admin.tenants.people.role', [$this->account, $this->owner]), [
            'role' => TenantRole::Staff->value,
        ])->assertSessionHas('error');

        /*
         * An account whose only owner is staff is an account where nobody
         * can reach the payment settings or the subscription, and the only
         * way out of it is this screen. Not a trap worth building.
         */
        $this->assertSame(TenantRole::Owner, $this->owner->fresh()?->roleIn($this->account));
    }

    #[Test]
    public function somebody_from_another_account_is_not_found_here(): void
    {
        $stranger = User::create([
            'name' => 'Stranger',
            'email' => 'stranger@elsewhere.test',
            'password' => 'correct-horse-battery',
        ])->refresh();

        /*
         * A user can belong to several accounts, so every one of these is
         * scoped to the tenant in the path. A controller that took a bare
         * user id would eventually change the role on the wrong one.
         */
        $this->post(route('admin.tenants.people.reset', [$this->account, $stranger]))
            ->assertNotFound();

        $this->patch(route('admin.tenants.people.role', [$this->account, $stranger]), [
            'role' => TenantRole::Owner->value,
        ])->assertNotFound();
    }
}
