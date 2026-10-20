<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Enums\SubscriptionEventType;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

/**
 * The card was declined (SPEC §4.3).
 *
 * Starts the dunning clock and nothing else. Bookings keep working: the venue
 * is mid-season, the card expired over the winter, and taking them off the
 * internet before anybody has had a chance to read an email would cost them
 * a Saturday and cost us the account.
 *
 * The time is recorded once and not overwritten by a later attempt, because
 * the grace period is measured from the first failure. Resetting it on every
 * retry would let a subscription sit in grace indefinitely.
 */
final readonly class RecordPaymentFailure
{
    public function __construct(
        private SyncTenantStatus $status,
        private RecordSubscriptionEvent $events,
    ) {}

    public function handle(Subscription $subscription, ?string $reason = null): Subscription
    {
        return DB::transaction(function () use ($subscription, $reason): Subscription {
            if ($subscription->payment_failed_at === null) {
                $subscription->forceFill(['payment_failed_at' => now()])->save();

                $this->events->handle(
                    $subscription,
                    SubscriptionEventType::PaymentFailed,
                    $reason !== null && $reason !== ''
                        ? 'Payment failed: '.$reason
                        : 'Payment failed. We will try again.',
                    (int) $subscription->price_cents,
                );
            }

            $this->status->forSubscription($subscription);

            return $subscription;
        });
    }

    /**
     * A later attempt succeeded, so the clock stops.
     */
    public function recovered(Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($subscription): Subscription {
            if ($subscription->payment_failed_at !== null) {
                $subscription->forceFill(['payment_failed_at' => null])->save();

                $this->events->handle(
                    $subscription,
                    SubscriptionEventType::PaymentRecovered,
                    'Payment went through.',
                    (int) $subscription->price_cents,
                );
            }

            $this->status->forSubscription($subscription);

            return $subscription;
        });
    }
}
