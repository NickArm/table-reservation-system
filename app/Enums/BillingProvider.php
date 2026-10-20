<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who takes the platform's money (SPEC §4.3).
 *
 * Not to be confused with App\Enums\Gateway, which is how a *venue* charges
 * its own guests with its own keys. That money never passes through us; this
 * money is ours. The two live in different tables with different names on
 * purpose — a single place where the two got mixed up would mean charging a
 * venue's guests into our account, or our subscription into theirs.
 */
enum BillingProvider: string
{
    case Stripe = 'stripe';
    case PayPal = 'paypal';

    /** Paid outside the system — a bank deposit, recorded by hand. */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Stripe => 'Card',
            self::PayPal => 'PayPal',
            self::Manual => 'Paid directly',
        };
    }

    /** Does the provider renew it on its own? */
    public function renewsItself(): bool
    {
        return $this !== self::Manual;
    }
}
