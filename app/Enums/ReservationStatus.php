<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The life of a reservation (SPEC §8.2).
 *
 * Only `confirmed`, `checked_in` and `completed` hold a resource. Everything
 * else has either not claimed it yet or has let it go — which is exactly what
 * the availability engine asks of this enum.
 */
enum ReservationStatus: string
{
    case PendingPayment = 'pending_payment';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case Completed = 'completed';
    case CancelledByVenue = 'cancelled_by_venue';
    case NoShow = 'no_show';
    case Expired = 'expired';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    /**
     * The statuses that occupy a table or a sunbed, and therefore block
     * someone else from booking it.
     *
     * A no-show still blocks: the venue held the spot and was stood up, and
     * freeing it retroactively would rewrite history.
     *
     * @return array<int, string>
     */
    public static function blocking(): array
    {
        return [
            self::PendingPayment->value,
            self::Confirmed->value,
            self::CheckedIn->value,
            self::Completed->value,
            self::NoShow->value,
            self::PartiallyRefunded->value,
        ];
    }

    public function blocksAvailability(): bool
    {
        return in_array($this->value, self::blocking(), true);
    }

    public function isCancelled(): bool
    {
        return match ($this) {
            self::CancelledByVenue, self::Expired, self::Refunded => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Awaiting payment',
            self::Confirmed => 'Confirmed',
            self::CheckedIn => 'Checked in',
            self::Completed => 'Completed',
            self::CancelledByVenue => 'Cancelled',
            self::NoShow => 'No show',
            self::Expired => 'Expired',
            self::Refunded => 'Refunded',
            self::PartiallyRefunded => 'Partially refunded',
        };
    }
}
