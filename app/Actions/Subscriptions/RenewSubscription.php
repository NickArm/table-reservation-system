<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Enums\BillingPeriod;
use App\Enums\SubscriptionEventType;
use App\Models\Subscription;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * A payment went through and the period moves on (SPEC §4.3).
 *
 * The new period starts where the old one ended, not at today. An account
 * whose renewal is confirmed three hours late has not bought three hours
 * less, and measuring each period from the one before is what keeps a
 * subscription billed on the 31st billing on the 31st.
 *
 * The exception is a period that ran out some time ago — an account coming
 * back after a lapse. Carrying on from a period end in March would hand them
 * a month that was already over.
 */
final readonly class RenewSubscription
{
    public function __construct(
        private SyncTenantStatus $status,
        private RecordSubscriptionEvent $events,
    ) {}

    public function handle(Subscription $subscription, ?int $amountCents = null): Subscription
    {
        return DB::transaction(function () use ($subscription, $amountCents): Subscription {
            $plan = $subscription->loadMissing('plan')->plan;

            $period = $plan?->billing_period ?? BillingPeriod::Monthly;

            $previousEnd = $subscription->current_period_end;

            /*
             * Continue from where the last period ended, unless carrying on
             * from there would hand the account a period that is already
             * over. A renewal confirmed three hours late has not bought three
             * hours less; an account coming back in April from a period that
             * ended in November has not bought the winter.
             */
            $continued = $previousEnd !== null && $period->endFrom($previousEnd)->isFuture();

            $start = $continued ? $previousEnd : now()->toImmutable();

            $subscription->forceFill([
                'current_period_start' => $start,
                'current_period_end' => $period->endFrom($start),
                'payment_failed_at' => null,

                // Chased from the beginning if the card fails again. The mark
                // is per failure, not per account.
                'dunning_sent_for' => null,
            ])->save();

            $charged = $amountCents ?? (int) $subscription->price_cents;

            $this->events->handle(
                $subscription,
                SubscriptionEventType::Renewed,
                'Renewed until '.$subscription->current_period_end?->format('j M Y')
                    .', '.Money::format($charged).'.',
                $charged,
            );

            $this->status->forSubscription($subscription);

            return $subscription;
        });
    }
}
