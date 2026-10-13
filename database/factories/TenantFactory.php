<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'uuid' => (string) Str::uuid7(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'status' => TenantStatus::Active,
            'timezone' => 'Europe/Athens',
            'locale' => 'en',
            'currency' => 'EUR',
        ];
    }

    public function trialing(): static
    {
        return $this->state(fn (): array => [
            'status' => TenantStatus::Trialing,
            'trial_ends_at' => now()->addDays(config('tenancy.trial_days')),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => TenantStatus::Expired,
        ]);
    }
}
