<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The things that happen to a subscription (SPEC §4.3).
 *
 * Each one is a line in the owner's billing history and, where a person did
 * it, a line in the platform's audit trail.
 */
enum SubscriptionEventType: string
{
    case Started = 'started';
    case Renewed = 'renewed';
    case PlanChanged = 'plan_changed';
    case PaymentFailed = 'payment_failed';
    case PaymentRecovered = 'payment_recovered';
    case EnteredGrace = 'entered_grace';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Resumed = 'resumed';
    case TrialEnded = 'trial_ended';

    /** Extended, granted or marked paid by hand (SPEC §4.1). */
    case AdjustedByHand = 'adjusted_by_hand';

    /**
     * Did a person do this, rather than a gateway or the nightly sweep?
     *
     * The answer decides whether a reason is required, and whether the line
     * carries a name.
     */
    public function needsAnActor(): bool
    {
        return $this === self::AdjustedByHand;
    }

    public function label(): string
    {
        return match ($this) {
            self::Started => 'Subscription started',
            self::Renewed => 'Renewed',
            self::PlanChanged => 'Plan changed',
            self::PaymentFailed => 'Payment failed',
            self::PaymentRecovered => 'Payment went through',
            self::EnteredGrace => 'Grace period started',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
            self::Resumed => 'Resumed',
            self::TrialEnded => 'Trial ended',
            self::AdjustedByHand => 'Adjusted by support',
        };
    }
}
