<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * How often a plan is charged (SPEC §4.1).
 *
 * The enum owns the date arithmetic, because "a month later" is the kind of
 * thing that gets written slightly differently in four places and then
 * disagrees about the 31st.
 */
enum BillingPeriod: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Yearly => 'Yearly',
        };
    }

    /** "per month" / "per year", for a price line. */
    public function per(): string
    {
        return match ($this) {
            self::Monthly => 'per month',
            self::Yearly => 'per year',
        };
    }

    /**
     * The end of a period that starts at this moment.
     *
     * Carbon's addMonth clamps rather than overflowing — the 31st of January
     * plus a month is the 28th of February, not the 3rd of March — which is
     * the behaviour a subscription wants. Billing on the 31st then bills on
     * the 28th in February and back on the 31st in March, because each period
     * is measured from the one before rather than from the clamped date.
     */
    public function endFrom(CarbonImmutable $start): CarbonImmutable
    {
        return match ($this) {
            self::Monthly => $start->addMonthNoOverflow(),
            self::Yearly => $start->addYearNoOverflow(),
        };
    }

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Yearly => 12,
        };
    }
}
