<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Gateway;
use App\Enums\GatewayMode;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Reservation;
use Database\Factories\Concerns\UsesCurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    use UsesCurrentTenant;

    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => $this->currentTenantId(),
            'reservation_id' => fn () => Reservation::query()->first()?->id ?? Reservation::factory(),
            'gateway' => Gateway::Stripe,
            'mode' => GatewayMode::Test,
            'session_id' => 'cs_test_'.fake()->regexify('[A-Za-z0-9]{24}'),
            'amount_cents' => 2500,
            'currency' => 'EUR',
            'status' => PaymentStatus::Pending,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Paid,
            'gateway_payment_id' => 'pi_'.fake()->regexify('[A-Za-z0-9]{24}'),
            'paid_at' => now(),
        ]);
    }

    public function forReservation(Reservation $reservation): static
    {
        return $this->state(fn (): array => [
            'reservation_id' => $reservation->id,
            'amount_cents' => $reservation->amount_cents,
        ]);
    }

    public function cash(): static
    {
        return $this->state(fn (): array => [
            'gateway' => Gateway::CashOnArrival,
            'mode' => GatewayMode::Live,
        ]);
    }
}
