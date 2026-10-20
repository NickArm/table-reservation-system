<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BillingPeriod;
use App\Enums\Feature;
use App\Enums\PlanLimit;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'billing_period' => BillingPeriod::Monthly,
            'price_cents' => 7000,
            'currency' => 'EUR',
            'trial_days' => 15,

            // Generous by default. A test that cares about a limit sets it,
            // and one that does not should never trip over one.
            'limits' => [
                PlanLimit::Venues->value => 1,
                PlanLimit::BookingTypes->value => null,
                PlanLimit::Resources->value => null,
                PlanLimit::ReservationsPerMonth->value => null,
                PlanLimit::StaffUsers->value => null,
            ],
            'features' => array_fill_keys(
                array_map(static fn (Feature $f): string => $f->value, Feature::cases()),
                true,
            ),
            'is_public' => true,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function yearly(): static
    {
        return $this->state(fn (): array => [
            'billing_period' => BillingPeriod::Yearly,
            'price_cents' => 50000,
        ]);
    }

    public function free(): static
    {
        return $this->state(fn (): array => ['price_cents' => 0]);
    }

    /**
     * @param  array<string, int|null>  $limits
     */
    public function limited(array $limits): static
    {
        return $this->state(fn (array $attributes): array => [
            'limits' => [...$attributes['limits'], ...$limits],
        ]);
    }

    /**
     * @param  array<int, Feature>  $features
     */
    public function without(array $features): static
    {
        return $this->state(fn (array $attributes): array => [
            'features' => [
                ...$attributes['features'],
                ...array_fill_keys(
                    array_map(static fn (Feature $f): string => $f->value, $features),
                    false,
                ),
            ],
        ]);
    }
}
