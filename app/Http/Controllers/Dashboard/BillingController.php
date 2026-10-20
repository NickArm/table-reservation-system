<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Subscriptions\Entitlements;
use App\Tenancy\TenantContext;
use Illuminate\View\View;

/**
 * What the account is paying, and what it could pay instead (SPEC §4).
 *
 * Reachable in every state, including expired — especially expired. An
 * account that has run out has to be able to see where it stands and do
 * something about it, so this page is deliberately outside the read-only
 * lock: locking somebody out of the page where they would pay is the one
 * mistake that guarantees they do not.
 */
final class BillingController extends Controller
{
    public function show(TenantContext $context, Entitlements $entitlements): View
    {
        $tenant = $context->getOrFail();

        $subscription = Subscription::query()->with('plan')->first();

        return view('dashboard.billing', [
            'tenant' => $tenant,
            'subscription' => $subscription,
            'plan' => $subscription?->plan,
            'plans' => Plan::query()->offeredTo($tenant)->get(),
            'entitlements' => $entitlements,

            // Newest first: the question somebody opens this page with is
            // almost always about the most recent thing that happened.
            'history' => SubscriptionEvent::query()
                ->with('actor')
                ->newestFirst()
                ->limit(50)
                ->get(),
        ]);
    }
}
