<?php

declare(strict_types=1);

namespace App\Payments;

use App\Enums\Gateway;
use App\Models\PaymentGatewaySetting;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Exceptions\GatewayError;
use App\Payments\Gateways\CashOnArrivalGateway;
use App\Payments\Gateways\PayPalGateway;
use App\Payments\Gateways\StripeGateway;
use Illuminate\Contracts\Container\Container;

/**
 * Finds the right implementation, and the venue's settings for it.
 *
 * The only place in the application that knows which gateways exist. Adding
 * Viva Wallet means one line here and one new class (SPEC §31.4).
 */
final readonly class GatewayManager
{
    public function __construct(private Container $container) {}

    public function for(Gateway $gateway): PaymentGateway
    {
        return $this->container->make(match ($gateway) {
            Gateway::Stripe => StripeGateway::class,
            Gateway::PayPal => PayPalGateway::class,
            Gateway::CashOnArrival => CashOnArrivalGateway::class,
        });
    }

    /**
     * The gateway a venue is actually set up to use.
     *
     * Only one can be active at a time in v1: a guest who is offered a choice
     * of three payment buttons is a guest who closes the tab (§1.1).
     */
    public function settingsFor(?Gateway $gateway = null): ?PaymentGatewaySetting
    {
        $query = PaymentGatewaySetting::query()->where('is_active', true);

        if ($gateway !== null) {
            $query->where('gateway', $gateway->value);
        }

        // Cash comes last: if a venue has set up a card gateway, that is what
        // it wants used.
        return $query->get()
            ->sortBy(static fn (PaymentGatewaySetting $setting): int => $setting->gateway->isOnline() ? 0 : 1)
            ->first();
    }

    /**
     * @return array{0: PaymentGateway, 1: PaymentGatewaySetting}
     */
    public function resolve(?Gateway $gateway = null): array
    {
        $setting = $this->settingsFor($gateway);

        if ($setting === null) {
            throw GatewayError::notConfigured($gateway ?? Gateway::CashOnArrival);
        }

        if (! $setting->isUsable()) {
            throw GatewayError::missingCredentials($setting->gateway);
        }

        return [$this->for($setting->gateway), $setting];
    }

    public function isConfigured(): bool
    {
        return $this->settingsFor() !== null;
    }
}
