<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * Verifying the address a new account signed up with.
 *
 * The highest-stakes of the routes nothing covered: `verified` sits on both
 * the dashboard and the admin area, so if this link stops working, no new
 * owner can reach the dashboard at all — and the only people who would find
 * out are the ones who have just paid.
 */
final class EmailVerificationTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    #[Test]
    public function an_unverified_owner_is_held_at_the_notice(): void
    {
        $owner = $this->unverified();

        $this->get(route('dashboard.today'))->assertRedirect(route('verification.notice'));
        $this->get(route('verification.notice'))->assertOk();

        $this->assertFalse($owner->hasVerifiedEmail());
    }

    #[Test]
    public function the_link_from_the_email_lets_them_in(): void
    {
        $owner = $this->unverified();

        $this->get($this->linkFor($owner))
            ->assertRedirect(route('dashboard.today'));

        $this->assertTrue($owner->refresh()->hasVerifiedEmail());

        $this->get(route('dashboard.today'))->assertOk();
    }

    #[Test]
    public function a_tampered_link_is_refused(): void
    {
        $owner = $this->unverified();

        // The signature covers the whole address, so changing the hash by a
        // character is the difference between verifying an account and 403.
        $this->get($this->linkFor($owner).'x')->assertForbidden();

        $this->assertFalse($owner->refresh()->hasVerifiedEmail());
    }

    #[Test]
    public function one_account_cannot_verify_another(): void
    {
        $mine = $this->unverified();
        $theirs = $this->unverified('Another Place');

        // Signed in as the second owner, following the first one's link.
        $this->actingAs($theirs);
        $this->get($this->linkFor($mine))->assertForbidden();

        $this->assertFalse($mine->refresh()->hasVerifiedEmail());
    }

    #[Test]
    public function following_the_link_twice_is_harmless(): void
    {
        $owner = $this->unverified();
        $link = $this->linkFor($owner);

        $this->get($link)->assertRedirect(route('dashboard.today'));
        $this->get($link)->assertRedirect(route('dashboard.today'));

        $this->assertTrue($owner->refresh()->hasVerifiedEmail());
    }

    #[Test]
    public function the_notice_steps_aside_once_the_address_is_verified(): void
    {
        $this->signInAsOwner();

        $this->get(route('verification.notice'))->assertRedirect(route('dashboard.today'));
    }

    #[Test]
    public function a_fresh_link_can_be_asked_for(): void
    {
        Notification::fake();

        $owner = $this->unverified();

        $this->post(route('verification.send'))
            ->assertRedirect()
            ->assertSessionHas('status');

        Notification::assertSentTo($owner, VerifyEmail::class);
    }

    #[Test]
    public function nothing_is_sent_to_an_address_that_is_already_verified(): void
    {
        Notification::fake();

        $this->signInAsOwner();

        $this->post(route('verification.send'))->assertRedirect();

        Notification::assertNothingSent();
    }

    private function unverified(string $venueName = 'Demo Beach Bar'): User
    {
        $owner = $this->signInAsOwner($venueName);

        $owner->forceFill(['email_verified_at' => null])->save();

        return $owner->refresh();
    }

    private function linkFor(User $user): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);
    }
}
