<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where the money for one reservation stands (SPEC §8.3).
 *
 * Separate from the reservation's own status on purpose: a booking can be
 * confirmed and unpaid (cash on arrival), or cancelled and still paid until
 * the venue decides what to refund.
 */
enum PaymentStatus: string
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Paid = 'paid';
    case PayOnArrival = 'pay_on_arrival';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Failed = 'failed';

    public function isSettled(): bool
    {
        return match ($this) {
            self::Paid, self::NotRequired, self::PayOnArrival => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::NotRequired => 'No payment',
            self::Pending => 'Awaiting payment',
            self::Paid => 'Paid',
            self::PayOnArrival => 'Pay at the venue',
            self::RefundPending => 'Refund in progress',
            self::Refunded => 'Refunded',
            self::PartiallyRefunded => 'Partially refunded',
            self::Failed => 'Payment failed',
        };
    }
}
