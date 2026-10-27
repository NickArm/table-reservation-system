<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * Forgetting a password.
 *
 * Written because nothing covered it: four routes, no test, and the one
 * occasion they run is an owner locked out in the middle of August with
 * fourteen bookings that day. A reset that silently does nothing is a support
 * call that cannot be answered quickly, and this flow goes through our own
 * middleware — `guest`, and a user whose tenant is resolved from the account —
 * so Laravel's own tests prove nothing about it here.
 */
final class PasswordResetTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    #[Test]
    public function the_two_pages_load_for_somebody_who_is_not_signed_in(): void
    {
        $this->get(route('password.request'))->assertOk();
        $this->get(route('password.reset', ['token' => 'anything']))->assertOk();
    }

    #[Test]
    public function a_whole_reset_works_end_to_end(): void
    {
        Notification::fake();

        $owner = $this->owner();

        $this->post(route('password.email'), ['email' => $owner->email])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $token = null;

        Notification::assertSentTo($owner, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $owner->email,
            'password' => 'a-whole-new-password',
            'password_confirmation' => 'a-whole-new-password',
        ])
            ->assertRedirect(route('login'))
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('a-whole-new-password', (string) $owner->refresh()->password));

        // And the new one actually gets them in, which is the only part of
        // this the owner cares about.
        $this->post(route('login'), [
            'email' => $owner->email,
            'password' => 'a-whole-new-password',
        ])->assertRedirect(route('dashboard.today'));
    }

    #[Test]
    public function an_address_with_no_account_is_answered_exactly_the_same_way(): void
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => 'nobody@example.test'])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        // Whether an address has an account here is not something a stranger
        // should be able to probe one address at a time.
        Notification::assertNothingSent();
    }

    #[Test]
    public function a_wrong_token_is_refused(): void
    {
        $owner = $this->owner();

        $this->post(route('password.update'), [
            'token' => 'not-the-token-we-sent',
            'email' => $owner->email,
            'password' => 'a-whole-new-password',
            'password_confirmation' => 'a-whole-new-password',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('correct-horse-battery', (string) $owner->refresh()->password));
    }

    #[Test]
    public function a_token_belonging_to_one_account_does_not_reset_another(): void
    {
        Notification::fake();

        $mine = $this->owner();
        $theirs = $this->owner('Another Place');

        $this->post(route('password.email'), ['email' => $mine->email]);

        $token = null;
        Notification::assertSentTo($mine, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $theirs->email,
            'password' => 'a-whole-new-password',
            'password_confirmation' => 'a-whole-new-password',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('correct-horse-battery', (string) $theirs->refresh()->password));
    }

    #[Test]
    public function the_new_password_has_to_be_typed_twice_the_same(): void
    {
        $owner = $this->owner();

        $this->post(route('password.update'), [
            'token' => 'whatever',
            'email' => $owner->email,
            'password' => 'a-whole-new-password',
            'password_confirmation' => 'something-else-entirely',
        ])->assertSessionHasErrors('password');
    }

    #[Test]
    public function somebody_already_signed_in_is_sent_away_from_these_pages(): void
    {
        $this->signInAsOwner();

        $this->get(route('password.request'))->assertRedirect();
    }

    /**
     * An owner who is then signed out again: these pages are for strangers,
     * and `signInAsOwner` leaves a session behind.
     */
    private function owner(string $venueName = 'Demo Beach Bar'): User
    {
        $owner = $this->signInAsOwner($venueName);

        auth()->logout();
        session()->flush();

        return $owner;
    }
}
