<?php

declare(strict_types=1);

namespace App\Payments\Gateways;

use App\Enums\Gateway;
use App\Enums\PaymentStatus;
use App\Models\PaymentGatewaySetting;
use App\Payments\CheckoutRequest;
use App\Payments\CheckoutSession;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\PaymentResult;
use App\Payments\RefundResult;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * No gateway at all — the guest pays at the venue (SPEC §7.4).
 *
 * Not a placeholder. Plenty of Greek venues have a card terminal from their
 * bank and no Stripe account, and telling them to open one is how a sale is
 * lost. This keeps the booking flow identical for them.
 */
final class CashOnArrivalGateway implements PaymentGateway
{
    public function gateway(): Gateway
    {
        return Gateway::CashOnArrival;
    }

    public function startCheckout(PaymentGatewaySetting $setting, CheckoutRequest $request): CheckoutSession
    {
        // Nowhere to send anybody: the booking is confirmed on the spot and
        // the money is the venue's business.
        return new CheckoutSession(
            id: 'cash_'.Str::lower(Str::random(24)),
            url: null,
        );
    }

    public function fetchPayment(PaymentGatewaySetting $setting, string $sessionId): PaymentResult
    {
        return new PaymentResult(
            status: PaymentStatus::PayOnArrival,
            gatewayPaymentId: $sessionId,
        );
    }

    public function cancelCheckout(PaymentGatewaySetting $setting, string $sessionId): void
    {
        // Nothing was opened, so nothing can be closed.
    }

    public function refund(
        PaymentGatewaySetting $setting,
        string $gatewayPaymentId,
        int $amountCents,
        string $idempotencyKey,
    ): RefundResult {
        // The venue hands back cash itself; the system only records that it
        // happened, and immediately — there is nothing to wait for.
        return new RefundResult(
            accepted: true,
            amountCents: $amountCents,
            gatewayRefundId: 'cash_refund_'.Str::lower(Str::random(16)),
            isPending: false,
        );
    }

    public function verifyWebhook(PaymentGatewaySetting $setting, Request $request): ?array
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function sessionIdFromEvent(array $event): ?string
    {
        return null;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function testConnection(PaymentGatewaySetting $setting): array
    {
        return ['ok' => true, 'message' => 'Nothing to connect — guests pay you directly.'];
    }
}
