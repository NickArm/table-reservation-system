<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PriceRuleType;
use App\Enums\StructureScope;
use App\Models\BookingType;
use App\Models\PriceRule;
use Database\Factories\Concerns\UsesCurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceRule>
 */
class PriceRuleFactory extends Factory
{
    use UsesCurrentTenant;

    protected $model = PriceRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => $this->currentTenantId(),
            'scope' => StructureScope::BookingType,
            'scope_id' => fn () => BookingType::query()->first()?->id ?? BookingType::factory(),
            'name' => 'Weekend rate',
            'price_type' => PriceRuleType::PercentModifier,
            'percent' => 30,
            'priority' => 10,
            'is_active' => true,
        ];
    }

    public function fixed(int $cents): static
    {
        return $this->state(fn (): array => [
            'price_type' => PriceRuleType::Fixed,
            'amount_cents' => $cents,
            'percent' => null,
        ]);
    }

    public function percent(int $percent): static
    {
        return $this->state(fn (): array => [
            'price_type' => PriceRuleType::PercentModifier,
            'percent' => $percent,
            'amount_cents' => null,
        ]);
    }

    /**
     * @param  array<int, int>  $days
     */
    public function onDays(array $days): static
    {
        return $this->state(fn (): array => ['days_of_week' => $days]);
    }

    public function between(string $from, string $to): static
    {
        return $this->state(fn (): array => [
            'time_from' => $from,
            'time_to' => $to,
        ]);
    }

    public function season(string $from, string $to): static
    {
        return $this->state(fn (): array => [
            'date_from' => $from,
            'date_to' => $to,
        ]);
    }

    public function forScope(StructureScope $scope, int $id): static
    {
        return $this->state(fn (): array => [
            'scope' => $scope,
            'scope_id' => $id,
        ]);
    }

    public function priority(int $priority): static
    {
        return $this->state(fn (): array => ['priority' => $priority]);
    }
}
