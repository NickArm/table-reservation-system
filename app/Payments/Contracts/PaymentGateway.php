<?php

declare(strict_types=1);

namespace App\Payments\Contracts;

use App\Enums\Gateway;
use App\Models\PaymentGatewaySetting;
use App\Payments\CheckoutRequest;
use App\Payments\CheckoutSession;
use App\Payments\PaymentResult;
use App\Payments\RefundResult;
use Illuminate\Http\Request;

/**
 * Everything the booking flow is allowed to know about taking money
 * (SPEC §7.4, §31.4).
 *
 * Stripe today; Viva Wallet and Cardlink are the obvious next two, because
 * most Greek venues already have one of those terminals and no Stripe
 * account. Adding one should mean writing a class and nothing else — if a
 * second gateway ever forces a change in here, this interface was wrong.
 *
 * Implementations talk to their gateway's REST API with Laravel's HTTP
 * client rather than a vendor SDK: the whole surface is five calls, it keeps
 * the application installable on shared hosting without composer gymnastics,
 * and swapping in an SDK later changes one class.
 */
interface PaymentGateway
{
    public function gateway(): Gateway;

    /**
     * Sends the guest somewhere to pay, or confirms immediately when there is
     * nothing to pay online.
     */
    public function startCheckout(PaymentGatewaySetting $setting, CheckoutRequest $request): CheckoutSession;

    /**
     * Asks the gateway what actually happened — the answer that counts when a
     * webhook is late, lost, or replayed (SPEC §24.2).
     */
    public function fetchPayment(PaymentGatewaySetting $setting, string $sessionId): PaymentResult;

    /**
     * Closes an abandoned checkout so a guest cannot pay for a spot whose
     * hold expired fifteen minutes ago (SPEC §24.1).
     */
    public function cancelCheckout(PaymentGatewaySetting $setting, string $sessionId): void;

    /**
     * Money back. The idempotency key is the caller's, so a double-clicked
     * button cannot refund twice.
     */
    public function refund(
        PaymentGatewaySetting $setting,
        string $gatewayPaymentId,
        int $amountCents,
        string $idempotencyKey,
    ): RefundResult;

    /**
     * Verifies the signature and returns the event, or null when it is not
     * genuine. Never trust an unverified webhook: anyone can post JSON.
     *
     * @return array<string, mixed>|null
     */
    public function verifyWebhook(PaymentGatewaySetting $setting, Request $request): ?array;

    /**
     * The gateway's own session id out of a verified webhook event, when the
     * event is one we care about.
     *
     * @param  array<string, mixed>  $event
     */
    public function sessionIdFromEvent(array $event): ?string;

    /**
     * A quick round trip to prove the keys work, for the button beside them
     * (SPEC §24.3).
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(PaymentGatewaySetting $setting): array;
}
