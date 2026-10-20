<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Enums\BillingProvider;
use App\Enums\SubscriptionEventType;
use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The account picks a plan and starts paying (SPEC §4.2 step 5).
 *
 * One subscription per account, so starting one when something is already
 * there changes it rather than adding a second — there is no sensible answer
 * to which of two rows counts.
 *
 * The price is copied onto the subscription rather than read through the plan
 * every time. A plan's price will be put up one day, and an account that
 * signed at €70 is paying €70 until it renews, not retroactively.
 */
final readonly class StartSubscription
{
    public function __construct(
        private SyncTenantStatus $status,
        private RecordSubscriptionEvent $events,
    ) {}

    public function handle(
        Tenant $tenant,
        Plan $plan,
        BillingProvider $provider = BillingProvider::Manual,
        ?string $providerSubscriptionId = null,
        ?string $providerCustomerId = null,
    ): Subscription {
        return DB::transaction(function () use (
            $tenant,
            $plan,
            $provider,
            $providerSubscriptionId,
            $providerCustomerId,
        ): Subscription {
            $existing = Subscription::query()
                ->acrossTenants()
                ->where('tenant_id', $tenant->getKey())
                ->first();

            $start = now()->toImmutable();

            $subscription = $existing ?? new Subscription;

            $changingPlan = $existing !== null && (int) $existing->plan_id !== (int) $plan->getKey();

            $subscription->forceFill([
                'tenant_id' => $tenant->getKey(),
                'plan_id' => $plan->getKey(),
                'status' => TenantStatus::Active,
                'current_period_start' => $start,
                'current_period_end' => $plan->billing_period->endFrom($start),
                'cancel_at_period_end' => false,
                'cancelled_at' => null,
                'payment_failed_at' => null,
                'provider' => $provider,
                'provider_subscription_id' => $providerSubscriptionId,
                'provider_customer_id' => $providerCustomerId,
                'price_cents' => $plan->price_cents,
                'currency' => $plan->currency,
            ])->save();

            $subscription->setRelation('plan', $plan);

            $this->events->handle(
                $subscription,
                $changingPlan ? SubscriptionEventType::PlanChanged : SubscriptionEventType::Started,
                $changingPlan
                    ? 'Moved to '.$plan->name.', '.Money::format((int) $plan->price_cents)
                        .' '.$plan->billing_period->per().'.'
                    : 'Started on '.$plan->name.', '.Money::format((int) $plan->price_cents)
                        .' '.$plan->billing_period->per().'.',
                (int) $plan->price_cents,
            );

            $this->status->handle($tenant);

            return $subscription;
        });
    }
}
