<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Enums\TenantStatus;
use App\Models\Subscription;
use App\Models\Tenant;

/**
 * Writes the tenant's cached status from its subscription (SPEC §4.3).
 *
 * The only place in the application that assigns `tenants.status`. Everything
 * that reads whether a venue may take bookings — both middlewares, the public
 * page, the widget, the API, every dashboard screen — reads that column, and
 * it has to agree with what the account is actually paying for. A second
 * writer is how a paying customer gets locked out on a Saturday.
 *
 * The derivation, in order, because the earlier rules outrank the later:
 *
 *  1. suspended by the platform, which nothing automatic may undo
 *  2. no subscription yet, so the trial decides
 *  3. a failed payment, which runs past_due then grace then expired
 *  4. otherwise whether the paid period has run out
 */
final readonly class SyncTenantStatus
{
    /**
     * The same thing, starting from a subscription.
     *
     * The tenant is fetched by key rather than read through the relation,
     * because strict mode refuses a lazy load — correctly: an action that
     * quietly issues a query per call is the thing that turns a nightly
     * sweep over four hundred accounts into four hundred round trips.
     */
    public function forSubscription(Subscription $subscription): TenantStatus
    {
        return $this->handle(
            Tenant::query()->findOrFail($subscription->tenant_id)
        );
    }

    public function handle(Tenant $tenant): TenantStatus
    {
        $status = $this->derive($tenant);

        if ($tenant->status !== $status) {
            $tenant->forceFill(['status' => $status])->save();
        }

        return $status;
    }

    private function derive(Tenant $tenant): TenantStatus
    {
        // Switched off by hand. Only a person can undo it, so no rule below
        // is allowed to.
        if ($tenant->status === TenantStatus::Suspended) {
            return TenantStatus::Suspended;
        }

        $subscription = Subscription::query()
            ->acrossTenants()
            ->where('tenant_id', $tenant->getKey())
            ->first();

        if (! $subscription instanceof Subscription) {
            // Never subscribed. Full access until the trial runs out, then
            // locked — which is the whole point of having a trial.
            return $tenant->isOnTrial() ? TenantStatus::Trialing : TenantStatus::Expired;
        }

        if ($subscription->payment_failed_at !== null) {
            return $this->afterAFailedPayment($subscription);
        }

        return $subscription->isCurrent()
            ? TenantStatus::Active
            : TenantStatus::Expired;
    }

    /**
     * The dunning ladder (SPEC §4.3).
     *
     * past_due while we are still trying the card, then the grace days, then
     * expired. Bookings keep working throughout past_due and grace: a card
     * that expired over the winter must not take a taverna off the internet
     * in the middle of August service.
     *
     * The grace days run from **the later of** the first failure and the end
     * of the paid period, and that detail is load-bearing. Measuring from the
     * failure alone looks right until a card fails early — a mid-cycle
     * update, a bank reissuing a number — and then the three days elapse
     * while the venue is still inside the month it already paid for. The
     * account would drop straight from past_due to expired with no grace at
     * all, which is precisely the case grace exists for. The dunning *emails*
     * still count from the first failure; only the lock waits.
     */
    private function afterAFailedPayment(Subscription $subscription): TenantStatus
    {
        $failedAt = $subscription->payment_failed_at;

        if ($failedAt === null) {
            return TenantStatus::Active;
        }

        // The paid period may still have time left on it, in which case
        // nothing is wrong yet from the venue's point of view.
        if ($subscription->isCurrent()) {
            return TenantStatus::PastDue;
        }

        $periodEnd = $subscription->current_period_end;

        $graceStarts = $periodEnd !== null && $periodEnd->greaterThan($failedAt)
            ? $periodEnd
            : $failedAt;

        return $graceStarts->addDays((int) config('tenancy.grace_days'))->isFuture()
            ? TenantStatus::Grace
            : TenantStatus::Expired;
    }
}
