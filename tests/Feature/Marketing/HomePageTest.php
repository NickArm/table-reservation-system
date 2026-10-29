<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The front page (SPEC §33).
 *
 * Two things on it can actually hurt: the demo credentials, which must be a
 * switch rather than a paragraph somebody has to remember to delete, and the
 * robots tag, which is wrong by default on every other page in this product
 * and has to be wrong in the other direction here.
 */
final class HomePageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_leads_with_the_thing_nobody_else_does(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('sunbeds', false)
            ->assertSee('Start free')
            ->assertSee('Sign in');
    }

    #[Test]
    public function this_is_the_one_page_meant_to_be_found(): void
    {
        /*
         * Everything else in this product is noindex by default — a venue's
         * booking page opts in, a dashboard never does. The front page is the
         * exception, and it was inheriting the default.
         */
        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('noindex', false)
            ->assertSee('<meta name="description"', false);
    }

    #[Test]
    public function the_demo_login_is_offered_with_both_roles_and_never_the_admin(): void
    {
        config([
            'demo.enabled' => true,
            'demo.password' => 'password',
            'demo.accounts' => [
                ['email' => 'owner@demo.test', 'role' => 'Owner', 'sees' => 'Everything.'],
                ['email' => 'staff@demo.test', 'role' => 'Staff', 'sees' => 'The floor.'],
            ],
        ]);

        // Both roles, because showing one makes permissions look like a claim
        // rather than a feature. Never the super admin: that account sees
        // every venue on the platform.
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('owner@demo.test')
            ->assertSee('staff@demo.test')
            ->assertSee('Owner')
            ->assertSee('Staff')
            ->assertDontSee('admin@');
    }

    #[Test]
    public function the_demo_credentials_disappear_with_one_setting(): void
    {
        /*
         * The day this has paying customers is the day a login printed on the
         * front page stops being clever. One env var, and a test so that the
         * switch is known to work before anybody needs it.
         */
        config(['demo.enabled' => false]);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('owner@demo.test')
            ->assertDontSee('Sign in to the demo');
    }

    #[Test]
    public function it_promises_the_trial_the_application_actually_gives(): void
    {
        // A front page quoting a different number from the one the signup
        // hands out is the cheapest possible way to start a relationship
        // badly.
        config(['tenancy.trial_days' => 21]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Start free for 21 days');
    }
}
