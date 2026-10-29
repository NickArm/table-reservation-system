<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Actions\Subscriptions\AdvanceLifecycle;
use App\Actions\Subscriptions\RecordPaymentFailure;
use App\Actions\Subscriptions\StartSubscription;
use App\Enums\AuditAction;
use App\Enums\SubscriptionEventType;
use App\Enums\TenantStatus;
use App\Models\AuditEntry;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsPlatformAdmin;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * What a platform admin can do to an account (SPEC §4.1, §13.2).
 *
 * Mostly about the record. Every one of these actions is something somebody
 * will later have to explain — a free month, a suspension, a price nobody
 * else got — so the test for each is as much about the line it writes as
 * about what it changes.
 */
final class TenantAdminTest extends TestCase
{
    use ActsAsPlatformAdmin;
    use ActsAsVenueOwner;
    use RefreshDatabase;

    private Tenant $account;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00', 'Europe/Athens'));

        $this->signInAsOwner('Demo Beach Bar');

        $plan = Plan::factory()->create(['price_cents' => 7000]);

        $this->subscription = $this->asTenant(fn (): Subscription => app(StartSubscription::class)
            ->handle($this->tenant, $plan));

        $this->account = $this->tenant;

        $this->signInAsPlatformAdmin();
    }

    #[Test]
    public function an_account_can_be_found_by_its_owners_email(): void
    {
        // The one thing a venue says correctly on the telephone.
        $this->get(route('admin.tenants.index', ['q' => 'demo-beach-bar@example.test']))
            ->assertOk()
            ->assertSee('Demo Beach Bar');

        $this->get(route('admin.tenants.index', ['q' => 'nobody@nowhere.test']))
            ->assertOk()
            ->assertSee('Nothing matches that');
    }

    #[Test]
    public function the_status_chips_carry_their_counts_and_keep_the_search(): void
    {
        $this->get(route('admin.tenants.index'))
            ->assertOk()
            ->assertSee('Everything')
            ->assertSee('<span class="chip__count">1</span>', false);

        // A status nobody is in is a chip nobody needs.
        $this->get(route('admin.tenants.index'))
            ->assertOk()
            ->assertDontSee('Suspended');

        // And narrowing by status does not throw away who was being looked for.
        $this->get(route('admin.tenants.index', ['q' => 'Demo']))
            ->assertOk()
            ->assertSee(route('admin.tenants.index', ['q' => 'Demo', 'status' => $this->account->status->value]));
    }

    #[Test]
    public function the_overview_says_why_each_account_is_on_the_list(): void
    {
        $this->asTenant(fn () => $this->account->forceFill([
            'status' => TenantStatus::Trialing,
            'trial_ends_at' => now()->addDays(2),
        ])->save());

        /*
         * A row that shows only a status leaves the reader to work out what
         * to do about it. The whole value of this list is that it can be
         * read with a telephone already in hand.
         */
        $this->get(route('admin.overview'))
            ->assertOk()
            ->assertSee('Trial ends in 2 days')
            ->assertSee('Worth a call');
    }

    #[Test]
    public function extending_moves_the_period_and_is_written_down_twice(): void
    {
        $until = CarbonImmutable::parse('2026-10-31', 'Europe/Athens');

        $this->post(route('admin.tenants.extend', $this->account), [
            'until' => $until->format('Y-m-d'),
            'reason' => 'Paid by transfer, invoice 2026-114.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(
            '2026-10-31',
            $this->reload()->current_period_end?->format('Y-m-d'),
        );

        // The owner's own history, in words they can read.
        $event = $this->asTenant(static fn (): ?SubscriptionEvent => SubscriptionEvent::query()
            ->where('type', SubscriptionEventType::AdjustedByHand)
            ->first());

        $this->assertNotNull($event);
        $this->assertSame((int) $this->admin->getKey(), (int) $event->actor_id);

        // And the platform's own, with the reason.
        $entry = AuditEntry::query()
            ->where('action', AuditAction::SubscriptionExtended)
            ->firstOrFail();

        $this->assertSame('Paid by transfer, invoice 2026-114.', $entry->reason);
        $this->assertSame('Demo Beach Bar', $entry->affected_tenant_name);
        $this->assertSame($this->admin->name, $entry->actor_name);
    }

    #[Test]
    public function extending_without_a_reason_is_refused(): void
    {
        $this->post(route('admin.tenants.extend', $this->account), [
            'until' => now()->addMonths(3)->format('Y-m-d'),
        ])->assertSessionHasErrors('reason');

        // An unexplained free month is indistinguishable from a mistake.
        $this->assertSame(0, AuditEntry::query()->count());
    }

    #[Test]
    public function an_extension_cannot_shorten_a_period(): void
    {
        $before = $this->reload()->current_period_end;

        $this->post(route('admin.tenants.extend', $this->account), [
            'until' => now()->addDay()->format('Y-m-d'),
            'reason' => 'Trying to cut them short.',
        ])->assertRedirect()->assertSessionHas('error');

        // Taking back time already paid for is not an extension, and should
        // not be reachable by typing a date into a box.
        $this->assertEquals($before, $this->reload()->current_period_end);
    }

    #[Test]
    public function extending_stops_the_chasing_emails(): void
    {
        $this->asTenant(fn () => app(RecordPaymentFailure::class)->handle(
            $this->reload(),
            'Your card has expired.',
        ));

        $this->post(route('admin.tenants.extend', $this->account), [
            'until' => now()->addMonths(2)->format('Y-m-d'),
            'reason' => 'Settled in cash at the venue.',
        ])->assertRedirect();

        $subscription = $this->reload();

        // Square with us by definition. Left set, the nightly sweep would
        // keep chasing a debt that has been settled.
        $this->assertNull($subscription->payment_failed_at);
        $this->assertNull($subscription->dunning_sent_for);
        $this->assertSame(TenantStatus::Active, $this->account->refresh()->status);
    }

    #[Test]
    public function suspending_stops_the_booking_page_and_nothing_automatic_undoes_it(): void
    {
        $this->post(route('admin.tenants.suspend', $this->account), [
            'reason' => 'Chargeback dispute.',
        ])->assertRedirect();

        $this->assertSame(TenantStatus::Suspended, $this->account->refresh()->status);

        // The nightly sweep must leave it alone. A suspension that came
        // undone by itself would mean nobody could say whether it had held.
        app(AdvanceLifecycle::class)->handle();

        $this->assertSame(TenantStatus::Suspended, $this->account->refresh()->status);

        // And the guest-facing side says so, calmly and without blaming the
        // venue for a dispute with us.
        auth()->logout();
        app(TenantContext::class)->forget();

        $this->get(route('public.search', [$this->account, 'people' => 2]))
            ->assertStatus(503)
            ->assertSee('temporarily unavailable')
            ->assertDontSee('suspended');
    }

    #[Test]
    public function suspending_without_a_reason_is_refused(): void
    {
        $this->post(route('admin.tenants.suspend', $this->account), [])
            ->assertSessionHasErrors('reason');

        $this->assertSame(TenantStatus::Active, $this->account->refresh()->status);
    }

    #[Test]
    public function restoring_gives_back_whatever_the_subscription_justifies(): void
    {
        $this->post(route('admin.tenants.suspend', $this->account), ['reason' => 'Dispute.'])
            ->assertRedirect();

        // The period ran out while they were suspended, so they come back
        // expired rather than back to the active state they were in before.
        $this->asTenant(fn () => $this->reload()
            ->forceFill(['current_period_end' => now()->subDay()])
            ->save());

        $this->post(route('admin.tenants.restore', $this->account), ['reason' => 'Resolved.'])
            ->assertRedirect();

        $this->assertSame(TenantStatus::Expired, $this->account->refresh()->status);

        $this->assertTrue(
            AuditEntry::query()->where('action', AuditAction::TenantRestored)->exists()
        );
    }

    #[Test]
    public function the_log_shows_what_was_done_and_can_be_narrowed_to_one_account(): void
    {
        $this->post(route('admin.tenants.suspend', $this->account), [
            'reason' => 'Chargeback dispute.',
        ])->assertRedirect();

        $this->get(route('admin.audit'))
            ->assertOk()
            ->assertSee('Account suspended')
            ->assertSee('Chargeback dispute.')
            ->assertSee($this->admin->name);

        $this->get(route('admin.audit', ['tenant' => $this->account->id]))
            ->assertOk()
            ->assertSee('Chargeback dispute.');

        $this->get(route('admin.audit', ['action' => AuditAction::PlanCreated->value]))
            ->assertOk()
            ->assertDontSee('Chargeback dispute.');
    }

    private function reload(): Subscription
    {
        return Subscription::query()
            ->acrossTenants()
            ->where('tenant_id', $this->account->getKey())
            ->firstOrFail();
    }
}
