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
 * PayPal Orders v2, over its REST API (SPEC §7.4).
 *
 * Worth having from the start: plenty of guests have PayPal and no card they
 * are willing to type into an unfamiliar site, and plenty of small venues
 * already run a PayPal business account.
 *
 * PayPal's refund fee rules differ from Stripe's, so nothing here calculates
 * a fee — whatever the gateway reports is what the owner is shown (§8.3).
 */
final class PayPalGateway implements PaymentGateway
{
    public function gateway(): Gateway
    {
        return Gateway::PayPal;
    }

    public function startCheckout(PaymentGatewaySetting $setting, CheckoutRequest $request): CheckoutSession
    {
        $response = $this->client($setting)
            ->withHeaders(['PayPal-Request-Id' => 'checkout:'.$request->reference])
            ->post($this->base($setting).'/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $request->reference,
                    'description' => mb_substr($request->description, 0, 127),
                    'amount' => [
                        'currency_code' => mb_strtoupper($request->currency),
                        'value' => number_format($request->amountCents / 100, 2, '.', ''),
                    ],
                ]],
                'payment_source' => [
                    'paypal' => [
                        'experience_context' => [
                            'return_url' => $request->successUrl,
                            'cancel_url' => $request->cancelUrl,
                            'user_action' => 'PAY_NOW',
                            'shipping_preference' => 'NO_SHIPPING',
                        ],
                    ],
                ],
            ]);

        $body = $this->decode($response->json(), $response->status());

        return new CheckoutSession(
            id: (string) ($body['id'] ?? ''),
            url: $this->linkFrom($body, 'payer-action') ?? $this->linkFrom($body, 'approve'),
            payload: Payment::scrub($body),
        );
    }

    public function fetchPayment(PaymentGatewaySetting $setting, string $sessionId): PaymentResult
    {
        $response = $this->client($setting)->get($this->base($setting).'/v2/checkout/orders/'.$sessionId);
        $body = $this->decode($response->json(), $response->status());

        $status = (string) ($body['status'] ?? '');

        // An approved order still has to be captured — approval is the guest
        // saying yes, not the money moving.
        if ($status === 'APPROVED') {
            return $this->capture($setting, $sessionId);
        }

        return new PaymentResult(
            status: $status === 'COMPLETED' ? PaymentStatus::Paid : PaymentStatus::Pending,
            gatewayPaymentId: $this->captureIdFrom($body),
            amountCents: $this->amountFrom($body),
            payload: Payment::scrub($body),
        );
    }

    public function cancelCheckout(PaymentGatewaySetting $setting, string $sessionId): void
    {
        // PayPal orders expire on their own and offer no cancel call, so the
        // defence here is the availability re-check before confirming
        // (SPEC §24.1, third line).
    }

    public function refund(
        PaymentGatewaySetting $setting,
        string $gatewayPaymentId,
        int $amountCents,
        string $idempotencyKey,
    ): RefundResult {
        $response = $this->client($setting)
            ->withHeaders(['PayPal-Request-Id' => $idempotencyKey])
            ->post($this->base($setting).'/v2/payments/captures/'.$gatewayPaymentId.'/refund', [
                'amount' => [
                    'currency_code' => 'EUR',
                    'value' => number_format($amountCents / 100, 2, '.', ''),
                ],
            ]);

        $body = $response->json();

        if (! $response->successful()) {
            return RefundResult::failed(
                (string) ($body['message'] ?? 'PayPal refused the refund.'),
                $amountCents,
            );
        }

        $status = (string) ($body['status'] ?? 'PENDING');

        return new RefundResult(
            accepted: $status !== 'FAILED',
            amountCents: $amountCents,
            gatewayRefundId: isset($body['id']) ? (string) $body['id'] : null,
            reference: isset($body['id']) ? (string) $body['id'] : null,
            failureReason: $status === 'FAILED' ? 'PayPal reported the refund as failed.' : null,
            isPending: $status === 'PENDING',
            payload: Payment::scrub(is_array($body) ? $body : []),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function verifyWebhook(PaymentGatewaySetting $setting, Request $request): ?array
    {
        $webhookId = $setting->credential('webhook_id');

        if ($webhookId === null) {
            return null;
        }

        $event = $request->json()->all();

        if (! is_array($event)) {
            return null;
        }

        // PayPal verifies its own signatures: the alternative is fetching and
        // caching their certificate chain ourselves, which is a worse idea
        // than one extra HTTPS call on a webhook.
        $response = $this->client($setting)->post(
            $this->base($setting).'/v1/notifications/verify-webhook-signature',
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

        return $response->json('verification_status') === 'SUCCESS' ? $event : null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function sessionIdFromEvent(array $event): ?string
    {
        $type = (string) ($event['event_type'] ?? '');

        if (! in_array($type, ['CHECKOUT.ORDER.APPROVED', 'PAYMENT.CAPTURE.COMPLETED'], true)) {
            return null;
        }

        $resource = $event['resource'] ?? [];

        if (isset($resource['id']) && $type === 'CHECKOUT.ORDER.APPROVED') {
            return (string) $resource['id'];
        }

        // A capture event points back at its order through a link.
        foreach ($resource['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') === 'up' && isset($link['href'])) {
                return basename((string) $link['href']);
            }
        }

        return null;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function testConnection(PaymentGatewaySetting $setting): array
    {
        try {
            $token = $this->accessToken($setting);
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => 'Could not reach PayPal: '.$exception->getMessage()];
        }

        return $token === null
            ? ['ok' => false, 'message' => 'PayPal refused those credentials.']
            : ['ok' => true, 'message' => 'Connected to PayPal.'];
    }

    private function capture(PaymentGatewaySetting $setting, string $orderId): PaymentResult
    {
        $response = $this->client($setting)
            ->withHeaders(['PayPal-Request-Id' => 'capture:'.$orderId])
            ->post($this->base($setting).'/v2/checkout/orders/'.$orderId.'/capture', []);

        $body = $response->json();

        if (! is_array($body)) {
            return new PaymentResult(status: PaymentStatus::Pending);
        }

        return new PaymentResult(
            status: ($body['status'] ?? '') === 'COMPLETED' ? PaymentStatus::Paid : PaymentStatus::Pending,
            gatewayPaymentId: $this->captureIdFrom($body),
            amountCents: $this->amountFrom($body),
            payload: Payment::scrub($body),
        );
    }

    private function accessToken(PaymentGatewaySetting $setting): ?string
    {
        $id = $setting->credential('client_id');
        $secret = $setting->credential('client_secret');

        if ($id === null || $secret === null) {
            throw GatewayError::missingCredentials(Gateway::PayPal);
        }

        $response = Http::asForm()
            ->withBasicAuth($id, $secret)
            ->timeout(20)
            ->post($this->base($setting).'/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        $token = $response->json('access_token');

        return is_string($token) ? $token : null;
    }

    private function client(PaymentGatewaySetting $setting): PendingRequest
    {
        $token = $this->accessToken($setting);

        if ($token === null) {
            throw GatewayError::unexpected(Gateway::PayPal, 'Those credentials were refused.');
        }

        return Http::withToken($token)
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 200, throw: false);
    }

    private function base(PaymentGatewaySetting $setting): string
    {
        return $setting->isTestMode()
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function linkFrom(array $body, string $rel): ?string
    {
        foreach ($body['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') === $rel && isset($link['href'])) {
                return (string) $link['href'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function captureIdFrom(array $body): ?string
    {
        $id = $body['purchase_units'][0]['payments']['captures'][0]['id'] ?? null;

        return is_string($id) ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function amountFrom(array $body): int
    {
        $value = $body['purchase_units'][0]['payments']['captures'][0]['amount']['value']
            ?? $body['purchase_units'][0]['amount']['value']
            ?? null;

        return $value === null ? 0 : (int) round(((float) $value) * 100);
    }

    /**
     * @param  mixed  $body
     * @return array<string, mixed>
     */
    private function decode($body, int $status): array
    {
        if (! is_array($body)) {
            throw GatewayError::unexpected(Gateway::PayPal, 'PayPal sent something that was not JSON.');
        }

        if ($status >= 400) {
            throw GatewayError::unexpected(
                Gateway::PayPal,
                (string) ($body['message'] ?? 'PayPal refused the request.'),
            );
        }

        return $body;
    }
}
