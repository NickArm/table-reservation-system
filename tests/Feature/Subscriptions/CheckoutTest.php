<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use App\Enums\BillingProvider;
use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * Paying for a subscription (SPEC §4.3).
 *
 * The thing being tested here is mostly distrust. A browser coming back from
 * a payment provider has proved nothing — the return address is a plain URL —
 * so the account is only marked as paying once the provider has been asked
 * directly and has said yes.
 */
final class CheckoutTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00', 'Europe/Athens'));

        config([
            'billing.provider' => 'stripe',
            'billing.mode' => 'test',
            'billing.stripe.secret' => 'sk_test_platform_key_0000',
            'billing.stripe.webhook_secret' => 'whsec_platform_0000',
        ]);

        $this->signInAsOwner('Demo Beach Bar');

        $this->plan = Plan::factory()->sellable()->create([
            'name' => 'Standard',
            'price_cents' => 7000,
        ]);
    }

    #[Test]
    public function the_billing_page_offers_a_button_for_a_plan_that_can_be_bought(): void
    {
        $this->get(route('dashboard.billing'))
            ->assertOk()
            ->assertSee('Choose this plan')
            ->assertSee(route('dashboard.billing.checkout', $this->plan), false);
    }

    #[Test]
    public function a_plan_the_provider_does_not_know_about_gets_no_button(): void
    {
        // A plan the super admin has written but not yet set up at Stripe. The
        // page has to keep working for one, and must not offer a button that
        // dies on the other side.
        Plan::query()->update(['stripe_price_id' => null, 'paypal_plan_id' => null]);

        $this->get(route('dashboard.billing'))
            ->assertOk()
            ->assertDontSee('Choose this plan');
    }

    #[Test]
    public function with_no_keys_configured_the_page_says_so_instead_of_offering_buttons(): void
    {
        config(['billing.stripe.secret' => null]);

        $this->get(route('dashboard.billing'))
            ->assertOk()
            ->assertDontSee('Choose this plan')
            ->assertSee('not switched on yet');
    }

    #[Test]
    public function choosing_a_plan_sends_them_to_the_provider(): void
    {
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_test_session_1',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_session_1',
            ]),
        ]);

        $this->post(route('dashboard.billing.checkout', $this->plan))
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_session_1');

        // Nothing is active yet. Being sent to a payment page is not paying,
        // and a subscription written here would hand the account everything
        // before a card had been touched.
        $this->assertSame(0, Subscription::query()->acrossTenants()->count());
    }

    #[Test]
    public function a_provider_that_refuses_is_reported_without_charging_anything(): void
    {
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response(
                ['error' => ['message' => 'No such price: price_nonsense']],
                400,
            ),
        ]);

        $this->from(route('dashboard.billing'))
            ->post(route('dashboard.billing.checkout', $this->plan))
            ->assertRedirect(route('dashboard.billing'))
            ->assertSessionHas('error');

        $this->assertSame(0, Subscription::query()->acrossTenants()->count());
    }

    #[Test]
    public function another_venues_private_plan_cannot_be_bought_by_guessing_its_id(): void
    {
        $other = Tenant::factory()->create();

        $private = Plan::factory()->sellable()->create([
            'is_public' => false,
            'custom_for_tenant_id' => $other->getKey(),
            'price_cents' => 1000,
        ]);

        // A plan is not tenant-scoped — it belongs to the platform — so this
        // is the only thing standing between an account and somebody else's
        // negotiated price.
        $this->post(route('dashboard.billing.checkout', $private))->assertNotFound();
    }

    #[Test]
    public function coming_back_from_the_provider_is_not_believed_on_its_own(): void
    {
        // Somebody typing the return address, with no checkout behind it.
        $this->get(route('dashboard.billing.returned', [
            'provider' => BillingProvider::Stripe->value,
            'reference' => 'cs_test_made_up',
        ]))->assertRedirect(route('dashboard.billing'));

        $this->assertSame(0, Subscription::query()->acrossTenants()->count());
    }

    #[Test]
    public function coming_back_after_a_real_payment_starts_the_subscription(): void
    {
        $this->startCheckout();

        Http::fake([
            'api.stripe.com/v1/checkout/sessions/cs_test_session_1*' => Http::response([
                'id' => 'cs_test_session_1',
                'subscription' => [
                    'id' => 'sub_test_1',
                    'status' => 'active',
                    'customer' => 'cus_test_1',
                    'current_period_end' => CarbonImmutable::parse('2026-08-01 09:00')->getTimestamp(),
                    'items' => ['data' => [[
                        'price' => ['unit_amount' => 7000, 'currency' => 'eur'],
                    ]]],
                ],
            ]),
        ]);

        $this->get(route('dashboard.billing.returned', [
            'provider' => BillingProvider::Stripe->value,
            'reference' => 'cs_test_session_1',
        ]))
            ->assertRedirect(route('dashboard.billing'))
            ->assertSessionHas('status');

        $subscription = $this->asTenant(
            static fn (): Subscription => Subscription::query()->firstOrFail()
        );

        $this->assertSame(TenantStatus::Active, $subscription->status);
        $this->assertSame(BillingProvider::Stripe, $subscription->provider);
        $this->assertSame('sub_test_1', $subscription->provider_subscription_id);
        $this->assertSame('cus_test_1', $subscription->provider_customer_id);
        $this->assertSame((int) $this->plan->getKey(), (int) $subscription->plan_id);

        $this->assertSame(
            TenantStatus::Active,
            $this->tenant->refresh()->status,
        );
    }

    #[Test]
    public function a_payment_still_being_approved_is_a_wait_rather_than_a_failure(): void
    {
        $this->startCheckout();

        // PayPal in particular can still be approving when the browser
        // arrives. Telling them it failed would send them to pay twice.
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/cs_test_session_1*' => Http::response([
                'id' => 'cs_test_session_1',
                'subscription' => null,
            ]),
        ]);

        $this->get(route('dashboard.billing.returned', [
            'provider' => BillingProvider::Stripe->value,
            'reference' => 'cs_test_session_1',
        ]))
            ->assertRedirect(route('dashboard.billing'))
            ->assertSessionHas('status');

        $this->assertSame(0, Subscription::query()->acrossTenants()->count());
    }

    #[Test]
    public function an_expired_account_can_still_reach_the_pay_button(): void
    {
        $this->tenant->forceFill([
            'status' => TenantStatus::Expired,
            'trial_ends_at' => now()->subMonth(),
        ])->save();

        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_test_session_2',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_session_2',
            ]),
        ]);

        // The read-only lock has to let this through. Locking somebody out of
        // the one page where they would pay is the single mistake that
        // guarantees they never do (§4.4).
        $this->get(route('dashboard.billing'))->assertOk();

        $this->post(route('dashboard.billing.checkout', $this->plan))
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_session_2');
    }

    #[Test]
    public function cancelling_stops_the_provider_and_keeps_the_month_that_was_paid_for(): void
    {
        $this->subscribe();

        Http::fake([
            'api.stripe.com/v1/subscriptions/sub_test_1' => Http::response([
                'id' => 'sub_test_1',
                'status' => 'canceled',
            ]),
        ]);

        $this->post(route('dashboard.billing.cancel'))
            ->assertRedirect(route('dashboard.billing'))
            ->assertSessionHas('status');

        $subscription = $this->asTenant(
            static fn (): Subscription => Subscription::query()->firstOrFail()
        );

        $this->assertNotNull($subscription->cancelled_at);
        $this->assertTrue((bool) $subscription->cancel_at_period_end);

        /*
         * Still working. They paid for the month, and switching the venue off
         * the same afternoon would strand every guest who has already booked
         * (SPEC §4.3).
         */
        $this->assertSame(TenantStatus::Active, $this->tenant->refresh()->status);
        $this->get(route('dashboard.today'))->assertOk();
    }

    #[Test]
    public function nothing_is_cancelled_here_if_the_provider_cannot_be_reached(): void
    {
        $this->subscribe();

        Http::fake([
            'api.stripe.com/v1/subscriptions/sub_test_1' => Http::response(['error' => 'down'], 500),
        ]);

        $this->post(route('dashboard.billing.cancel'))
            ->assertRedirect()
            ->assertSessionHas('error');

        /*
         * The order is deliberate. Marking it cancelled here while the
         * provider keeps charging is the one outcome worth refusing — the
         * owner would be paying for an account that believes it is closed.
         */
        $this->assertNull($this->asTenant(
            static fn (): ?Subscription => Subscription::query()->first()
        )?->cancelled_at);
    }

    #[Test]
    public function cancelling_twice_changes_nothing(): void
    {
        $this->subscribe();

        Http::fake([
            'api.stripe.com/v1/subscriptions/sub_test_1' => Http::response(['status' => 'canceled']),
        ]);

        $this->post(route('dashboard.billing.cancel'))->assertRedirect();

        $first = $this->asTenant(
            static fn (): Subscription => Subscription::query()->firstOrFail()
        )->cancelled_at;

        $this->travel(2)->hours();
        $this->post(route('dashboard.billing.cancel'))->assertRedirect(route('dashboard.billing'));

        $this->assertEquals($first, $this->asTenant(
            static fn (): Subscription => Subscription::query()->firstOrFail()
        )->cancelled_at);
    }

    #[Test]
    public function one_venue_cannot_cancel_another_ones_subscription(): void
    {
        $this->subscribe();

        $mine = $this->tenant;

        $this->signInAsOwner('Another Place');
        $this->post(route('dashboard.billing.cancel'))->assertRedirect(route('dashboard.billing'));

        // Nothing of the first account's was touched: `cancel` resolves the
        // subscription from the signed-in account, so there is no identifier
        // to aim at somebody else's.
        $this->assertNull(
            Subscription::query()->acrossTenants()
                ->where('tenant_id', $mine->getKey())
                ->firstOrFail()
                ->cancelled_at
        );
    }

    /**
     * An account that is paying, through a full return from the provider —
     * the same path a real one took to get there.
     */
    private function subscribe(): void
    {
        $this->startCheckout();

        Http::fake([
            'api.stripe.com/v1/checkout/sessions/cs_test_session_1*' => Http::response([
                'id' => 'cs_test_session_1',
                'subscription' => [
                    'id' => 'sub_test_1',
                    'status' => 'active',
                    'customer' => 'cus_test_1',
                    'current_period_end' => CarbonImmutable::parse('2026-08-01 09:00')->getTimestamp(),
                    'items' => ['data' => [[
                        'price' => ['unit_amount' => 7000, 'currency' => 'eur'],
                    ]]],
                ],
            ]),
        ]);

        $this->get(route('dashboard.billing.returned', [
            'provider' => BillingProvider::Stripe->value,
            'reference' => 'cs_test_session_1',
        ]))->assertRedirect(route('dashboard.billing'));
    }

    /**
     * Puts the session into the state a real checkout leaves behind.
     */
    private function startCheckout(): void
    {
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_test_session_1',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_session_1',
            ]),
        ]);

        $this->post(route('dashboard.billing.checkout', $this->plan))->assertRedirect();
    }
}
