<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Gateway;
use App\Enums\GatewayMode;
use App\Models\PaymentGatewaySetting;
use Database\Factories\Concerns\UsesCurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentGatewaySetting>
 */
class PaymentGatewaySettingFactory extends Factory
{
    use UsesCurrentTenant;

    protected $model = PaymentGatewaySetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => $this->currentTenantId(),
            'gateway' => Gateway::CashOnArrival,
            'mode' => GatewayMode::Live,
            'is_active' => true,
        ];
    }

    public function stripe(bool $active = true): static
    {
        return $this->state(fn (): array => [
            'gateway' => Gateway::Stripe,
            'mode' => GatewayMode::Test,
            'is_active' => $active,
            'credentials' => [
                'secret_key' => 'sk_test_'.fake()->regexify('[A-Za-z0-9]{24}'),
                'webhook_secret' => 'whsec_'.fake()->regexify('[A-Za-z0-9]{32}'),
            ],
            'statement_descriptor' => 'DEMO BEACH BAR',
        ]);
    }

    public function paypal(bool $active = true): static
    {
        return $this->state(fn (): array => [
            'gateway' => Gateway::PayPal,
            'mode' => GatewayMode::Test,
            'is_active' => $active,
            'credentials' => [
                'client_id' => 'AX'.fake()->regexify('[A-Za-z0-9]{30}'),
                'client_secret' => 'EK'.fake()->regexify('[A-Za-z0-9]{30}'),
                'webhook_id' => 'WH-'.fake()->regexify('[A-Z0-9]{17}'),
            ],
        ]);
    }

    public function live(): static
    {
        return $this->state(fn (): array => ['mode' => GatewayMode::Live]);
    }
}
