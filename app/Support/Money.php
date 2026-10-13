<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Money is stored and moved around as integer cents, everywhere (SPEC §31.2).
 * This is the only place it turns into something a person reads.
 *
 * Euro only for now, which is why there is no currency argument: a second
 * currency means tax, rounding and gateway rules too, and that is a decision,
 * not a parameter.
 */
final class Money
{
    public const SYMBOL = '€';

    /**
     * "€20" when it is whole, "€19.50" when it is not — because a price list
     * full of trailing zeroes is harder to read quickly.
     */
    public static function format(int $cents): string
    {
        $amount = $cents % 100 === 0
            ? number_format($cents / 100, 0, '.', ' ')
            : number_format($cents / 100, 2, '.', ' ');

        return self::SYMBOL.$amount;
    }

    /**
     * Always two decimals, for invoices and vouchers, where a column of
     * numbers has to line up.
     */
    public static function formatExact(int $cents): string
    {
        return self::SYMBOL.number_format($cents / 100, 2, '.', ' ');
    }

    /**
     * Euros as typed into a form, in cents. Rounded, never truncated: 19.99
     * arrives as the float 19.989999… and (int) would make that 1998.
     */
    public static function toCents(float|int|string $euros): int
    {
        return (int) round(((float) $euros) * 100);
    }
}
