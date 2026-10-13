<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BookingMode;
use App\Enums\Occupancy;
use App\Enums\PaymentRequirement;
use App\Enums\PriceBasis;
use App\Enums\ResourceType;
use App\Models\BookingType;
use App\Models\Venue;
use Database\Factories\Concerns\UsesCurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BookingType>
 */
class BookingTypeFactory extends Factory
{
    use UsesCurrentTenant;

    protected $model = BookingType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'tenant_id' => $this->currentTenantId(),
            'venue_id' => fn () => Venue::query()->first()?->id ?? Venue::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'resource_type' => ResourceType::Table,
            'booking_mode' => BookingMode::Slot,
            'slot_duration_minutes' => 90,
            'slot_interval_minutes' => 30,
            'turnover_buffer_minutes' => 15,
            'occupancy' => Occupancy::FixedDuration,
            'max_stay_minutes' => 90,
            'min_lead_time_minutes' => 60,
            'max_advance_days' => 60,
            'hold_duration_minutes' => 15,
            'payment_requirement' => PaymentRequirement::Required,
            'default_price_cents' => 2000,
            'price_basis' => PriceBasis::PerResource,
            'default_view' => 'list',
            'is_active' => true,
        ];
    }

    public function sunbeds(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Beach Sunbeds',
            'resource_type' => ResourceType::SunbedSet,
            'booking_mode' => BookingMode::ArrivalWindow,
            'arrival_window_start' => '10:00',
            'arrival_window_end' => '13:00',
            'occupancy' => Occupancy::WholeDay,
            'max_stay_minutes' => null,
            'deposit_label' => 'Deducted from your final bill',
        ]);
    }
}
