<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use App\Actions\Subscriptions\StartSubscription;
use App\Enums\BillingProvider;
use App\Enums\SubscriptionEventType;
use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * What the platform's provider tells us about subscriptions (SPEC §4.3).
 *
 * This is the half of billing nobody watches. A renewal eleven months from
 * now arrives here and nowhere else — there is no browser open when a card is
 * charged for the second time — so an account staying alive depends entirely
 * on these requests being handled correctly.
 *
 * Which makes the forged ones the first thing to test.
 */
final class BillingWebhookTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    private const SECRET = 'whsec_platform_0000';

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00', 'Europe/Athens'));

        config([
            'billing.provider' => 'stripe',
            'billing.mode' => 'test',
            'billing.stripe.secret' => 'sk_test_platform_key_0000',
            'billing.stripe.webhook_secret' => self::SECRET,
        ]);

        $this->signInAsOwner('Demo Beach Bar');

        $plan = Plan::factory()->sellable()->create(['price_cents' => 7000]);

        $this->subscription = $this->asTenant(fn (): Subscription => app(StartSubscription::class)->handle(
            $this->tenant,
            $plan,
            BillingProvider::Stripe,
            'sub_test_1',
            'cus_test_1',
        ));
    }

    #[Test]
    public function an_unsigned_notice_changes_nothing(): void
    {
        $before = $this->subscription->current_period_end;

        // Anybody can find this address. Without a signature it is a stranger
        // claiming somebody paid.
        $this->postJson(
            route('webhooks.billing', BillingProvider::Stripe->value),
            $this->invoicePaid(),
        )->assertNoContent();

        $this->assertEquals($before, $this->reload()->current_period_end);
    }

    #[Test]
    public function a_notice_signed_with_the_wrong_secret_changes_nothing(): void
    {
        $before = $this->subscription->current_period_end;

        $this->send($this->invoicePaid(), secret: 'whsec_not_ours')->assertNoContent();

        $this->assertEquals($before, $this->reload()->current_period_end);
    }

    #[Test]
    public function a_notice_replayed_from_last_week_changes_nothing(): void
    {
        $before = $this->subscription->current_period_end;

        // Stripe's own five-minute tolerance. Without it a webhook captured
        // once could be posted back for ever.
        $this->send($this->invoicePaid(), at: now()->subWeek()->getTimestamp())
            ->assertNoContent();

        $this->assertEquals($before, $this->reload()->current_period_end);
    }

    #[Test]
    public function a_renewal_extends_the_period_and_is_written_into_the_history(): void
    {
        // The period has run out, which is what a renewal arrives to fix.
        $this->travelTo(CarbonImmutable::parse('2026-08-01 09:05', 'Europe/Athens'));

        $this->send($this->invoicePaid())->assertNoContent();

        $subscription = $this->reload();

        $this->assertTrue($subscription->current_period_end?->isFuture());
        $this->assertSame(TenantStatus::Active, $subscription->status);
        $this->assertSame(TenantStatus::Active, $this->tenant->refresh()->status);

        $this->assertTrue(
            $this->asTenant(static fn (): bool => SubscriptionEvent::query()
                ->where('type', SubscriptionEventType::Renewed)
                ->exists())
        );
    }

    #[Test]
    public function the_first_invoice_of_a_new_subscription_does_not_buy_a_second_month(): void
    {
        $end = $this->subscription->current_period_end;

        // Stripe pays the first invoice seconds after checkout, by which time
        // the browser has usually started the subscription already. Renewing
        // on top of that would hand out a month nobody paid for.
        $this->send($this->invoicePaid())->assertNoContent();

        $this->assertEquals($end, $this->reload()->current_period_end);
    }

    #[Test]
    public function a_failed_payment_is_recorded_without_switching_anything_off(): void
    {
        $this->send($this->invoiceFailed())->assertNoContent();

        $subscription = $this->reload();

        $this->assertNotNull($subscription->payment_failed_at);

        // Past due, not expired — and past due still takes bookings. A card
        // that bounced on a Friday in August must not close the booking page
        // for the weekend (§4.4).
        $status = $this->tenant->refresh()->status;

        $this->assertSame(TenantStatus::PastDue, $status);
        $this->assertTrue($status->acceptsBookings());

        $event = $this->asTenant(static fn (): ?SubscriptionEvent => SubscriptionEvent::query()
            ->where('type', SubscriptionEventType::PaymentFailed)
            ->first());

        $this->assertNotNull($event);
        $this->assertStringContainsString('expired', (string) $event->summary);
    }

    #[Test]
    public function a_payment_that_goes_through_after_a_failure_clears_it(): void
    {
        $this->send($this->invoiceFailed())->assertNoContent();
        $this->assertNotNull($this->reload()->payment_failed_at);

        $this->send($this->invoicePaid())->assertNoContent();

        $subscription = $this->reload();

        $this->assertNull($subscription->payment_failed_at);
        $this->assertSame(TenantStatus::Active, $this->tenant->refresh()->status);
    }

    #[Test]
    public function cancelling_at_the_provider_is_reflected_here(): void
    {
        $this->send($this->subscriptionDeleted())->assertNoContent();

        $subscription = $this->reload();

        $this->assertTrue((bool) $subscription->cancel_at_period_end);
        $this->assertNotNull($subscription->cancelled_at);

        // They paid for this month, so this month is theirs.
        $this->assertSame(TenantStatus::Active, $this->tenant->refresh()->status);
    }

    #[Test]
    public function a_notice_about_a_subscription_we_have_never_heard_of_is_answered_and_dropped(): void
    {
        $payload = $this->invoicePaid();
        $payload['data']['object']['subscription'] = 'sub_somebody_else';
        $payload['data']['object']['client_reference_id'] = null;

        $this->send($payload)->assertNoContent();

        $this->assertNull($this->reload()->payment_failed_at);
    }

    #[Test]
    public function the_very_first_webhook_can_arrive_before_the_browser_does(): void
    {
        // A webhook can overtake the redirect, which is the one moment the
        // provider's subscription id has not been stored yet. The account
        // reference we attached at checkout is what saves it.
        $this->asTenant(fn () => $this->subscription
            ->forceFill(['provider_subscription_id' => null])
            ->save());

        $this->travelTo(CarbonImmutable::parse('2026-08-01 09:05', 'Europe/Athens'));

        $payload = $this->invoicePaid();
        $payload['data']['object']['subscription'] = 'sub_not_stored_yet';

        $this->send($payload)->assertNoContent();

        $this->assertTrue($this->reload()->current_period_end?->isFuture());
    }

    #[Test]
    public function an_event_we_do_not_act_on_is_simply_accepted(): void
    {
        $payload = $this->invoicePaid();
        $payload['type'] = 'customer.updated';

        // A provider that gets anything but a 200 retries for hours. An event
        // we have no use for is not a reason to be sent it again.
        $this->send($payload)->assertNoContent();

        $this->assertNull($this->reload()->payment_failed_at);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(array $payload, ?string $secret = null, ?int $at = null): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = $at ?? now()->getTimestamp();

        $signature = hash_hmac(
            'sha256',
            $timestamp.'.'.$body,
            $secret ?? self::SECRET,
        );

        return $this->call(
            'POST',
            route('webhooks.billing', BillingProvider::Stripe->value),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature,
            ],
            $body,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePaid(): array
    {
        return [
            'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_test_1',
                'subscription' => 'sub_test_1',
                'client_reference_id' => (string) $this->tenant->uuid,
                'amount_paid' => 7000,
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceFailed(): array
    {
        return [
            'type' => 'invoice.payment_failed',
            'data' => ['object' => [
                'id' => 'in_test_2',
                'subscription' => 'sub_test_1',
                'client_reference_id' => (string) $this->tenant->uuid,
                'last_finalization_error' => ['message' => 'Your card has expired.'],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionDeleted(): array
    {
        return [
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => [
                'id' => 'sub_test_1',
                'status' => 'canceled',
                'metadata' => ['tenant_uuid' => (string) $this->tenant->uuid],
            ]],
        ];
    }

    private function reload(): Subscription
    {
        return $this->asTenant(static fn (): Subscription => Subscription::query()->firstOrFail());
    }
}
