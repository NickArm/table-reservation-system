<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a venue takes money from its guests (SPEC §7.4).
 *
 * The money goes straight to the venue's own account with its own keys — we
 * never hold it. That is a deliberate position, not a shortcut: it keeps us
 * out of the payments business and the venue's tax affairs its own.
 */
enum Gateway: string
{
    case Stripe = 'stripe';
    case PayPal = 'paypal';
    case CashOnArrival = 'cash_on_arrival';

    public function label(): string
    {
        return match ($this) {
            self::Stripe => 'Stripe',
            self::PayPal => 'PayPal',
            self::CashOnArrival => 'Pay at the venue',
        };
    }

    /** Takes the guest somewhere else to pay. */
    public function isOnline(): bool
    {
        return $this !== self::CashOnArrival;
    }

    public function needsCredentials(): bool
    {
        return $this->isOnline();
    }

    /**
     * Greek venues often have a card terminal and no Stripe account at all,
     * which is why cash is a first-class option rather than a fallback.
     */
    public function describe(): string
    {
        return match ($this) {
            self::Stripe => 'Card payments through your own Stripe account.',
            self::PayPal => 'PayPal, through your own business account.',
            self::CashOnArrival => 'No online payment — the guest settles with you.',
        };
    }
}
