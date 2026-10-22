<?php

declare(strict_types=1);

namespace App\Billing\Contracts;

use App\Billing\BillingCheckout;
use App\Billing\BillingNotice;
use App\Billing\BillingState;
use App\Enums\BillingProvider;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * Everything the subscription flow is allowed to know about taking our money
 * (SPEC §4.3).
 *
 * Deliberately a different interface from PaymentGateway, which takes a
 * *venue's* money from a *guest*. The two look similar and mean opposite
 * things, and one shared interface would be an invitation to pass the wrong
 * credentials: a guest's dinner charged to our account, or our subscription
 * charged to theirs. Keeping them apart costs one small interface.
 *
 * Four calls, and each implementation talks to its provider over the HTTP
 * client rather than a vendor SDK — the same reasoning as the venue
 * gateways: a small surface, installable on shared hosting, and swapping in
 * an SDK later changes one class.
 */
interface BillingGateway
{
    public function provider(): BillingProvider;

    /**
     * Is this provider configured well enough to be offered at all?
     *
     * Checked before a button is drawn, so an owner never presses "pay" and
     * lands on a provider error about a missing key.
     */
    public function isConfigured(): bool;

    /**
     * Sends the owner somewhere to set up a recurring payment.
     *
     * Recurring on purpose: the provider renews it and tells us, rather than
     * us holding a card and charging it ourselves (SPEC §4.3).
     */
    public function startCheckout(Plan $plan, Tenant $tenant): BillingCheckout;

    /**
     * Asks the provider what actually happened.
     *
     * Called on the return from checkout and whenever a webhook is in doubt.
     * The redirect itself proves nothing — anybody can type the return URL.
     */
    public function fetch(string $reference): BillingState;

    /**
     * Stops it renewing at the provider's end.
     */
    public function cancel(string $subscriptionId): void;

    /**
     * Verifies the signature and translates the event, or returns null when
     * it is not genuine. Never trust an unverified webhook: anyone can post
     * JSON to a public address.
     */
    public function verify(Request $request): ?BillingNotice;
}
