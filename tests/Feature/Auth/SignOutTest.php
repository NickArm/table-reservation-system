<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * Signing out.
 *
 * Four lines of controller and no test, which is exactly how a shared
 * computer behind a bar ends up still signed in: the session has to be
 * invalidated, not merely forgotten, and the token regenerated with it.
 */
final class SignOutTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    #[Test]
    public function signing_out_ends_the_session_and_sends_them_to_the_front_page(): void
    {
        $this->signInAsOwner();

        $this->post(route('logout'))->assertRedirect(route('home'));

        $this->assertGuest();

        // And the dashboard is shut again, which is the part that matters on a
        // laptop somebody else uses next.
        $this->get(route('dashboard.today'))->assertRedirect(route('login'));
    }

    #[Test]
    public function the_session_identifier_changes(): void
    {
        $this->signInAsOwner();

        $this->get(route('dashboard.today'))->assertOk();
        $before = session()->getId();

        $this->post(route('logout'));

        // A session that is only emptied can be handed back a stolen cookie.
        $this->assertNotSame($before, session()->getId());
    }

    #[Test]
    public function a_stranger_cannot_sign_somebody_else_out(): void
    {
        $this->post(route('logout'))->assertRedirect(route('login'));
    }
}
