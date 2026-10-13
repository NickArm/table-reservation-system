<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Models\Venue;
use App\Payments\CheckoutRequest;
use App\Payments\CheckoutSession;
use App\Payments\GatewayManager;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Sends a guest to pay (SPEC §8, §24.1).
 *
 * The reservation already exists at this point, as `pending_payment`, which
 * means it is holding its spot while the guest is on the gateway's page. That
 * is deliberate: a reservation we can see is one reconciliation can find
 * later, and an abandoned one is expired by a scheduled job rather than lost.
 */
final readonly class StartCheckout
{
    public function __construct(private GatewayManager $gateways) {}

    public function handle(Reservation $reservation, Tenant $tenant): CheckoutSession
    {
        [$gateway, $setting] = $this->gateways->resolve();
        $venue = Venue::query()->firstOrFail();

        return DB::transaction(function () use ($reservation, $tenant, $gateway, $setting, $venue): CheckoutSession {
            $session = $gateway->startCheckout($setting, new CheckoutRequest(
                reference: (string) $reservation->uuid,
                description: $this->describe($reservation, $venue->name),
                amountCents: (int) $reservation->amount_cents,
                currency: (string) $tenant->currency,
                successUrl: route('public.paid', [$tenant, $reservation]),
                cancelUrl: route('public.payment-cancelled', [$tenant, $reservation]),
                customerEmail: (string) $reservation->customer_email,
                metadata: [
                    'reservation' => (string) $reservation->uuid,
                    'code' => (string) $reservation->code,
                    'venue' => (string) $venue->name,
                ],
                statementDescriptor: $setting->statement_descriptor,
            ));

            $payment = new Payment;

            $payment->fill([
                'gateway' => $setting->gateway,
                'mode' => $setting->mode,
                'session_id' => $session->id,
                'amount_cents' => (int) $reservation->amount_cents,
                'currency' => (string) $tenant->currency,
                'status' => PaymentStatus::Pending,
                'payload' => $session->payload,
            ]);

            $payment->reservation_id = $reservation->id;
            $payment->save();

            return $session;
        });
    }

    private function describe(Reservation $reservation, string $venueName): string
    {
        $what = $reservation->resource_snapshot['label'] ?? 'Reservation';

        return sprintf(
            '%s · %s · %s',
            $venueName,
            $what,
            Money::format((int) $reservation->amount_cents),
        );
    }
}
