<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Enums\SubscriptionEventType;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

/**
 * The owner stops paying (SPEC §4.3).
 *
 * Takes effect at the end of the period, never immediately. They paid for the
 * month; cancelling in the middle of it and switching the venue off the same
 * afternoon would be taking money for nothing, and would strand every guest
 * who has already booked.
 *
 * Nothing is deleted. Data is kept for twelve months (SPEC §4.4), because a
 * seasonal venue cancels in November and comes back in April, and coming back
 * to an empty account is how they end up at a competitor instead.
 */
final readonly class CancelSubscription
{
    public function __construct(
        private SyncTenantStatus $status,
        private RecordSubscriptionEvent $events,
    ) {}

    public function handle(Subscription $subscription, ?string $reason = null): Subscription
    {
        return DB::transaction(function () use ($subscription, $reason): Subscription {
            $subscription->forceFill([
                'cancel_at_period_end' => true,
                'cancelled_at' => now(),
            ])->save();

            $until = $subscription->current_period_end?->format('j M Y');

            $this->events->handle(
                $subscription,
                SubscriptionEventType::Cancelled,
                $until === null
                    ? 'Cancelled.'
                    : 'Cancelled. Your bookings keep working until '.$until.'.',
                reason: $reason,
            );

            $this->status->forSubscription($subscription);

            return $subscription;
        });
    }

    /**
     * Changed their mind before the period ran out.
     */
    public function resume(Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($subscription): Subscription {
            $subscription->forceFill([
                'cancel_at_period_end' => false,
                'cancelled_at' => null,
            ])->save();

            $this->events->handle(
                $subscription,
                SubscriptionEventType::Resumed,
                'Resumed. It renews as normal.',
            );

            $this->status->forSubscription($subscription);

            return $subscription;
        });
    }
}
