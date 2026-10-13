<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Subscription lifecycle of a venue account.
 *
 * SPEC §4.3 — trialing → active → past_due → grace → expired, with suspended
 * reserved for an account the platform has switched off by hand.
 */
enum TenantStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Grace = 'grace';
    case Expired = 'expired';
    case Suspended = 'suspended';

    /**
     * May the owner still reach the dashboard?
     *
     * Expired accounts keep read access on purpose (SPEC §4.4): they have to be
     * able to see their data and pay to come back.
     */
    public function canUseDashboard(): bool
    {
        return $this !== self::Suspended;
    }

    /**
     * May the public page take new bookings?
     */
    public function acceptsBookings(): bool
    {
        return match ($this) {
            self::Trialing, self::Active, self::PastDue, self::Grace => true,
            self::Expired, self::Suspended => false,
        };
    }

    /**
     * Read-only means the dashboard loads but nothing can be changed.
     */
    public function isReadOnly(): bool
    {
        return $this === self::Expired;
    }

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Trial',
            self::Active => 'Active',
            self::PastDue => 'Payment failed',
            self::Grace => 'Grace period',
            self::Expired => 'Expired',
            self::Suspended => 'Suspended',
        };
    }
}
