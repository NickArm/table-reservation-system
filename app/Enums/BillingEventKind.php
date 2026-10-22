<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A verified billing webhook, reduced to the things we act on (SPEC §4.3).
 *
 * Each provider has its own vocabulary — `invoice.paid`,
 * `PAYMENT.SALE.COMPLETED`, `BILLING.SUBSCRIPTION.CANCELLED` — and nothing
 * outside the gateway class should have to learn either. The gateway
 * translates into these four; everything downstream reads only these.
 */
enum BillingEventKind: string
{
    case Paid = 'paid';
    case PaymentFailed = 'payment_failed';
    case Cancelled = 'cancelled';

    /** Genuine and verified, but about something we do not act on. */
    case Ignored = 'ignored';
}
