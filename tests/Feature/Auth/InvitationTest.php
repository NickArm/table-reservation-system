<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Actions\Platform\InviteUser;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * The door an invited owner comes through (SPEC §4.1).
 *
 * The whole reason this exists rather than borrowing the password reset is
 * the lifetime: a reset token is minted to last an hour, which is right for
 * "I forgot" and useless for an invitation sent during a telephone call and
 * read the next morning. So most of what is tested here is the lifetime and
 * what happens at both ends of it.
 */
final class InvitationTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    private Tenant $account;

    private User $invited;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-04-01 10:00', 'Europe/Athens'));

        Mail::fake();

        $this->signInAsOwner('Crystal Seaside');

        $this->account = $this->tenant;
        $this->invited = $this->owner;

        // Nobody signed in: an invited person has no session yet.
        auth()->logout();
    }

    #[Test]
    public function it_lets_them_choose_a_password_and_takes_them_straight_in(): void
    {
        $token = $this->invite();

        $this->get(route('invitation.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('Choose your password')
            // The address is on the page, not in a field they have to retype.
            ->assertSee($this->invited->email);

        $this->post(route('invitation.store', ['token' => $token]), [
            'password' => 'correct-horse-battery-29',
            'password_confirmation' => 'correct-horse-battery-29',
        ])->assertRedirect(route('dashboard.today'));

        $this->assertAuthenticatedAs($this->invited->fresh());

        $this->assertTrue(Hash::check(
            'correct-horse-battery-29',
            (string) $this->invited->fresh()?->password,
        ));
    }

    #[Test]
    public function arriving_through_the_link_verifies_the_address(): void
    {
        $this->invited->forceFill(['email_verified_at' => null])->save();

        $token = $this->invite();

        $this->post(route('invitation.store', ['token' => $token]), [
            'password' => 'correct-horse-battery-29',
            'password_confirmation' => 'correct-horse-battery-29',
        ]);

        /*
         * Clicking a link that was emailed to the address *is* proof of the
         * address. Sending a second link to the same inbox to prove the same
         * thing loses a third of them and proves nothing extra.
         */
        $this->assertTrue($this->invited->fresh()?->hasVerifiedEmail());
    }

    #[Test]
    public function the_link_works_once(): void
    {
        $token = $this->invite();

        $this->post(route('invitation.store', ['token' => $token]), [
            'password' => 'correct-horse-battery-29',
            'password_confirmation' => 'correct-horse-battery-29',
        ]);

        auth()->logout();

        $this->assertNull($this->invited->fresh()?->invitation_token);

        $this->get(route('invitation.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('That link has run out');
    }

    #[Test]
    public function it_runs_out_after_the_configured_days(): void
    {
        config(['tenancy.invitation_days' => 7]);

        $token = $this->invite();

        $this->travel(6)->days();

        $this->get(route('invitation.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('Choose your password');

        $this->travel(2)->days();

        $this->get(route('invitation.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('That link has run out');

        // And a dead link cannot be spent either, which is the half that
        // actually matters.
        $this->post(route('invitation.store', ['token' => $token]), [
            'password' => 'another-password-42',
            'password_confirmation' => 'another-password-42',
        ])->assertRedirect();

        $this->assertGuest();
    }

    #[Test]
    public function a_token_nobody_has_ever_held_is_answered_the_same_way(): void
    {
        /*
         * The same page as an expired one, and never "that has already been
         * used". Whoever is holding a dead link learns nothing about whether
         * the address exists.
         */
        $this->get(route('invitation.show', ['token' => str_repeat('a', 64)]))
            ->assertOk()
            ->assertSee('That link has run out');
    }

    #[Test]
    public function a_fresh_invitation_kills_the_previous_link(): void
    {
        $first = $this->invite();
        $second = $this->invite();

        $this->assertNotSame($first, $second);

        $this->get(route('invitation.show', ['token' => $first]))
            ->assertSee('That link has run out');

        $this->get(route('invitation.show', ['token' => $second]))
            ->assertSee('Choose your password');
    }

    #[Test]
    public function it_holds_the_password_to_the_same_bar_as_everywhere_else(): void
    {
        $token = $this->invite();

        $this->post(route('invitation.store', ['token' => $token]), [
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertGuest();
    }

    private function invite(): string
    {
        app(InviteUser::class)->handle($this->invited, $this->account);

        return (string) $this->invited->fresh()?->invitation_token;
    }
}
