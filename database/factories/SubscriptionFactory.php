<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BillingProvider;
use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Subscription;
use Database\Factories\Concerns\UsesCurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    use UsesCurrentTenant;

    protected $model = Subscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => $this->currentTenantId(),
            'plan_id' => fn () => Plan::query()->first()?->id ?? Plan::factory(),
            'status' => TenantStatus::Active,
            'current_period_start' => now()->subDays(3),
            'current_period_end' => now()->addMonth(),
            'provider' => BillingProvider::Stripe,
            'price_cents' => 7000,
            'currency' => 'EUR',
        ];
    }

    public function lapsed(): static
    {
        return $this->state(fn (): array => [
            'current_period_start' => now()->subMonths(2),
            'current_period_end' => now()->subDay(),
        ]);
    }

    public function pastDue(): static
    {
        return $this->state(fn (): array => [
            'status' => TenantStatus::PastDue,
            'payment_failed_at' => now()->subDay(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'cancel_at_period_end' => true,
            'cancelled_at' => now(),
        ]);
    }

    public function paidByHand(): static
    {
        return $this->state(fn (): array => ['provider' => BillingProvider::Manual]);
    }
}
