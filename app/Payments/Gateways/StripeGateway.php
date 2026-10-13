<?php

declare(strict_types=1);

namespace App\Payments\Gateways;

use App\Enums\Gateway;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentGatewaySetting;
use App\Payments\CheckoutRequest;
use App\Payments\CheckoutSession;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Exceptions\GatewayError;
use App\Payments\PaymentResult;
use App\Payments\RefundResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Stripe Checkout, over its REST API (SPEC §7.4).
 *
 * No SDK: the whole surface used here is five calls and a signature check,
 * and a vendor package would be the single heaviest dependency in a project
 * meant to install on shared hosting. The interface exists precisely so that
 * swapping this for stripe-php later touches nothing else.
 *
 * Card data never reaches us — the guest types it on Stripe's own page, which
 * is what keeps us in PCI scope SAQ-A (SPEC §16).
 */
final class StripeGateway implements PaymentGateway
{
    private const BASE = 'https://api.stripe.com/v1';

    /** Pinned, so Stripe cannot change a field under us without warning. */
    private const API_VERSION = '2024-06-20';

    public function gateway(): Gateway
    {
        return Gateway::Stripe;
    }

    public function startCheckout(PaymentGatewaySetting $setting, CheckoutRequest $request): CheckoutSession
    {
        $payload = [
            'mode' => 'payment',
            'success_url' => $request->successUrl,
            'cancel_url' => $request->cancelUrl,
            'client_reference_id' => $request->reference,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => mb_strtolower($request->currency),
                    'unit_amount' => $request->amountCents,
                    'product_data' => ['name' => $request->description],
                ],
            ]],
        ];

        if ($request->customerEmail !== null) {
            $payload['customer_email'] = $request->customerEmail;
        }

        if ($request->statementDescriptor !== null) {
            $payload['payment_intent_data']['statement_descriptor'] = $request->statementDescriptor;
        }

        foreach ($request->metadata as $key => $value) {
            $payload['metadata'][$key] = $value;
        }

        // The reference doubles as the idempotency key: a guest who
        // double-taps "pay" gets the same checkout, not two.
        $response = $this->client($setting)
            ->withHeaders(['Idempotency-Key' => 'checkout:'.$request->reference])
            ->asForm()
            ->post(self::BASE.'/checkout/sessions', $this->flatten($payload));

        $body = $this->decode($response->json(), $response->status());

        return new CheckoutSession(
            id: (string) ($body['id'] ?? ''),
            url: isset($body['url']) ? (string) $body['url'] : null,
            payload: Payment::scrub($body),
        );
    }

    public function fetchPayment(PaymentGatewaySetting $setting, string $sessionId): PaymentResult
    {
        $response = $this->client($setting)->get(self::BASE.'/checkout/sessions/'.$sessionId);
        $body = $this->decode($response->json(), $response->status());

        $paid = ($body['payment_status'] ?? '') === 'paid';
        $expired = ($body['status'] ?? '') === 'expired';

        return new PaymentResult(
            status: match (true) {
                $paid => PaymentStatus::Paid,
                $expired => PaymentStatus::Failed,
                default => PaymentStatus::Pending,
            },
            gatewayPaymentId: isset($body['payment_intent']) ? (string) $body['payment_intent'] : null,
            amountCents: (int) ($body['amount_total'] ?? 0),
            failureReason: $expired ? 'The checkout expired before it was paid.' : null,
            payload: Payment::scrub($body),
        );
    }

    public function cancelCheckout(PaymentGatewaySetting $setting, string $sessionId): void
    {
        // Expiring the session is what stops a guest paying for a spot whose
        // hold ran out while they were typing (SPEC §24.1).
        $this->client($setting)->asForm()->post(self::BASE.'/checkout/sessions/'.$sessionId.'/expire');
    }

    public function refund(
        PaymentGatewaySetting $setting,
        string $gatewayPaymentId,
        int $amountCents,
        string $idempotencyKey,
    ): RefundResult {
        $response = $this->client($setting)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->asForm()
            ->post(self::BASE.'/refunds', [
                'payment_intent' => $gatewayPaymentId,
                'amount' => $amountCents,
            ]);

        $body = $response->json();

        if (! $response->successful()) {
            return RefundResult::failed(
                (string) ($body['error']['message'] ?? 'The refund was refused.'),
                $amountCents,
            );
        }

        $status = (string) ($body['status'] ?? 'pending');

        return new RefundResult(
            accepted: $status !== 'failed',
            amountCents: (int) ($body['amount'] ?? $amountCents),
            gatewayRefundId: isset($body['id']) ? (string) $body['id'] : null,

            // The acquirer reference number: what a guest's bank can trace.
            reference: isset($body['destination_details']['card']['reference'])
                ? (string) $body['destination_details']['card']['reference']
                : null,
            failureReason: $status === 'failed'
                ? (string) ($body['failure_reason'] ?? 'The refund failed.')
                : null,
            isPending: $status === 'pending',
            payload: Payment::scrub(is_array($body) ? $body : []),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function verifyWebhook(PaymentGatewaySetting $setting, Request $request): ?array
    {
        $secret = $setting->credential('webhook_secret');
        $header = $request->header('Stripe-Signature');

        if ($secret === null || ! is_string($header)) {
            return null;
        }

        $parts = [];

        foreach (explode(',', $header) as $piece) {
            [$key, $value] = array_pad(explode('=', trim($piece), 2), 2, null);
            $parts[(string) $key][] = (string) $value;
        }

        $timestamp = $parts['t'][0] ?? null;

        if ($timestamp === null) {
            return null;
        }

        // Five minutes, Stripe's own tolerance: without it a captured webhook
        // could be replayed forever.
        if (abs(now()->getTimestamp() - (int) $timestamp) > 300) {
            return null;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        foreach ($parts['v1'] ?? [] as $signature) {
            if (hash_equals($expected, $signature)) {
                $event = $request->json()->all();

                return is_array($event) ? $event : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function sessionIdFromEvent(array $event): ?string
    {
        $type = (string) ($event['type'] ?? '');

        if (! in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            return null;
        }

        $id = $event['data']['object']['id'] ?? null;

        return is_string($id) ? $id : null;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function testConnection(PaymentGatewaySetting $setting): array
    {
        try {
            $response = $this->client($setting)->get(self::BASE.'/balance');
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => 'Could not reach Stripe: '.$exception->getMessage()];
        }

        if ($response->successful()) {
            return ['ok' => true, 'message' => 'Connected to Stripe.'];
        }

        return [
            'ok' => false,
            'message' => (string) ($response->json('error.message') ?? 'Stripe refused those keys.'),
        ];
    }

    private function client(PaymentGatewaySetting $setting): PendingRequest
    {
        $key = $setting->credential('secret_key');

        if ($key === null) {
            throw GatewayError::missingCredentials(Gateway::Stripe);
        }

        return Http::withToken($key)
            ->withHeaders(['Stripe-Version' => self::API_VERSION])
            ->timeout(20)
            ->retry(2, 200, throw: false);
    }

    /**
     * @param  mixed  $body
     * @return array<string, mixed>
     */
    private function decode($body, int $status): array
    {
        if (! is_array($body)) {
            throw GatewayError::unexpected(Gateway::Stripe, 'Stripe sent something that was not JSON.');
        }

        if ($status >= 400) {
            throw GatewayError::unexpected(
                Gateway::Stripe,
                (string) ($body['error']['message'] ?? 'Stripe refused the request.'),
            );
        }

        return $body;
    }

    /**
     * Stripe's form API takes nested data as line_items[0][price_data][...].
     *
     * @param  array<string|int, mixed>  $data
     * @return array<string, mixed>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix.'['.$key.']';

            if (is_array($value)) {
                $flat += $this->flatten($value, $name);

                continue;
            }

            $flat[$name] = $value;
        }

        return $flat;
    }
}
