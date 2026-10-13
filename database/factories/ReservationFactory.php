<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Channel;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Area;
use App\Models\BookingType;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Database\Factories\Concerns\UsesCurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
{
    use UsesCurrentTenant;

    protected $model = Reservation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = CarbonImmutable::now()->addDay()->setTime(19, 0);

        return [
            'tenant_id' => $this->currentTenantId(),
            'venue_id' => fn () => Venue::query()->first()?->id ?? Venue::factory(),
            'booking_type_id' => fn () => BookingType::query()->first()?->id ?? BookingType::factory(),
            'area_id' => fn () => Area::query()->first()?->id,
            'resource_id' => fn () => Resource::query()->first()?->id,
            'customer_name' => fake()->name(),
            'customer_email' => fake()->unique()->safeEmail(),
            'customer_phone' => '+3069'.fake()->numerify('########'),
            'locale' => 'en',
            'party_size' => fake()->numberBetween(2, 4),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(90),
            'status' => ReservationStatus::Confirmed,
            'amount_cents' => 2000,
            'payment_status' => PaymentStatus::Paid,
            'channel' => Channel::Website,
        ];
    }

    public function at(CarbonImmutable $startsAt, CarbonImmutable $endsAt): static
    {
        return $this->state(fn (): array => [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }

    public function forResource(Resource $resource): static
    {
        return $this->state(fn (): array => [
            'booking_type_id' => $resource->booking_type_id,
            'area_id' => $resource->area_id,
            'resource_id' => $resource->id,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => ReservationStatus::CancelledByVenue,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Cancelled by the venue',
        ]);
    }

    public function noShow(): static
    {
        return $this->state(fn (): array => [
            'status' => ReservationStatus::NoShow,
        ]);
    }

    public function checkedIn(): static
    {
        return $this->state(fn (): array => [
            'status' => ReservationStatus::CheckedIn,
            'checked_in_at' => now(),
        ]);
    }

    public function pendingPayment(): static
    {
        return $this->state(fn (): array => [
            'status' => ReservationStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
        ]);
    }
}
