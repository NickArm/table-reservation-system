<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Actions\Payments\ReconcilePayments;
use App\Actions\Payments\SettlePayment;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\BookingType;
use App\Models\Payment;
use App\Models\PaymentGatewaySetting;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\Concerns\BuildsVenueStructure;
use Tests\TestCase;

/**
 * Money, from the guest's card to the venue's account (SPEC §7.4, §24.1–2).
 *
 * The gateway is faked at the HTTP boundary rather than mocked at the class
 * boundary, so the request we actually send to Stripe is part of what is
 * tested — including the idempotency keys.
 */
final class StripeCheckoutTest extends TestCase
{
    use ActsAsVenueOwner;
    use BuildsVenueStructure;
    use RefreshDatabase;

    private const DATE = '2026-07-04';

    private Tenant $venueTenant;

    private BookingType $bookingType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00', 'Europe/Athens'));

        $this->signInAsOwner('Demo Beach Bar');
        $this->venueTenant = $this->tenant;

        $this->bookingType = $this->asTenant(function (): BookingType {
            $type = $this->sunbedType(['name' => 'Sunbeds']);
            $area = $this->areaIn($type, 'Front row');
            $this->resourcesIn($area, 'A', 2, ['base_price_cents' => 2500]);

            PaymentGatewaySetting::factory()->stripe()->create();

            return $type;
        });

