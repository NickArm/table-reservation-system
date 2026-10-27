<?php

declare(strict_types=1);

namespace App\Billing\Gateways;

use App\Billing\BillingCheckout;
use App\Billing\BillingNotice;
use App\Billing\BillingState;
use App\Billing\Contracts\BillingGateway;
use App\Billing\Exceptions\BillingUnavailable;
use App\Enums\BillingEventKind;
use App\Enums\BillingProvider;
use App\Models\Plan;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * The platform's own PayPal account, charging venues their subscription
 * (SPEC §4.3).
 *
 * PayPal Subscriptions, for the same reason as Stripe's: PayPal renews it and
 * tells us. It matters more here than it might elsewhere — a good number of
 * Greek venues have a PayPal account and no card gateway at all, and asking
 * them for a card would lose the sale rather than move it.
 *
 * Two differences from Stripe worth knowing when reading this:
 *
 *  - every call needs an OAuth token first, fetched from the same credentials
 *  - a webhook is verified by *asking PayPal*, not by checking a signature
 *    locally, so verification costs a round trip
 */
final class PayPalBilling implements BillingGateway
{
    private const LIVE = 'https://api-m.paypal.com';

    private const SANDBOX = 'https://api-m.sandbox.paypal.com';

    public function provider(): BillingProvider
    {
        return BillingProvider::PayPal;
    }

    public function isConfigured(): bool
    {
        return filled(config('billing.paypal.client_id'))
            && filled(config('billing.paypal.client_secret'));
    }

    public function startCheckout(Plan $plan, Tenant $tenant): BillingCheckout
    {
        $planId = (string) $plan->paypal_plan_id;

        if ($planId === '') {
            throw BillingUnavailable::planNotSetUp($plan->name, $this->provider());
        }

        $response = $this->client()->post($this->base().'/v1/billing/subscriptions', [
            'plan_id' => $planId,

            // Carried by PayPal and handed back on every webhook, so a
            // renewal next year can be matched to an account.
            'custom_id' => (string) $tenant->uuid,

            'application_context' => [
                'brand_name' => (string) config('app.name'),
                'user_action' => 'SUBSCRIBE_NOW',
                'return_url' => route(
                    (string) config('billing.return_route'),
                    ['provider' => $this->provider()->value],
                ),
                'cancel_url' => route((string) config('billing.cancel_route')),
            ],
        ]);

        $body = $this->decode($response);

        $id = (string) ($body['id'] ?? '');
        $approve = null;

        foreach ($body['links'] ?? [] as $link) {
            if (is_array($link) && ($link['rel'] ?? null) === 'approve') {
                $approve = (string) ($link['href'] ?? '');
            }
        }

        if ($id === '' || $approve === null || $approve === '') {
            throw BillingUnavailable::providerSaidNothingUseful($this->provider());
        }

        return new BillingCheckout(url: $approve, reference: $id);
    }

    public function fetch(string $reference): BillingState
    {
        $body = $this->decode(
            $this->client()->get($this->base().'/v1/billing/subscriptions/'.$reference)
        );

        $status = (string) ($body['status'] ?? '');

        $amount = $body['billing_info']['last_payment']['amount']['value']
            ?? $body['shipping_amount']['value']
            ?? null;

        $currency = $body['billing_info']['last_payment']['amount']['currency_code'] ?? null;

        return new BillingState(
            active: $status === 'ACTIVE',
            subscriptionId: isset($body['id']) ? (string) $body['id'] : null,
            customerId: isset($body['subscriber']['payer_id'])
                ? (string) $body['subscriber']['payer_id']
                : null,
            currentPeriodEnd: isset($body['billing_info']['next_billing_time'])
                ? CarbonImmutable::parse((string) $body['billing_info']['next_billing_time'])
                : null,

            // PayPal talks in decimal strings; everything here is cents.
            amountCents: $amount === null ? null : (int) round(((float) $amount) * 100),
            currency: is_string($currency) ? strtoupper($currency) : null,

            // SUSPENDED is what PayPal does after enough failed attempts, so
            // it means the same thing as Stripe's past_due here.
            paymentFailed: $status === 'SUSPENDED'
                || (int) ($body['billing_info']['failed_payments_count'] ?? 0) > 0,
            cancelled: in_array($status, ['CANCELLED', 'EXPIRED'], true),
        );
    }

