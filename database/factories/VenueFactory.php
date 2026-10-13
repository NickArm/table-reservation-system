<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Venue;
use Database\Factories\Concerns\UsesCurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Venue>
 */
class VenueFactory extends Factory
{
    use UsesCurrentTenant;

    protected $model = Venue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => $this->currentTenantId(),
            'name' => fake()->company(),
            'timezone' => 'Europe/Athens',
            'country' => 'GR',
            'accent_color' => '#0E6E6E',
        ];
    }

    public function complete(): static
    {
        return $this->state(fn (): array => [
            'description' => fake()->paragraph(),
            'address' => fake()->streetAddress(),
            'city' => 'Corfu',
            'postal_code' => '49100',
            'region' => 'Ionian Islands',
            'latitude' => 39.6243,
            'longitude' => 19.9217,
            'phone' => '+302661000000',
            'venue_category' => 'beach_bar',
            'price_range' => '€€',
            'hero_path' => 'venues/demo/hero.jpg',
        ]);
    }
}
