<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PriceBasis;
use App\Models\ResourceTemplate;
use Database\Factories\Concerns\UsesCurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResourceTemplate>
 */
class ResourceTemplateFactory extends Factory
{
    use UsesCurrentTenant;

    protected $model = ResourceTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => $this->currentTenantId(),
            'name' => 'Beach Set',
            'is_composite' => true,
            'components' => [
                ['label' => 'Sunbed', 'qty' => 2],
                ['label' => 'Umbrella', 'qty' => 1],
                ['label' => 'Side table', 'qty' => 1],
            ],
            'capacity_min' => 1,
            'capacity_max' => 4,
            'base_price_cents' => 2500,
            'price_basis' => PriceBasis::PerResource,
        ];
    }

    public function single(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Single Sunbed',
            'is_composite' => false,
            'components' => null,
            'capacity_max' => 1,
            'base_price_cents' => 800,
        ]);
    }
}
