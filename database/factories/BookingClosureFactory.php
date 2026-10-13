<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StructureScope;
use App\Models\BookingClosure;
use App\Models\BookingType;
use Carbon\CarbonImmutable;
use Database\Factories\Concerns\UsesCurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingClosure>
 */
class BookingClosureFactory extends Factory
{
    use UsesCurrentTenant;

    protected $model = BookingClosure::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = CarbonImmutable::now()->addWeek()->toDateString();

        return [
            'tenant_id' => $this->currentTenantId(),
            'scope' => StructureScope::BookingType,
            'scope_id' => fn () => BookingType::query()->first()?->id ?? BookingType::factory(),
            'date_from' => $date,
            'date_to' => $date,
            'reason' => 'Private event',
        ];
    }

    public function on(string $date, ?string $until = null): static
    {
        return $this->state(fn (): array => [
            'date_from' => $date,
            'date_to' => $until ?? $date,
        ]);
    }

    public function between(string $from, string $to): static
    {
        return $this->state(fn (): array => [
            'time_from' => $from,
            'time_to' => $to,
        ]);
    }

    public function forScope(StructureScope $scope, int $id): static
    {
        return $this->state(fn (): array => [
            'scope' => $scope,
            'scope_id' => $id,
        ]);
    }
}
