<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PriceRuleType;
use App\Enums\StructureScope;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\PriceRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One exception to the usual price (SPEC §7.2).
 *
 * Scopes reuse StructureScope: both answer the same question — booking type,
 * area, or one particular spot — and two enums with identical cases would
 * drift apart the first time somebody adds a fourth level.
 */
#[Fillable([
    'scope', 'scope_id', 'name',
    'days_of_week', 'date_from', 'date_to', 'time_from', 'time_to',
    'price_type', 'amount_cents', 'percent',
    'priority', 'is_active',
])]
class PriceRule extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PriceRuleFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => StructureScope::class,
            'price_type' => PriceRuleType::class,
            'days_of_week' => 'array',
            'date_from' => 'date',
            'date_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Does this rule apply to a booking starting at this moment?
     *
     * Every comparison happens in the venue's own time: "Saturday" and
     * "after 18:00" are wall-clock ideas, not instants (SPEC §3.5).
     */
    public function matches(CarbonImmutable $startsAt, string $timezone): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $local = $startsAt->setTimezone($timezone);

        return $this->matchesDay($local)
            && $this->matchesDate($local)
            && $this->matchesTime($local);
    }

    public function apply(int $baseCents): int
    {
        return $this->price_type->applyTo($baseCents, $this->amount_cents, $this->percent);
    }

    /**
     * How specific this rule is, for breaking a tie on priority: a rule about
     * one sunbed beats a rule about the whole beach.
     */
    public function specificity(): int
    {
        return match ($this->scope) {
            StructureScope::Resource => 3,
            StructureScope::Area => 2,
            StructureScope::BookingType => 1,
        };
    }

    /**
     * What the dashboard shows beside the rule, and what goes on the booking
     * page when a price is not the usual one.
     */
    public function describe(): string
    {
        if (filled($this->name)) {
            return (string) $this->name;
        }

        return $this->price_type === PriceRuleType::Fixed
            ? Money::format((int) $this->amount_cents)
            : sprintf('%+d%%', (int) $this->percent);
    }

    /**
     * @param  Builder<PriceRule>  $query
     * @param  array<int, int>  $areaIds
     * @param  array<int, int>  $resourceIds
     * @return Builder<PriceRule>
     */
    public function scopeCovering(
        Builder $query,
        int $bookingTypeId,
        array $areaIds = [],
        array $resourceIds = [],
    ): Builder {
        return $query
            ->where('is_active', true)
            ->where(function (Builder $query) use ($bookingTypeId, $areaIds, $resourceIds): void {
                $query->where(function (Builder $query) use ($bookingTypeId): void {
                    $query->where('scope', StructureScope::BookingType->value)
                        ->where('scope_id', $bookingTypeId);
                });

                if ($areaIds !== []) {
                    $query->orWhere(function (Builder $query) use ($areaIds): void {
                        $query->where('scope', StructureScope::Area->value)
                            ->whereIn('scope_id', $areaIds);
                    });
                }

                if ($resourceIds !== []) {
                    $query->orWhere(function (Builder $query) use ($resourceIds): void {
                        $query->where('scope', StructureScope::Resource->value)
                            ->whereIn('scope_id', $resourceIds);
                    });
                }
            });
    }

    private function matchesDay(CarbonImmutable $local): bool
    {
        $days = $this->days_of_week;

        if (blank($days)) {
            return true;
        }

        return in_array($local->dayOfWeek, array_map('intval', $days), true);
    }

    private function matchesDate(CarbonImmutable $local): bool
    {
        $date = $local->toDateString();

        if ($this->date_from !== null && $date < $this->date_from->toDateString()) {
            return false;
        }

        return $this->date_to === null || $date <= $this->date_to->toDateString();
    }

    private function matchesTime(CarbonImmutable $local): bool
    {
        if (blank($this->time_from) || blank($this->time_to)) {
            return true;
        }

        $time = $local->format('H:i');
        $from = substr((string) $this->time_from, 0, 5);
        $to = substr((string) $this->time_to, 0, 5);

        // A range that ends before it starts runs past midnight: "22:00 to
        // 02:00" is one late evening, not a contradiction.
        return $from <= $to
            ? $time >= $from && $time < $to
            : $time >= $from || $time < $to;
    }
}
