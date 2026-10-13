<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Enums\StructureScope;
use App\Models\Area;
use App\Models\PriceRule;
use App\Models\Resource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Every price rule that could apply to a booking type, loaded once and asked
 * many times (SPEC §7.2).
 *
 * The same shape as ClosureSet and BusyIndex, for the same reason: a booking
 * page prices twenty sunbeds against twenty arrival times, and none of that
 * should touch the database.
 */
final class PriceBook
{
    /** @var array<string, array<int, PriceRule>> */
    private array $rules = [];

    /**
     * @param  Collection<int, PriceRule>  $rules
     */
    public function __construct(Collection $rules, private readonly string $timezone)
    {
        foreach ($rules as $rule) {
            $this->rules[$this->key($rule->scope, (int) $rule->scope_id)][] = $rule;
        }
    }

    public static function empty(string $timezone = 'UTC'): self
    {
        return new self(new Collection, $timezone);
    }

    /**
     * The price of one spot at one time.
     *
     * Exactly one rule wins — the highest priority among those that match,
     * narrowest scope first on a tie. Rules do not stack: an owner has to be
     * able to point at a price and say which rule made it (SPEC §1.1).
     */
    public function priceFor(
        Resource $resource,
        ?Area $area,
        CarbonImmutable $startsAt,
        int $partySize,
    ): Price {
        $base = (int) $resource->base_price_cents;
        $winner = $this->winningRule($resource, $area, $startsAt);

        $unit = $winner === null ? $base : $winner->apply($base);

        return new Price(
            baseCents: $base,
            unitCents: $unit,
            totalCents: $resource->price_basis->totalCents($unit, $partySize),
            basis: $resource->price_basis,
            rule: $winner,
        );
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }

    private function winningRule(Resource $resource, ?Area $area, CarbonImmutable $startsAt): ?PriceRule
    {
        $candidates = [
            ...$this->rules[$this->key(StructureScope::Resource, (int) $resource->id)] ?? [],
            ...$area === null ? [] : $this->rules[$this->key(StructureScope::Area, (int) $area->id)] ?? [],
            ...$this->rules[$this->key(StructureScope::BookingType, (int) $resource->booking_type_id)] ?? [],
        ];

        $winner = null;

        foreach ($candidates as $rule) {
            if (! $rule->matches($startsAt, $this->timezone)) {
                continue;
            }

            if ($winner === null || $this->beats($rule, $winner)) {
                $winner = $rule;
            }
        }

        return $winner;
    }

    private function beats(PriceRule $rule, PriceRule $winner): bool
    {
        if ($rule->priority !== $winner->priority) {
            return $rule->priority > $winner->priority;
        }

        if ($rule->specificity() !== $winner->specificity()) {
            return $rule->specificity() > $winner->specificity();
        }

        // Still tied: the one added last, which is the one the owner was
        // thinking about most recently.
        return $rule->id > $winner->id;
    }

    private function key(StructureScope $scope, int $id): string
    {
        return $scope->value.':'.$id;
    }
}
