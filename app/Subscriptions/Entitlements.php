<?php

declare(strict_types=1);

namespace App\Subscriptions;

use App\Enums\Feature;
use App\Enums\PlanLimit;
use App\Enums\TenantStatus;
use App\Models\BookingType;
use App\Models\Plan;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Venue;
use App\Subscriptions\Exceptions\PlanDoesNotAllowThis;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * What this account is allowed to do (SPEC §4.5).
 *
 * One object that every write path asks, rather than each one working it out:
 * the rules are short but the consequences of getting one wrong are either a
 * customer paying for something they cannot use, or a free account using
 * something they are not paying for.
 *
 * Three states, in plain terms:
 *
 *  - **on trial** — everything, no limits. The trial is the product demo, and
 *    a demo that refuses things is a demo of the wrong product (SPEC §4.2)
 *  - **subscribed** — whatever the plan says
 *  - **lapsed** — nothing new. Existing bookings stay visible and valid, and
 *    the export never stops working (SPEC §4.4)
 *
 * Resolved per request and cached on the instance, because a single dashboard
 * page asks several of these and the answer cannot change mid-request.
 */
final class Entitlements
{
    private ?Tenant $tenant = null;

    private ?Plan $plan = null;

    private bool $resolved = false;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * May the account use this feature at all?
     */
    public function allows(Feature $feature): bool
    {
        $this->resolve();

        if ($this->tenant === null) {
            return false;
        }

        if ($this->isOnTrial()) {
            return true;
        }

        if ($this->hasLapsed()) {
            return false;
        }

        return $this->plan?->allows($feature) ?? false;
    }

    /**
     * How many of these the account may have in total, or null for no limit.
     */
    public function limit(PlanLimit $limit): ?int
    {
        $this->resolve();

        if ($this->tenant === null) {
            return 0;
        }

        if ($this->isOnTrial()) {
            return null;
        }

        if ($this->hasLapsed()) {
            return 0;
        }

        return $this->plan?->limit($limit);
    }

    /**
     * Is there room for one more?
     *
     * Asked immediately before creating something. A limit that is only
     * checked in the interface is not a limit — the API and the console reach
     * the same actions (SPEC §4.5).
     */
    public function hasRoomFor(PlanLimit $limit, int $wanted = 1): bool
    {
        $allowed = $this->limit($limit);

        if ($allowed === null) {
            return true;
        }

        return $this->countOf($limit) + $wanted <= $allowed;
    }

    /**
     * What to tell the owner when there is not.
     *
     * Never a bare "limit reached": the message names the number and says
     * what can be done, because the alternative generates a support email
     * rather than an upgrade.
     */
    public function refusalFor(PlanLimit $limit): string
    {
        $this->resolve();

        if ($this->hasLapsed()) {
            return 'Your subscription has run out, so nothing new can be added. '
                .'Everything already here is safe, and renewing brings it all back.';
        }

        $allowed = $this->limit($limit);

        return $allowed === null
            ? 'That is not available on your plan.'
            : $limit->refusal($allowed);
    }

    /**
     * The same two questions, as a guard.
     *
     * Every write path calls one of these rather than the boolean above, so
     * that forgetting to act on a false is not possible: the action stops
     * where the rule is, with the sentence the owner should read already
     * attached.
     */
    public function ensureRoomFor(PlanLimit $limit, int $wanted = 1): void
    {
        if (! $this->hasRoomFor($limit, $wanted)) {
            throw PlanDoesNotAllowThis::noRoom($this->refusalFor($limit));
        }
    }

    public function ensureAllows(Feature $feature): void
    {
        if (! $this->allows($feature)) {
            throw PlanDoesNotAllowThis::featureMissing($feature);
        }
    }

    /**
     * How many the account has now.
     *
     * Bookings are counted for the current month and everything else in
     * total, which is what the two kinds of limit mean.
     */
    public function countOf(PlanLimit $limit): int
    {
        return match ($limit) {
            PlanLimit::Venues => Venue::query()->count(),
            PlanLimit::BookingTypes => BookingType::query()->count(),
            PlanLimit::Resources => Resource::query()->count(),
            PlanLimit::StaffUsers => $this->staffCount(),
            PlanLimit::ReservationsPerMonth => Reservation::query()
                ->where('created_at', '>=', CarbonImmutable::now()->startOfMonth())
                ->count(),
        };
    }

    public function plan(): ?Plan
    {
        $this->resolve();

        return $this->plan;
    }

    public function isOnTrial(): bool
    {
        $this->resolve();

        return $this->tenant?->isOnTrial() ?? false;
    }

    /**
     * Run out, and not currently in any state that still allows writing.
     *
     * past_due and grace deliberately do not count: the venue is mid-season
     * and the card expired, and refusing to let them add a table while we
     * email them about it is the wrong order of events.
     */
    public function hasLapsed(): bool
    {
        $this->resolve();

        return match ($this->tenant?->status) {
            TenantStatus::Expired, TenantStatus::Suspended => true,
            null => true,
            default => false,
        };
    }

    /**
     * Forgets the cached answer. For a console sweep that moves between
     * accounts inside one process.
     */
    public function forget(): void
    {
        $this->resolved = false;
        $this->tenant = null;
        $this->plan = null;
    }

    private function staffCount(): int
    {
        $tenant = $this->tenant;

        if ($tenant === null) {
            return 0;
        }

        return User::query()
            ->whereHas('tenants', fn ($query) => $query->whereKey($tenant->getKey()))
            ->count();
    }

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }

        $this->resolved = true;
        $this->tenant = $this->context->get();

        if ($this->tenant === null) {
            return;
        }

        $subscription = Subscription::query()
            ->with('plan')
            ->acrossTenants()
            ->where('tenant_id', $this->tenant->getKey())
            ->first();

        $this->plan = $subscription?->plan;
    }
}
