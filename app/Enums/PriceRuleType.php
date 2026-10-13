<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a price rule changes the price (SPEC §7.2).
 */
enum PriceRuleType: string
{
    /** Replaces the price outright: "€35 on Saturdays". */
    case Fixed = 'fixed';

    /** Moves it: "+25% in August", "−20% in May". */
    case PercentModifier = 'percent_modifier';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'A set price',
            self::PercentModifier => 'More or less than usual',
        };
    }

    public function applyTo(int $baseCents, ?int $amountCents, ?int $percent): int
    {
        return match ($this) {
            self::Fixed => max(0, $amountCents ?? $baseCents),

            // Rounded half up, in cents, once — so a chain of percentages can
            // never drift the way repeated float maths does.
            self::PercentModifier => max(0, (int) round($baseCents * (100 + ($percent ?? 0)) / 100)),
        };
    }
}
