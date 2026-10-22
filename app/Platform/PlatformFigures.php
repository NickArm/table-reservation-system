<?php

declare(strict_types=1);

namespace App\Platform;

use App\Enums\TenantStatus;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * The numbers on the platform overview (SPEC §13.2).
 *
 * Four of them, because four is what gets looked at. MRR, how many accounts
 * are paying, how many trials are about to decide, and how many have lapsed
 * recently — which is churn, stated as something that can be acted on rather
 * than as a percentage.
 *
 * MRR is computed from the subscription rows rather than from the plans. An
 * account that signed at €70 is on €70 until it renews even after the plan
 * price goes up (`StartSubscription` copies the price for exactly this
 * reason), so reading the plan would overstate the figure by whatever the
 * last price rise was.
 */
final readonly class PlatformFigures
{
    /**
     * Monthly recurring revenue, in cents.
     *
     * A yearly subscription counts as a twelfth of itself per month. That is
     * the honest comparison: a €500 annual account is not twelve times more
     * valuable than a €70 monthly one.
     */
    public function mrrCents(): int
    {
        return (int) round(
            $this->paying()
                ->map(static fn (Subscription $subscription): float => (int) $subscription->price_cents
                    / max(1, $subscription->loadMissing('plan')->plan?->billing_period->months() ?? 1))
                ->sum()
        );
    }

    /**
     * Accounts that are paying us something right now.
     *
     * Past due and grace count: the money is late, not gone, and treating a
     * card that bounced this morning as churn makes the figure jump around
     * for reasons that have nothing to do with the business.
     *
     * @return Collection<int, Subscription>
     */
    public function paying(): Collection
    {
        return Subscription::query()
            ->acrossTenants()
            ->with('plan')
            ->whereIn('status', [
                TenantStatus::Active->value,
                TenantStatus::PastDue->value,
                TenantStatus::Grace->value,
            ])
            ->where('price_cents', '>', 0)
            ->get();
    }

    public function activeAccounts(): int
    {
        return Tenant::query()
            ->whereIn('status', [
                TenantStatus::Active->value,
                TenantStatus::PastDue->value,
                TenantStatus::Grace->value,
            ])
            ->count();
    }

    public function onTrial(): int
    {
        return Tenant::query()->where('status', TenantStatus::Trialing->value)->count();
    }

    /**
     * Trials that run out within the week — the ones worth a phone call.
     */
    public function trialsEndingSoon(): int
    {
        return Tenant::query()
            ->where('status', TenantStatus::Trialing->value)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now()->addWeek())
            ->count();
    }

    /**
     * Accounts that have lapsed in the last month.
     *
     * Churn as a number of venues rather than a percentage: with a few dozen
     * accounts a percentage swings wildly on one cancellation and tells
     * nobody which venue to ring.
     */
    public function lapsedThisMonth(): int
    {
        return Subscription::query()
            ->acrossTenants()
            ->where('status', TenantStatus::Expired->value)
            ->where('current_period_end', '>=', now()->subMonth())
            ->count();
    }

    public function suspended(): int
    {
        return Tenant::query()->where('status', TenantStatus::Suspended->value)->count();
    }

    /**
     * Payments the providers could not take, still outstanding.
     */
    public function failedPayments(): int
    {
        return Subscription::query()
            ->acrossTenants()
            ->whereNotNull('payment_failed_at')
            ->count();
    }
}
