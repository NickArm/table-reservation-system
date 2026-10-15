<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OnlineStatus;
use App\Enums\PriceBasis;
use App\Models\Area;
use App\Models\BookingType;
use App\Models\Resource;
use Database\Factories\Concerns\UsesCurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Resource>
 */
class ResourceFactory extends Factory
{
    use UsesCurrentTenant;

    protected $model = Resource::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $area = fn () => Area::query()->first() ?? Area::factory()->create();

        return [
            'tenant_id' => $this->currentTenantId(),
            'area_id' => fn () => $area()->id,
            'booking_type_id' => function () use ($area) {
                return $area()->booking_type_id
                    ?? (BookingType::query()->first()?->id ?? BookingType::factory());
            },
            'label' => Str::upper(Str::random(2)).fake()->unique()->numberBetween(1, 999),
            'is_composite' => false,
            'capacity_min' => 1,
            'capacity_max' => 4,
            'base_price_cents' => 2000,
            'price_basis' => PriceBasis::PerResource,
            'online_status' => OnlineStatus::Open,
            'is_active' => true,
        ];
    }

    /**
     * A sunbed set: the thing the guest books as one unit, two sunbeds and an
     * umbrella. Named `composite` rather than `set` because `Factory::set()`
     * already exists and overriding it is a fatal error.
     */
    public function composite(): static
    {
        return $this->state(fn (): array => [
            'is_composite' => true,
            'components' => [
                ['label' => 'Sunbed', 'qty' => 2],
                ['label' => 'Umbrella', 'qty' => 1],
                ['label' => 'Side table', 'qty' => 1],
            ],
            'capacity_min' => 1,
            'capacity_max' => 4,
            'base_price_cents' => 2500,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'online_status' => OnlineStatus::Closed,
        ]);
    }
}
