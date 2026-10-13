<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\TenantRole;
use App\Models\Payment;
use App\Models\PaymentGatewaySetting;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\Concerns\BuildsVenueStructure;
use Tests\TestCase;

/**
 * Giving money back (SPEC §8.3).
 *
 * Every rule here was learned by somebody expensively: idempotency, never
 * claiming a refund landed before the gateway says so, a running total that
 * cannot be exceeded, and a failure that is reported rather than swallowed.
 */
final class RefundTest extends TestCase
{
    use ActsAsVenueOwner;
    use BuildsVenueStructure;
    use RefreshDatabase;

    private Reservation $reservation;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00', 'Europe/Athens'));

        $this->signInAsOwner();

        [$this->reservation, $this->payment] = $this->asTenant(function (): array {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');
            $resource = $this->resourcesIn($area, 'A', 1, ['base_price_cents' => 2500])->first();

            PaymentGatewaySetting::factory()->stripe()->create();

            $reservation = Reservation::factory()
                ->forResource($resource)
                ->create(['amount_cents' => 2500, 'payment_status' => PaymentStatus::Paid]);

            $payment = Payment::factory()->forReservation($reservation)->paid()->create();

            return [$reservation, $payment];
        });
    }

    #[Test]
    public function the_owner_cancels_and_refunds_in_full(): void
    {
        Http::fake(['api.stripe.com/v1/refunds' => Http::response([
            'id' => 're_1',
            'status' => 'succeeded',
            'amount' => 2500,
            'destination_details' => ['card' => ['reference' => '74512345678901234567890']],
        ])]);

        $this->post(route('dashboard.reservations.cancel', $this->reservation), [
            'reason' => 'Guest called — flight cancelled',
            'refund' => 'full',
        ])->assertRedirect(route('dashboard.today'));

        $this->payment->refresh();
        $this->reservation->refresh();

        $this->assertSame(2500, (int) $this->payment->refunded_amount_cents);
        $this->assertSame(PaymentStatus::Refunded, $this->payment->status);
        $this->assertSame('74512345678901234567890', $this->payment->refund_reference);
        $this->assertSame(ReservationStatus::Refunded, $this->reservation->status);
        $this->assertSame('Guest called — flight cancelled', $this->reservation->cancellation_reason);
    }

    #[Test]
    public function a_partial_refund_leaves_the_rest_with_the_venue(): void
    {
        Http::fake(['api.stripe.com/v1/refunds' => Http::response([
            'id' => 're_1',
            'status' => 'succeeded',
            'amount' => 1000,
        ])]);

        $this->post(route('dashboard.reservations.cancel', $this->reservation), [
            'reason' => 'Late cancellation',
            'refund' => 'partial',
            'amount' => '10.00',
        ])->assertRedirect();

        $this->payment->refresh();

        $this->assertSame(1000, (int) $this->payment->refunded_amount_cents);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $this->payment->status);
        $this->assertSame(1500, $this->payment->refundableCents());
    }

    #[Test]
    public function cancelling_without_a_refund_is_a_valid_choice(): void
    {
        Http::fake();

        $this->post(route('dashboard.reservations.cancel', $this->reservation), [
            'reason' => 'No show policy',
            'refund' => 'none',
        ])->assertRedirect();

        $this->payment->refresh();
        $this->reservation->refresh();

        $this->assertSame(0, (int) $this->payment->refunded_amount_cents);
        $this->assertSame(ReservationStatus::CancelledByVenue, $this->reservation->status);

        Http::assertNothingSent();
    }

    /**
     * Two partial refunds must never add up to more than the charge.
     */
    #[Test]
    public function the_running_total_cannot_be_exceeded(): void
    {
        Http::fake(['api.stripe.com/v1/refunds' => Http::response([
            'id' => 're_1',
            'status' => 'succeeded',
            'amount' => 2000,
        ])]);

        $refunds = app(\App\Actions\Payments\RefundPayment::class);

        $this->asTenant(function () use ($refunds): void {
            $first = $refunds->handle($this->payment, 2000);
            $this->assertTrue($first->accepted);

            $this->payment->refresh();

            $second = $refunds->handle($this->payment, 1000);
            $this->assertFalse($second->accepted);
            $this->assertStringContainsString('up to', (string) $second->failureReason);

            $this->payment->refresh();
        });

        $this->assertSame(2000, (int) $this->payment->refunded_amount_cents);
    }

    /**
     * A refund is asynchronous. Until the gateway confirms it, the answer to
     * "have I been refunded?" is "it is on its way".
     */
    #[Test]
    public function a_pending_refund_is_never_reported_as_done(): void
    {
        Http::fake(['api.stripe.com/v1/refunds' => Http::response([
            'id' => 're_1',
            'status' => 'pending',
            'amount' => 2500,
        ])]);

        $this->asTenant(function (): void {
            app(\App\Actions\Payments\RefundPayment::class)->handle($this->payment, 2500);

            $this->payment->refresh();
            $this->reservation->refresh();
        });

        $this->assertSame(PaymentStatus::RefundPending, $this->payment->status);
        $this->assertSame('pending', $this->payment->refund_status);
        $this->assertSame(PaymentStatus::RefundPending, $this->reservation->payment_status);
    }

    #[Test]
    public function a_refused_refund_is_reported_not_swallowed(): void
    {
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(
            ['error' => ['message' => 'Insufficient funds in your Stripe balance.']],
            402,
        )]);

        $this->post(route('dashboard.reservations.cancel', $this->reservation), [
            'reason' => 'Guest called',
            'refund' => 'full',
        ])->assertSessionHasErrors('refund');

        $this->payment->refresh();

        $this->assertSame(0, (int) $this->payment->refunded_amount_cents);
        $this->assertSame('failed', $this->payment->refund_status);
        $this->assertStringContainsString('Insufficient funds', (string) $this->payment->refund_failure_reason);
    }

    /**
     * A double-clicked button is one refund, because the key is the same.
     */
    #[Test]
    public function the_same_refund_attempt_carries_the_same_idempotency_key(): void
    {
        Http::fake(['api.stripe.com/v1/refunds' => Http::response([
            'id' => 're_1',
            'status' => 'succeeded',
            'amount' => 2500,
        ])]);

        $this->asTenant(fn () => app(\App\Actions\Payments\RefundPayment::class)
            ->handle($this->payment, 2500));

        Http::assertSent(function ($request): bool {
            $key = $request->header('Idempotency-Key')[0] ?? '';

            return str_starts_with((string) $key, 'refund:') && str_ends_with((string) $key, ':0');
        });
    }

    #[Test]
    public function staff_can_cancel_but_not_move_money(): void
    {
        $staff = User::forceCreate([
            'name' => 'Staff',
            'email' => 'staff@example.test',
            'password' => 'correct-horse-battery',
            'email_verified_at' => now(),
        ]);

        $this->tenant->users()->attach($staff, ['role' => TenantRole::Staff->value]);

        $this->actingAs($staff);

        Http::fake();

        $this->post(route('dashboard.reservations.cancel', $this->reservation), [
            'reason' => 'Guest called',
            'refund' => 'full',
        ])->assertSessionHasErrors('refund');

        $this->post(route('dashboard.reservations.cancel', $this->reservation), [
            'reason' => 'Guest called',
            'refund' => 'none',
        ])->assertRedirect();

        $this->reservation->refresh();

        $this->assertSame(ReservationStatus::CancelledByVenue, $this->reservation->status);

        Http::assertNothingSent();
    }

    #[Test]
    public function another_venue_cannot_refund_this_ones_booking(): void
    {
        $mine = $this->reservation;

        $this->signInAsOwner('Another Place');

        $this->post(route('dashboard.reservations.cancel', $mine), [
            'reason' => 'Trying it on',
            'refund' => 'full',
        ])->assertNotFound();
    }
}
