<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Guest;
use App\Models\Venue;
use Database\Factories\Concerns\UsesCurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Guest>
 */
class GuestFactory extends Factory
{
    use UsesCurrentTenant;

    protected $model = Guest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => $this->currentTenantId(),
            'venue_id' => fn () => Venue::query()->first()?->id ?? Venue::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+3069'.fake()->numerify('########'),
            'locale' => 'en',
            'first_seen_at' => now()->subMonths(2),
            'last_seen_at' => now()->subDays(3),
        ];
    }

    public function regular(int $bookings = 4): static
    {
        return $this->state(fn (): array => [
            'bookings_count' => $bookings,
            'completed_count' => $bookings,
            'total_spent_cents' => $bookings * 2500,
            'tags' => ['regular'],
        ]);
    }

    public function troublesome(int $noShows = 2): static
    {
        return $this->state(fn (): array => [
            'bookings_count' => $noShows + 1,
            'no_show_count' => $noShows,
        ]);
    }

    public function anonymized(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Deleted guest',
            'email' => null,
            'phone' => null,
            'anonymized_at' => now(),
        ]);
    }
}
