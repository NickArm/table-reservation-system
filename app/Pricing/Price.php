<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Enums\PriceBasis;
use App\Models\PriceRule;
use App\Support\Money;

/**
 * What one booking costs, and why (SPEC §7).
 *
 * Carries the rule that decided it, so the booking page can say "Weekend
 * rate" instead of silently charging more than the guest expected.
 */
final readonly class Price
{
    public function __construct(
        public int $baseCents,
        public int $unitCents,
        public int $totalCents,
        public PriceBasis $basis,
        public ?PriceRule $rule = null,
    ) {}

    public function wasAdjusted(): bool
    {
        return $this->unitCents !== $this->baseCents;
    }

    public function isFree(): bool
    {
        return $this->totalCents === 0;
    }

    /** "€30 each", "€12 per person". */
    public function label(): string
    {
        return Money::format($this->unitCents).' '.$this->basis->suffix();
    }

    /** "€30 each · Weekend rate" when a rule changed it. */
    public function describe(): string
    {
        $label = $this->label();

        return $this->rule === null
            ? $label
            : $label.' · '.$this->rule->describe();
    }
}
