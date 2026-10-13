<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether the price is for the thing or for each person on it (SPEC §7.1).
 */
enum PriceBasis: string
{
    case PerResource = 'per_resource';
    case PerPerson = 'per_person';

    public function label(): string
    {
        return match ($this) {
            self::PerResource => 'Per table or set',
            self::PerPerson => 'Per person',
        };
    }

    /** Reads after a price: "€20 each", "€12 per person". */
    public function suffix(): string
    {
        return match ($this) {
            self::PerResource => 'each',
            self::PerPerson => 'per person',
        };
    }

    public function totalCents(int $unitCents, int $partySize): int
    {
        return match ($this) {
            self::PerResource => $unitCents,
            self::PerPerson => $unitCents * max(1, $partySize),
        };
    }
}
