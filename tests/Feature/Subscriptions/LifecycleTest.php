<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use App\Actions\Subscriptions\AdvanceLifecycle;
use App\Actions\Subscriptions\CancelSubscription;
use App\Actions\Subscriptions\RecordPaymentFailure;
use App\Actions\Subscriptions\RecordSubscriptionEvent;
use App\Actions\Subscriptions\RenewSubscription;
use App\Actions\Subscriptions\StartSubscription;
use App\Actions\Subscriptions\SyncTenantStatus;
use App\Enums\BillingProvider;
use App\Enums\SubscriptionEventType;
use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * The subscription lifecycle (SPEC §4.3).
 *
 * The first test here is the one that matters most to the business: before
 * this milestone nothing ever ended a trial, so anybody who found /register
 * had the whole product for nothing, for ever.
 */
final class LifecycleTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00', 'Europe/Athens'));

        $this->signInAsOwner('Demo Beach Bar');
    }

    #[Test]
    public function a_trial_that_runs_out_locks_the_account(): void
    {
        $this->assertSame(TenantStatus::Trialing, $this->tenant->status);

        // Day sixteen of a fifteen-day trial.
        $this->travel(16)->days();

        $moved = app(AdvanceLifecycle::class)->handle();

        $this->assertSame(1, $moved['trials_ended']);
        $this->assertSame(TenantStatus::Expired, $this->tenant->fresh()?->status);
    }

    #[Test]
    public function a_trial_still_running_is_left_alone(): void
    {
        $this->travel(10)->days();

        app(AdvanceLifecycle::class)->handle();

        $this->assertSame(TenantStatus::Trialing, $this->tenant->fresh()?->status);
    }

    #[Test]
    public function the_sweep_is_safe_to_run_twice(): void
    {
        $this->travel(16)->days();

        app(AdvanceLifecycle::class)->handle();

        // The second run must find nothing to do rather than stepping the
        // state on again: a lifecycle that advances by one each time drifts
        // the first time a cron is missed.
        $again = app(AdvanceLifecycle::class)->handle();

        $this->assertSame(0, $again['changed']);
        $this->assertSame(TenantStatus::Expired, $this->tenant->fresh()?->status);
    }

    #[Test]
    public function subscribing_makes_the_account_active_and_records_it(): void
    {
        $plan = Plan::factory()->create(['name' => 'Monthly', 'price_cents' => 7000]);

        $subscription = $this->asTenant(fn (): Subscription => app(StartSubscription::class)->handle(
            $this->tenant,
            $plan,
            BillingProvider::Stripe,
        ));

        $this->assertSame(TenantStatus::Active, $this->tenant->fresh()?->status);
        $this->assertTrue($subscription->isCurrent());
        $this->assertSame(7000, (int) $subscription->price_cents);

        $event = $this->asTenant(static fn (): SubscriptionEvent => SubscriptionEvent::query()->sole());

        $this->assertSame(SubscriptionEventType::Started, $event->type);
        $this->assertStringContainsString('Monthly', $event->summary);
    }

    #[Test]
    public function the_price_is_copied_so_a_later_rise_does_not_apply_retroactively(): void
    {
        $plan = Plan::factory()->create(['price_cents' => 7000]);

        $subscription = $this->asTenant(
            fn (): Subscription => app(StartSubscription::class)->handle($this->tenant, $plan)
        );

        $plan->forceFill(['price_cents' => 9000])->save();

        $this->assertSame(7000, (int) $subscription->fresh()?->price_cents);
    }

    #[Test]
    public function renewing_measures_from_the_previous_end_not_from_today(): void
    {
        $plan = Plan::factory()->create();

        $subscription = $this->asTenant(
            fn (): Subscription => app(StartSubscription::class)->handle($this->tenant, $plan)
        );

        $firstEnd = $subscription->current_period_end;

        // The renewal is confirmed three hours late. The account has not
        // bought three hours less.
        $this->travelTo($firstEnd?->addHours(3));

        $this->asTenant(fn () => app(RenewSubscription::class)->handle($subscription->fresh()));

        $this->assertTrue(
            $subscription->fresh()?->current_period_start->equalTo($firstEnd),
            'The new period should start where the old one ended.'
        );
    }

    #[Test]
    public function a_failed_payment_does_not_stop_bookings_the_same_afternoon(): void
    {
        $subscription = $this->subscribed();

        $this->asTenant(fn () => app(RecordPaymentFailure::class)->handle($subscription, 'card_expired'));

        // Mid-season, the card expired over the winter. Taking the venue off
        // the internet before anybody has read an email costs them a Saturday.
        $this->assertSame(TenantStatus::PastDue, $this->tenant->fresh()?->status);
        $this->assertTrue($this->tenant->fresh()?->acceptsBookings());
    }

    #[Test]
    public function a_failed_payment_runs_past_due_then_grace_then_expired(): void
    {
        $subscription = $this->subscribed();

        $this->asTenant(fn () => app(RecordPaymentFailure::class)->handle($subscription));

        $this->assertSame(TenantStatus::PastDue, $this->tenant->fresh()?->status);

        // The paid period runs out, and the grace days begin.
        $this->travelTo($subscription->current_period_end?->addHour());

        $this->asTenant(fn () => app(SyncTenantStatus::class)->forSubscription($subscription->fresh()));

        $this->assertSame(TenantStatus::Grace, $this->tenant->fresh()?->status);
        $this->assertTrue($this->tenant->fresh()?->acceptsBookings());

        // And once the grace days are gone.
        $this->travel((int) config('tenancy.grace_days') + 1)->days();

        $this->asTenant(fn () => app(SyncTenantStatus::class)->forSubscription($subscription->fresh()));

        $this->assertSame(TenantStatus::Expired, $this->tenant->fresh()?->status);
        $this->assertFalse($this->tenant->fresh()?->acceptsBookings());
    }

    #[Test]
    public function the_grace_period_is_measured_from_the_first_failure_not_the_latest(): void
    {
        $subscription = $this->subscribed();

        $failures = app(RecordPaymentFailure::class);

        $this->asTenant(function () use ($failures, $subscription): void {
            $failures->handle($subscription);
        });

        $firstFailure = $subscription->fresh()?->payment_failed_at;

        $this->travel(2)->days();

        // A retry also fails. If this reset the clock a subscription could
        // sit in grace for ever.
        $this->asTenant(fn () => $failures->handle($subscription->fresh()));

        $this->assertTrue($subscription->fresh()?->payment_failed_at->equalTo($firstFailure));
    }

    #[Test]
    public function a_recovered_payment_stops_the_clock(): void
    {
        $subscription = $this->subscribed();

        $failures = app(RecordPaymentFailure::class);

        $this->asTenant(fn () => $failures->handle($subscription));
        $this->asTenant(fn () => $failures->recovered($subscription->fresh()));

        $this->assertNull($subscription->fresh()?->payment_failed_at);
        $this->assertSame(TenantStatus::Active, $this->tenant->fresh()?->status);
    }

    #[Test]
    public function cancelling_keeps_everything_working_until_the_period_ends(): void
    {
        $subscription = $this->subscribed();

        $this->asTenant(fn () => app(CancelSubscription::class)->handle($subscription));

        // They paid for the month. Switching the venue off the same afternoon
        // would be taking money for nothing and stranding every guest who has
        // already booked.
        $this->assertSame(TenantStatus::Active, $this->tenant->fresh()?->status);
        $this->assertTrue($this->tenant->fresh()?->acceptsBookings());
        $this->assertFalse($subscription->fresh()?->willRenew());

        $this->travelTo($subscription->current_period_end?->addHour());

        app(AdvanceLifecycle::class)->handle();

        $this->assertSame(TenantStatus::Expired, $this->tenant->fresh()?->status);
    }

    #[Test]
    public function a_suspended_account_is_never_un_suspended_by_the_sweep(): void
    {
        $this->subscribed();

        $this->tenant->forceFill(['status' => TenantStatus::Suspended])->save();

        app(AdvanceLifecycle::class)->handle();

        // Only a person may undo this. A nightly job that could reinstate an
        // account the platform switched off by hand is a job that will.
        $this->assertSame(TenantStatus::Suspended, $this->tenant->fresh()?->status);
    }

    #[Test]
    public function a_change_made_by_hand_without_a_reason_is_refused(): void
    {
        $subscription = $this->subscribed();

        $this->expectException(InvalidArgumentException::class);

        $this->asTenant(fn () => app(RecordSubscriptionEvent::class)->handle(
            $subscription,
            SubscriptionEventType::AdjustedByHand,
            'Gave them a free month.',
            actor: $this->owner,
        ));
    }

    #[Test]
    public function a_change_made_by_hand_with_a_reason_carries_the_name(): void
    {
        $subscription = $this->subscribed();

        $event = $this->asTenant(fn (): SubscriptionEvent => app(RecordSubscriptionEvent::class)->handle(
            $subscription,
            SubscriptionEventType::AdjustedByHand,
            'Free month added.',
            actor: $this->owner,
            reason: 'Paid by bank transfer on the 3rd.',
        ));

        $this->assertTrue($event->wasByHand());
        $this->assertSame('Paid by bank transfer on the 3rd.', $event->reason);
    }

    #[Test]
    public function one_account_never_sees_another_ones_billing_history(): void
    {
        $this->subscribed();

        $mine = $this->asTenant(static fn (): int => SubscriptionEvent::query()->count());

        $this->assertGreaterThan(0, $mine);

        $this->signInAsOwner('Other Taverna');

        $theirs = $this->asTenant(static fn (): int => SubscriptionEvent::query()->count());

        $this->assertSame(0, $theirs);
    }

    private function subscribed(): Subscription
    {
        $plan = Plan::factory()->create(['name' => 'Monthly']);

        return $this->asTenant(fn (): Subscription => app(StartSubscription::class)->handle(
            $this->tenant,
            $plan,
            BillingProvider::Stripe,
        ));
    }
}