        auth()->logout();
        app(\App\Tenancy\TenantContext::class)->forget();
    }

    #[Test]
    public function a_guest_who_has_to_pay_is_sent_to_the_gateway(): void
    {
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_test_123',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
            ]),
        ]);

        $this->book();

        $reservation = $this->asVenue(static fn (): Reservation => Reservation::query()->firstOrFail());
        $payment = $this->asVenue(static fn (): Payment => Payment::query()->firstOrFail());

        // Held, not confirmed: the spot is off the board while they pay.
        $this->assertSame(ReservationStatus::PendingPayment, $reservation->status);
        $this->assertSame(PaymentStatus::Pending, $reservation->payment_status);
        $this->assertSame(2500, $reservation->amount_cents);

        $this->assertSame('cs_test_123', $payment->session_id);
        $this->assertSame(2500, $payment->amount_cents);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
                && $data['line_items[0][price_data][unit_amount]'] === 2500
                && $data['mode'] === 'payment'
                && str_starts_with((string) $request->header('Idempotency-Key')[0], 'checkout:');
        });
    }

    #[Test]
    public function a_paid_webhook_confirms_the_booking(): void
    {
        $payment = $this->pendingPayment();

        Http::fake([
            'api.stripe.com/v1/checkout/sessions/cs_test_123' => Http::response([
                'id' => 'cs_test_123',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_test_999',
                'amount_total' => 2500,
            ]),
        ]);

        $this->postWebhook(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_123']]])
            ->assertOk();

        $payment->refresh();
        $reservation = $this->asVenue(static fn (): Reservation => Reservation::query()->firstOrFail());

        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame('pi_test_999', $payment->gateway_payment_id);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
    }

    /**
     * Gateways retry. Twice through must not produce two bookings or two
     * charges (SPEC §25.1).
     */
    #[Test]
    public function the_same_webhook_twice_changes_nothing_the_second_time(): void
    {
        $this->pendingPayment();

        Http::fake([
            'api.stripe.com/v1/checkout/sessions/cs_test_123' => Http::response([
                'id' => 'cs_test_123',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_test_999',
                'amount_total' => 2500,
            ]),
        ]);

        $event = ['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_123']]];

        $this->postWebhook($event)->assertOk();
        $this->postWebhook($event)->assertOk();

        $this->assertSame(1, $this->asVenue(static fn (): int => Reservation::query()->count()));
        $this->assertSame(1, $this->asVenue(static fn (): int => Payment::query()->count()));

        // Asked once: the second time it was already paid, so we did not ask.
        $this->assertSame(
            1,
            Http::recorded(static fn ($request): bool => str_contains($request->url(), 'cs_test_123'))->count(),
        );
    }

    #[Test]
    public function a_webhook_with_a_wrong_signature_is_refused(): void
    {
        $this->pendingPayment();

        $this->postJson(
            route('webhooks', ['stripe', $this->venueTenant->uuid]),
            ['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_123']]],
            ['Stripe-Signature' => 't='.now()->timestamp.',v1=nonsense'],
        )->assertStatus(400);

        $this->assertSame(
            ReservationStatus::PendingPayment,
            $this->asVenue(static fn (): Reservation => Reservation::query()->firstOrFail())->status,
        );
    }

    /**
     * An old webhook, captured and replayed later, must not be accepted.
     */
    #[Test]
    public function a_webhook_older_than_five_minutes_is_refused(): void
    {
        $this->pendingPayment();

        $event = ['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_123']]];
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $stale = now()->subMinutes(10)->timestamp;

        $this->call(
            'POST',
            route('webhooks', ['stripe', $this->venueTenant->uuid]),
            [], [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => 't='.$stale.',v1='.hash_hmac('sha256', $stale.'.'.$body, $this->signingSecret()),
            ],
            $body,
        )->assertStatus(400);
    }

    /**
     * The payment arrives, but somebody else got the spot. We never keep
     * money for a booking that does not exist (SPEC §24.1).
     */
    #[Test]
    public function a_spot_taken_during_payment_is_refunded_in_full(): void
    {
        $payment = $this->pendingPayment();

        $this->asVenue(function (): void {
            $reservation = Reservation::query()->firstOrFail();

            // Somebody at the venue books the same sunbed by phone.
            Reservation::factory()
                ->forResource(Resource::query()->findOrFail($reservation->resource_id))
                ->at($reservation->starts_at, $reservation->ends_at)
                ->create(['customer_name' => 'Walked In']);
        });

        Http::fake([
            'api.stripe.com/v1/checkout/sessions/cs_test_123' => Http::response([
                'id' => 'cs_test_123',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_test_999',
                'amount_total' => 2500,
            ]),
            'api.stripe.com/v1/refunds' => Http::response([
                'id' => 're_test_1',
                'status' => 'succeeded',
                'amount' => 2500,
            ]),
        ]);

        app(SettlePayment::class)->handle($this->asVenue(static fn (): Payment => Payment::query()->firstOrFail()));

        $payment->refresh();
        $reservation = $this->asVenue(static fn (): Reservation => Reservation::query()->orderBy('id')->firstOrFail());

        $this->assertSame(2500, (int) $payment->refunded_amount_cents);

        // Two steps, one outcome: the booking is cancelled by the venue, and
        // the full refund that follows promotes it to Refunded so the
        // dashboard says both "we cancelled this" and "the money is back"
        // rather than leaving the owner to work the second part out.
        $this->assertSame(ReservationStatus::Refunded, $reservation->status);

        Http::assertSent(static fn ($request): bool => $request->url() === 'https://api.stripe.com/v1/refunds');
    }

    /**
     * The webhook never arrived. The hourly job finds the payment anyway —
     * the single most important safety net in the system (SPEC §24.2).
     */
    #[Test]
    public function reconciliation_finds_a_payment_whose_webhook_was_lost(): void
    {
        $this->pendingPayment();

        Http::fake([
            'api.stripe.com/v1/checkout/sessions/cs_test_123' => Http::response([
                'id' => 'cs_test_123',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_test_999',
                'amount_total' => 2500,
            ]),
        ]);

        $result = app(ReconcilePayments::class)->handle();

        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['settled']);

        $this->assertSame(
            ReservationStatus::Confirmed,
            $this->asVenue(static fn (): Reservation => Reservation::query()->firstOrFail())->status,
        );
    }

    #[Test]
    public function an_abandoned_checkout_gives_the_spot_back(): void
    {
        $this->pendingPayment();

        Http::fake([
            'api.stripe.com/v1/checkout/sessions/cs_test_123' => Http::response(['id' => 'cs_test_123']),
            'api.stripe.com/v1/checkout/sessions/cs_test_123/expire' => Http::response(['id' => 'cs_test_123']),
        ]);

        $this->travel(25)->minutes();

        $this->artisan('payments:reconcile')->assertSuccessful();

        $reservation = $this->asVenue(static fn (): Reservation => Reservation::query()->firstOrFail());

        $this->assertSame(ReservationStatus::Expired, $reservation->status);

        // And Stripe was told to close the checkout, so a late payment on a
        // stale tab is impossible (SPEC §24.1).
        Http::assertSent(static fn ($request): bool => str_ends_with($request->url(), '/expire'));
    }

    #[Test]
    public function a_webhook_for_another_venue_does_nothing_here(): void
    {
        $this->pendingPayment();

        $this->signInAsOwner('Another Place');
        $otherTenant = $this->tenant;
        auth()->logout();

        // Acknowledged so the gateway stops retrying, and acted on by nobody:
        // that venue has no Stripe set up, so there is no secret to verify
        // against and no payment of ours to touch.
        $this->postJson(
            route('webhooks', ['stripe', $otherTenant->uuid]),
            ['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_123']]],
        )->assertOk();

        $this->assertSame(
            ReservationStatus::PendingPayment,
            $this->asVenue(static fn (): Reservation => Reservation::query()->firstOrFail())->status,
        );
    }

    #[Test]
    public function a_guest_who_backs_out_at_the_gateway_frees_the_spot_at_once(): void
    {
        $this->pendingPayment();

        $reservation = $this->asVenue(static fn (): Reservation => Reservation::query()->firstOrFail());

        $this->get(route('public.payment-cancelled', [$this->venueTenant, $reservation]))
            ->assertRedirect(route('public.search', [
                $this->venueTenant,
                'people' => $reservation->party_size,
            ]));

        /*
         * Immediately, rather than at the next sweep. The guest is standing on
         * the sand with the page open and will try again in the next thirty
         * seconds; finding their own abandoned booking in the way is the one
         * thing that must not happen (SPEC §24.1).
         */
        $this->assertSame(
            ReservationStatus::Expired,
            $this->asVenue(static fn (): Reservation => Reservation::query()->firstOrFail())->status,
        );
    }

    #[Test]
    public function backing_out_does_not_touch_a_booking_that_was_already_paid(): void
    {
        $this->pendingPayment();

        // The webhook carries an identifier and nothing worth trusting, so
        // the session is fetched from Stripe before anything is believed.
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/cs_test_123' => Http::response([
                'id' => 'cs_test_123',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_test_123',
                'amount_total' => 2500,
            ]),
        ]);

        $this->postWebhook([
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_test_123']],
        ])->assertOk();

        $reservation = $this->asVenue(static fn (): Reservation => Reservation::query()->firstOrFail());
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);

        // The same URL, typed afterwards or opened from a stale tab. A guest
        // must not be able to cancel a paid booking by going back a page.
        $this->get(route('public.payment-cancelled', [$this->venueTenant, $reservation]))
            ->assertRedirect();

        $this->assertSame(
            ReservationStatus::Confirmed,
            $this->asVenue(static fn (): Reservation => Reservation::query()->firstOrFail())->status,
        );
    }

    #[Test]
    public function one_venues_address_cannot_cancel_another_venues_booking(): void
    {
        $this->pendingPayment();

        $reservation = $this->asVenue(static fn (): Reservation => Reservation::query()->firstOrFail());

        $this->signInAsOwner('Another Place');
        $otherTenant = $this->tenant;
        auth()->logout();

        $this->get(route('public.payment-cancelled', [$otherTenant, $reservation]))
            ->assertNotFound();

        $this->assertSame(
            ReservationStatus::PendingPayment,
            $this->asVenue(static fn (): Reservation => Reservation::query()->firstOrFail())->status,
        );
    }

    private function book(): void
    {
        $spot = $this->asVenue(static fn (): Resource => Resource::query()->where('label', 'A1')->firstOrFail());

        $this->post(route('public.hold', $this->venueTenant), [
            'resource' => $spot->id,
            'starts_at' => CarbonImmutable::parse(self::DATE.' 11:00', 'Europe/Athens')->utc()->toIso8601String(),
            'people' => 2,
        ]);

        $hold = $this->asVenue(static fn () => \App\Models\ReservationHold::query()->firstOrFail());

        $this->post(route('public.book.store', [$this->venueTenant, $hold]), [
            'name' => 'Anna Muller',
            'email' => 'anna@example.com',
            'calling_code' => '+49',
            'phone' => '15123456789',
            'terms' => '1',
        ]);
    }

    private function pendingPayment(): Payment
    {
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_test_123',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
            ]),
        ]);

        $this->book();

        // Deliberately no `Http::fake()` here. It does not reset anything: it
        // appends a catch-all stub that answers every later request with an
        // empty 200, and because stubs are tried in the order they were
        // registered that catch-all answered before the stub the test itself
        // sets up — so the gateway received an empty body and reported that
        // Stripe had sent something that was not JSON. The stub above matches
        // the create-session address exactly and cannot shadow the retrieve
        // address a test adds afterwards.
        return $this->asVenue(static fn (): Payment => Payment::query()->firstOrFail());
    }

    private function postWebhook(array $event): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = now()->timestamp;

        return $this->call(
            'POST',
            route('webhooks', ['stripe', $this->venueTenant->uuid]),
            [], [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='
                    .hash_hmac('sha256', $timestamp.'.'.$body, $this->signingSecret()),
            ],
            $body,
        );
    }

    private function signingSecret(): string
    {
        return (string) $this->asVenue(
            static fn (): ?string => PaymentGatewaySetting::query()
                ->where('gateway', 'stripe')
                ->firstOrFail()
                ->credential('webhook_secret')
        );
    }

    private function asVenue(Closure $callback): mixed
    {
        return app(\App\Tenancy\TenantContext::class)->asTenant($this->venueTenant, $callback);
    }
}
