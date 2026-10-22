<?php

declare(strict_types=1);

namespace App\Billing;

use App\Billing\Contracts\BillingGateway;
use App\Billing\Gateways\PayPalBilling;
use App\Billing\Gateways\StripeBilling;
use App\Enums\BillingProvider;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Finds the implementation for a provider.
 *
 * The only place that knows which billing providers exist. Note what is
 * missing compared with the venue-side GatewayManager: there is no lookup of
 * per-account settings, because these credentials are the platform's and
 * there is only one of us.
 */
final readonly class BillingManager
{
    public function __construct(private Container $container) {}

    public function for(BillingProvider $provider): BillingGateway
    {
        return $this->container->make(match ($provider) {
            BillingProvider::Stripe => StripeBilling::class,
            BillingProvider::PayPal => PayPalBilling::class,

            // Recorded by a person, not taken by a provider. There is nothing
            // to call, and asking for one is a programming mistake rather
            // than a runtime condition.
            BillingProvider::Manual => throw new InvalidArgumentException(
                'A manual subscription is recorded by hand and has no gateway.'
            ),
        });
    }

    /**
     * The providers an owner can actually pay with right now.
     *
     * Configured means keys present. A button for a provider with no keys
     * sends somebody to an error page with our name on it.
     *
     * @return array<int, BillingProvider>
     */
    public function available(): array
    {
        $out = [];

        foreach ([BillingProvider::Stripe, BillingProvider::PayPal] as $provider) {
            if ($this->for($provider)->isConfigured()) {
                $out[] = $provider;
            }
        }

        return $out;
    }

    /**
     * The one to use when nobody has chosen.
     */
    public function preferred(): ?BillingProvider
    {
        $configured = $this->available();

        if ($configured === []) {
            return null;
        }

        $wanted = BillingProvider::tryFrom((string) config('billing.provider'));

        return $wanted !== null && in_array($wanted, $configured, true)
            ? $wanted
            : $configured[0];
    }

    public function isTestMode(): bool
    {
        return config('billing.mode') !== 'live';
    }
}
