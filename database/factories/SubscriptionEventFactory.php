<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubscriptionEventType;
use App\Models\SubscriptionEvent;
use Database\Factories\Concerns\UsesCurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionEvent>
 */
class SubscriptionEventFactory extends Factory
{
    use UsesCurrentTenant;

    protected $model = SubscriptionEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => $this->currentTenantId(),
            'subscription_id' => null,
            'plan_name' => 'Monthly',
            'type' => SubscriptionEventType::Renewed,
            'summary' => 'Renewed for another month.',
            'amount_cents' => 7000,
            'currency' => 'EUR',
            'occurred_at' => now(),
        ];
    }
}
