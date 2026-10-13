<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OpeningHour;
use App\Models\Venue;
use Database\Factories\Concerns\UsesCurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpeningHour>
 */
class OpeningHourFactory extends Factory
{
    use UsesCurrentTenant;

    protected $model = OpeningHour::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => $this->currentTenantId(),
            'scope' => OpeningHour::SCOPE_VENUE,
            'scope_id' => fn () => Venue::query()->first()?->id ?? Venue::factory()->create()->id,
            'day_of_week' => fake()->numberBetween(0, 6),
            'opens_at' => '09:00',
            'closes_at' => '19:00',
            'closes_next_day' => false,
        ];
    }
}
