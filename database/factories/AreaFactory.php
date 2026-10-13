<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Area;
use App\Models\BookingType;
use Database\Factories\Concerns\UsesCurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Area>
 */
class AreaFactory extends Factory
{
    use UsesCurrentTenant;

    protected $model = Area::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => $this->currentTenantId(),
            'booking_type_id' => fn () => BookingType::query()->first()?->id ?? BookingType::factory(),
            'name' => fake()->randomElement(['1st Row', '2nd Row', 'Back Row', 'Terrace', 'Main Hall']),
            'sort_order' => 0,
        ];
    }
}
