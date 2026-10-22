<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Venue;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_registration_page_loads(): void
    {
        $this->get(route('register'))->assertOk();
    }

    #[Test]
    public function signing_up_creates_an_account_on_trial_with_its_venue(): void
    {
        $response = $this->post(route('register'), [
            'venue_name' => 'Crystal Seaside',
            'name' => 'Nick',
            'email' => 'nick@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'terms' => '1',
        ]);

        $response->assertRedirect(route('verification.notice'));
        $this->assertAuthenticated();

        $tenant = Tenant::firstWhere('slug', 'crystal-seaside');

        $this->assertNotNull($tenant);
        $this->assertSame(TenantStatus::Trialing, $tenant->status);
        $this->assertNotNull($tenant->trial_ends_at);
        $this->assertSame(
            config('tenancy.trial_days'),
            (int) round(now()->diffInDays($tenant->trial_ends_at, false)),
        );

        $user = User::firstWhere('email', 'nick@example.com');
        $this->assertNotNull($user);
        $this->assertSame(TenantRole::Owner, $user->roleIn($tenant));

        // One venue, created for them, never shown as a choice (SPEC §1.3).
        $venues = app(TenantContext::class)->asTenant($tenant, fn () => Venue::all());

        $this->assertCount(1, $venues);
        $this->assertSame('Crystal Seaside', $venues->first()->name);
    }

    #[Test]
    public function slugs_are_never_reused_even_after_an_account_is_deleted(): void
    {
        Tenant::factory()->create(['slug' => 'crystal-seaside'])->delete();

        $this->post(route('register'), [
            'venue_name' => 'Crystal Seaside',
            'name' => 'Nick',
            'email' => 'nick@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'terms' => '1',
        ]);

        // An old link must never resolve to a different venue (SPEC §29.3).
        $this->assertNotNull(Tenant::firstWhere('slug', 'crystal-seaside-2'));
    }

    #[Test]
    public function reserved_words_cannot_become_a_slug(): void
    {
        $this->post(route('register'), [
            'venue_name' => 'Admin',
            'name' => 'Nick',
            'email' => 'nick@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'terms' => '1',
        ]);

        $this->assertNull(Tenant::firstWhere('slug', 'admin'));
        $this->assertNotNull(Tenant::firstWhere('slug', 'venue'));
    }

    #[Test]
    public function the_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post(route('register'), [
            'venue_name' => 'Somewhere',
            'name' => 'Nick',
            'email' => 'taken@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'terms' => '1',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
