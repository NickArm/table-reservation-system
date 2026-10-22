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
 * The platform's own Stripe account, charging venues their subscription
 * (SPEC §4.3).
 *
 * Stripe Subscriptions rather than a card we charge ourselves: Stripe renews
 * it, retries a declined card on its own schedule, handles the bank's
 * authentication when a card needs it, and tells us about every one of those.
 * Holding the card ourselves would mean rebuilding all of that, badly, and
 * storing something we would rather never touch.
 */
final class StripeBilling implements BillingGateway
{
    private const BASE = 'https://api.stripe.com/v1';

    public function provider(): BillingProvider
    {
        return BillingProvider::Stripe;
    }

    public function isConfigured(): bool
    {
        return filled(config('billing.stripe.secret'));
    }

    public function startCheckout(Plan $plan, Tenant $tenant): BillingCheckout
    {
        $price = (string) $plan->stripe_price_id;

        if ($price === '') {
            throw BillingUnavailable::planNotSetUp($plan->name, $this->provider());
        }

        $response = $this->client()->asForm()->post(self::BASE.'/checkout/sessions', [
            'mode' => 'subscription',
            'line_items[0][price]' => $price,
            'line_items[0][quantity]' => 1,

            /*
             * The braces are Stripe's placeholder and have to survive intact,
             * which is why the reference is appended rather than passed
             * through route(): the URL generator would percent-encode them
             * and Stripe would hand back the literal string instead of a
             * session id.
             */
            'success_url' => route(
                (string) config('billing.return_route'),
                ['provider' => $this->provider()->value],
            ).'?reference={CHECKOUT_SESSION_ID}',
            'cancel_url' => route((string) config('billing.cancel_route')),

            /*
             * Which account this is for, carried by Stripe and handed back on
             * every webhook. Without it a renewal six months from now arrives
             * with a subscription id and no way to say whose it is, unless we
             * happen to have stored that id — and the one time we have not is
             * the time it matters.
             */
            'client_reference_id' => (string) $tenant->uuid,
            'subscription_data[metadata][tenant_uuid]' => (string) $tenant->uuid,
            'metadata[tenant_uuid]' => (string) $tenant->uuid,
        ]);

        $body = $this->decode($response);

        $url = (string) ($body['url'] ?? '');
        $id = (string) ($body['id'] ?? '');

        if ($url === '' || $id === '') {
            throw BillingUnavailable::providerSaidNothingUseful($this->provider());
        }

        return new BillingCheckout(
            url: $url,
            reference: $id,
        );
    }

    public function fetch(string $reference): BillingState
    {
        // A checkout session id on the way back from checkout; a subscription
        // id when a webhook sent us looking. One method, because the caller
        // should not have to know which kind of string it is holding.
        $isSession = str_starts_with($reference, 'cs_');

        $body = $isSession
            ? $this->decode($this->client()->get(
                self::BASE.'/checkout/sessions/'.$reference,
                ['expand' => ['subscription']],
            ))
            : $this->decode($this->client()->get(self::BASE.'/subscriptions/'.$reference));

        $subscription = $isSession
            ? (is_array($body['subscription'] ?? null) ? $body['subscription'] : [])
            : $body;

        if ($subscription === []) {
            return new BillingState(
                active: false,
                subscriptionId: null,
                customerId: null,
                currentPeriodEnd: null,
                amountCents: null,
                currency: null,
            );
        }

        $status = (string) ($subscription['status'] ?? '');

        $item = $subscription['items']['data'][0] ?? [];
        $amount = $item['price']['unit_amount'] ?? null;

        return new BillingState(
            // `trialing` counts as active: Stripe's own trial, if a price is
            // ever set up with one, is still a subscription in good standing.
            active: in_array($status, ['active', 'trialing'], true),
            subscriptionId: isset($subscription['id']) ? (string) $subscription['id'] : null,
            customerId: isset($subscription['customer']) ? (string) $subscription['customer'] : null,
            currentPeriodEnd: isset($subscription['current_period_end'])
                ? CarbonImmutable::createFromTimestamp((int) $subscription['current_period_end'])
                : null,
            amountCents: $amount === null ? null : (int) $amount,
            currency: isset($item['price']['currency'])
                ? strtoupper((string) $item['price']['currency'])
                : null,
            paymentFailed: in_array($status, ['past_due', 'unpaid', 'incomplete'], true),
            cancelled: in_array($status, ['canceled', 'incomplete_expired'], true),
        );
    }

    public function cancel(string $subscriptionId): void
    {
        // At the end of the period, not now. They paid for the month.
        $this->client()->asForm()->post(
            self::BASE.'/subscriptions/'.$subscriptionId,
            ['cancel_at_period_end' => 'true'],
        );
    }

    public function verify(Request $request): ?BillingNotice
    {
        $secret = (string) config('billing.stripe.webhook_secret');
        $header = $request->header('Stripe-Signature');

        if ($secret === '' || ! is_string($header)) {
            return null;
        }

        if (! $this->signatureHolds($secret, $header, $request->getContent())) {
            return null;
        }

        $event = $request->json()->all();

        if (! is_array($event)) {
            return null;
        }

        $type = (string) ($event['type'] ?? '');
        $object = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];

        $subscriptionId = isset($object['subscription'])
            ? (string) $object['subscription']
            : (isset($object['id']) && str_starts_with((string) $object['id'], 'sub_')
                ? (string) $object['id']
                : null);

        return match ($type) {
            'invoice.paid', 'invoice.payment_succeeded' => new BillingNotice(
                BillingEventKind::Paid,
                $subscriptionId,
                isset($object['amount_paid']) ? (int) $object['amount_paid'] : null,
            ),
            'invoice.payment_failed' => new BillingNotice(
                BillingEventKind::PaymentFailed,
                $subscriptionId,
                reason: $this->declineReason($object),
            ),
            'customer.subscription.deleted' => new BillingNotice(
                BillingEventKind::Cancelled,
                $subscriptionId,
            ),
            default => BillingNotice::ignored(),
        };
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function declineReason(array $invoice): ?string
    {
        $message = $invoice['last_finalization_error']['message']
            ?? $invoice['charge']['failure_message']
            ?? null;

        return is_string($message) ? $message : null;
    }

    /**
     * The same scheme as the venue-side gateway, against our own secret.
     *
     * The five-minute tolerance is Stripe's own: without it a webhook
     * captured once could be replayed for ever.
     */
    private function signatureHolds(string $secret, string $header, string $payload): bool
    {
        $parts = [];

        foreach (explode(',', $header) as $piece) {
            [$key, $value] = array_pad(explode('=', trim($piece), 2), 2, null);
            $parts[(string) $key][] = (string) $value;
        }

        $timestamp = $parts['t'][0] ?? null;

        if ($timestamp === null || abs(now()->getTimestamp() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($parts['v1'] ?? [] as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) config('billing.stripe.secret'))
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 200, throw: false);
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
                (string) ($body['error']['message'] ?? 'Stripe refused the request.'),
            );
        }

        return $body;
    }
}