    public function cancel(string $subscriptionId): void
    {
        $response = $this->client()->post(
            $this->base().'/v1/billing/subscriptions/'.$subscriptionId.'/cancel',
            ['reason' => 'Cancelled from the venue dashboard.'],
        );

        /*
         * Not through `decode`: a successful cancellation here is 204 with an
         * empty body, which `decode` would read as the provider saying nothing
         * useful. The status is the whole answer — and it has to be looked at,
         * because the client does not throw and a discarded refusal means the
         * owner is told they have stopped paying while PayPal keeps billing.
         */
        if ($response->status() >= 400) {
            throw BillingUnavailable::providerRefused(
                $this->provider(),
                (string) ($response->json('message') ?? 'PayPal refused to cancel the subscription.'),
            );
        }
    }

    public function verify(Request $request): ?BillingNotice
    {
        $webhookId = (string) config('billing.paypal.webhook_id');

        if ($webhookId === '') {
            // No webhook id means nothing can be verified, and an unverified
            // webhook is a stranger telling us somebody paid.
            return null;
        }

        $event = $request->json()->all();

        if (! is_array($event)) {
            return null;
        }

        /*
         * PayPal verifies on its own servers rather than by a signature we
         * can check here. That costs a round trip per webhook, which is the
         * price of the only verification PayPal offers.
         */
        $verification = $this->client()->post(
            $this->base().'/v1/notifications/verify-webhook-signature',
            [
                'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
                'cert_url' => $request->header('PAYPAL-CERT-URL'),
                'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
                'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
                'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
                'webhook_id' => $webhookId,
                'webhook_event' => $event,
            ],
        );

        if ($verification->json('verification_status') !== 'SUCCESS') {
            return null;
        }

        $type = (string) ($event['event_type'] ?? '');
        $resource = is_array($event['resource'] ?? null) ? $event['resource'] : [];

        $subscriptionId = $resource['billing_agreement_id']
            ?? ($type === 'BILLING.SUBSCRIPTION.CANCELLED' || str_starts_with($type, 'BILLING.SUBSCRIPTION.')
                ? ($resource['id'] ?? null)
                : null);

        $amount = $resource['amount']['total'] ?? $resource['amount']['value'] ?? null;

        return match ($type) {
            'PAYMENT.SALE.COMPLETED' => new BillingNotice(
                BillingEventKind::Paid,
                is_string($subscriptionId) ? $subscriptionId : null,
                $amount === null ? null : (int) round(((float) $amount) * 100),
            ),
            'BILLING.SUBSCRIPTION.PAYMENT.FAILED', 'PAYMENT.SALE.DENIED' => new BillingNotice(
                BillingEventKind::PaymentFailed,
                is_string($subscriptionId) ? $subscriptionId : null,
                reason: 'PayPal could not take the payment.',
            ),
            'BILLING.SUBSCRIPTION.CANCELLED', 'BILLING.SUBSCRIPTION.EXPIRED' => new BillingNotice(
                BillingEventKind::Cancelled,
                is_string($subscriptionId) ? $subscriptionId : null,
            ),
            default => BillingNotice::ignored(),
        };
    }

    private function base(): string
    {
        return config('billing.mode') === 'live' ? self::LIVE : self::SANDBOX;
    }

    private function client(): PendingRequest
    {
        $token = $this->accessToken();

        if ($token === null) {
            throw BillingUnavailable::providerRefused(
                $this->provider(),
                'the credentials were not accepted.',
            );
        }

        return Http::withToken($token)
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 200, throw: false);
    }

    private function accessToken(): ?string
    {
        $response = Http::asForm()
            ->withBasicAuth(
                (string) config('billing.paypal.client_id'),
                (string) config('billing.paypal.client_secret'),
            )
            ->timeout(20)
            ->post($this->base().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        $token = $response->json('access_token');

        return is_string($token) ? $token : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            throw BillingUnavailable::providerSaidNothingUseful($this->provider());
        }

        if ($response->status() >= 400) {
            throw BillingUnavailable::providerRefused(
                $this->provider(),
                (string) ($body['message'] ?? 'PayPal refused the request.'),
            );
        }

        return $body;
    }
}
