<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AuditAction;
use App\Enums\BillingProvider;
use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Mail\AccountInvitation;
use App\Models\AuditEntry;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Venue;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsPlatformAdmin;
use Tests\TestCase;

/**
 * Opening an account for somebody (SPEC §4.1).
 *
 * The way most venues will actually start: a telephone call, a price agreed,
 * and an address to write to. The tests are mostly about the three things
 * that separate a hand-made account from a self-service one — it is paid
 * rather than on trial, it is billed by a person rather than by a card, and
 * nobody ever types its owner's password.
 */
final class AccountsByHandTest extends TestCase
{
    use ActsAsPlatformAdmin;
    use RefreshDatabase;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-04-01 10:00', 'Europe/Athens'));

        Mail::fake();

        $this->plan = Plan::factory()->create([
            'name' => 'Monthly',
            'price_cents' => 7000,
            'is_active' => true,
        ]);

        $this->signInAsPlatformAdmin();
    }

    #[Test]
    public function it_opens_a_working_account_in_one_submission(): void
    {
        $this->post(route('admin.tenants.store'), [
            'venue_name' => 'Crystal Seaside',
            'owner_name' => 'Maria Stamati',
            'owner_email' => 'maria@crystalseaside.test',
            'plan_id' => $this->plan->id,
            'send_invitation' => '1',
        ])->assertRedirect();

        $tenant = Tenant::query()->where('name', 'Crystal Seaside')->firstOrFail();

        $this->assertSame('crystal-seaside', $tenant->slug);

        // The same three rows a sign-up produces, which is the point of
        // going through the same action.
        $owner = User::query()->where('email', 'maria@crystalseaside.test')->firstOrFail();

        $this->assertTrue($owner->belongsToTenant($tenant));
        $this->assertSame(TenantRole::Owner, $owner->roleIn($tenant));

        $venue = app(TenantContext::class)->asTenant(
            $tenant,
            static fn (): ?Venue => Venue::query()->first(),
        );

        $this->assertNotNull($venue);
        $this->assertSame('Crystal Seaside', $venue->name);
    }

    #[Test]
    public function it_starts_paid_rather_than_on_trial(): void
    {
        $this->post(route('admin.tenants.store'), $this->form());

        $tenant = Tenant::query()->where('name', 'Crystal Seaside')->firstOrFail();

        /*
         * The one that matters. Left on trial, the nightly sweep would
         * expire an account that has already been paid for — and the venue
         * would find out when the booking page stopped.
         */
        $this->assertSame(TenantStatus::Active, $tenant->status);

        $subscription = Subscription::query()->acrossTenants()
            ->where('tenant_id', $tenant->getKey())
            ->firstOrFail();

        $this->assertSame(TenantStatus::Active, $subscription->status);
        $this->assertSame(7000, (int) $subscription->price_cents);
        $this->assertTrue($subscription->current_period_end?->isFuture());
    }

    #[Test]
    public function it_is_billed_by_hand_and_will_not_renew_itself(): void
    {
        $this->post(route('admin.tenants.store'), $this->form());

        $subscription = Subscription::query()->acrossTenants()->firstOrFail();

        $this->assertSame(BillingProvider::Manual, $subscription->provider);

        // The single value that keeps the dunning emails away from somebody
        // whose card we have never had.
        $this->assertFalse($subscription->willRenew());
        $this->assertNull($subscription->provider_subscription_id);
    }

    #[Test]
    public function it_invites_the_owner_instead_of_sending_them_a_password(): void
    {
        $response = $this->post(route('admin.tenants.store'), $this->form());

        $owner = User::query()->where('email', 'maria@crystalseaside.test')->firstOrFail();

        $this->assertTrue($owner->hasOpenInvitation());

        Mail::assertSent(AccountInvitation::class, function (AccountInvitation $mail) use ($owner): bool {
            // The link, and nothing that could be read as a password.
            $this->assertStringContainsString((string) $owner->invitation_token, $mail->url);

            return $mail->hasTo('maria@crystalseaside.test');
        });

        /*
         * And the same link on the screen, because half of these are opened
         * during a telephone call and the email is not always what the
         * customer reads.
         */
        $response->assertSessionHas('invitation');
    }

    #[Test]
    public function it_can_open_an_account_without_emailing_anybody(): void
    {
        $this->post(route('admin.tenants.store'), [...$this->form(), 'send_invitation' => null])
            ->assertSessionHas('invitation');

        Mail::assertNothingSent();

        // The link still exists — it is simply being delivered by a person.
        $owner = User::query()->where('email', 'maria@crystalseaside.test')->firstOrFail();
        $this->assertTrue($owner->hasOpenInvitation());
    }

    #[Test]
    public function it_writes_who_opened_it_into_the_log(): void
    {
        $this->post(route('admin.tenants.store'), $this->form());

        $entry = AuditEntry::query()
            ->where('action', AuditAction::TenantCreated->value)
            ->firstOrFail();

        $this->assertSame($this->admin->getKey(), $entry->actor_id);
        $this->assertStringContainsString('Crystal Seaside', (string) $entry->summary);
        $this->assertStringContainsString('billed by hand', (string) $entry->summary);
    }

    #[Test]
    public function it_refuses_an_address_that_already_signs_in_somewhere(): void
    {
        User::create([
            'name' => 'Already Here',
            'email' => 'maria@crystalseaside.test',
            'password' => 'correct-horse-battery',
        ]);

        $this->post(route('admin.tenants.store'), $this->form())
            ->assertSessionHasErrors('owner_email');

        /*
         * Nothing half-made left behind. A second account quietly attached
         * to one human is how somebody ends up ringing up about the empty
         * one.
         */
        $this->assertSame(0, Tenant::query()->where('name', 'Crystal Seaside')->count());
    }

    #[Test]
    public function it_refuses_a_plan_that_is_no_longer_sold(): void
    {
        $retired = Plan::factory()->create(['is_active' => false]);

        $this->post(route('admin.tenants.store'), [...$this->form(), 'plan_id' => $retired->id])
            ->assertSessionHasErrors('plan_id');
    }

    #[Test]
    public function it_is_closed_to_everybody_but_the_platform(): void
    {
        $nobody = User::forceCreate([
            'name' => 'Nobody',
            'email' => 'nobody@example.test',
            'password' => 'correct-horse-battery',
            'email_verified_at' => now(),
        ])->refresh();

        // 404 rather than 403, the way the rest of the admin area answers:
        // a platform screen should not confirm it exists to somebody who
        // has no business knowing.
        $this->actingAs($nobody)
            ->get(route('admin.tenants.create'))
            ->assertNotFound();

        $this->actingAs($nobody)
            ->post(route('admin.tenants.store'), $this->form())
            ->assertNotFound();

        $this->assertSame(0, Tenant::query()->where('name', 'Crystal Seaside')->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function form(): array
    {
        return [
            'venue_name' => 'Crystal Seaside',
            'owner_name' => 'Maria Stamati',
            'owner_email' => 'maria@crystalseaside.test',
            'plan_id' => $this->plan->id,
            'send_invitation' => '1',
        ];
    }
}
