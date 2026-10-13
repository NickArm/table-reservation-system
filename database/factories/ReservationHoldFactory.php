<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BookingType;
use App\Models\ReservationHold;
use App\Models\Resource;
use Carbon\CarbonImmutable;
use Database\Factories\Concerns\UsesCurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReservationHold>
 */
class ReservationHoldFactory extends Factory
{
    use UsesCurrentTenant;

    protected $model = ReservationHold::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = CarbonImmutable::now()->addDay()->setTime(19, 0);

        return [
            'tenant_id' => $this->currentTenantId(),
            'booking_type_id' => fn () => BookingType::query()->first()?->id ?? BookingType::factory(),
            'resource_id' => fn () => Resource::query()->first()?->id ?? Resource::factory(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(90),
            'party_size' => 2,
            'session_token' => Str::random(40),
            'expires_at' => now()->addMinutes(15),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function forResource(Resource $resource): static
    {
        return $this->state(fn (): array => [
            'booking_type_id' => $resource->booking_type_id,
            'resource_id' => $resource->id,
        ]);
    }
}
