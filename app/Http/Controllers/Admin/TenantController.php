<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Platform\ExtendSubscriptionByHand;
use App\Actions\Platform\SuspendTenant;
use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExtendSubscriptionRequest;
use App\Http\Requests\Admin\SuspendTenantRequest;
use App\Models\AuditEntry;
use App\Models\Plan;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\Subscription;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Every account, and what can be done to one (SPEC §13.2).
 *
 * The search is over name, slug and the owners' email addresses, because
 * those are the three things somebody has in front of them when they ring
 * up — and the email is usually the only one they say correctly.
 */
final class TenantController extends Controller
{
    public function index(Request $request): View
    {
        $term = trim((string) $request->string('q'));
        $status = TenantStatus::tryFrom((string) $request->string('status'));

        $tenants = Tenant::query()
            ->with(['subscription.plan', 'venue'])
            ->when($term !== '', function ($query) use ($term): void {
                $query->where(function ($match) use ($term): void {
                    $match
                        ->where('name', 'like', '%'.$term.'%')
                        ->orWhere('slug', 'like', '%'.$term.'%')
                        ->orWhereHas('users', fn ($users) => $users->where('email', 'like', '%'.$term.'%'));
                });
            })
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->orderBy('name')
            ->paginate(40)
            ->withQueryString();

        return view('admin.tenants.index', [
            'tenants' => $tenants,
            'term' => $term,
            'status' => $status,
        ]);
    }

    public function show(Tenant $tenant): View
    {
        $tenant->loadMissing(['venue', 'users', 'subscription.plan']);

        return view('admin.tenants.show', [
            'tenant' => $tenant,
            'subscription' => $tenant->subscription,

            /*
             * What they are actually using, which is the thing that decides
             * whether a custom plan is worth writing. Counted here rather
             * than read off the plan limits: the limits say what they may
             * have, these say what they do have.
             */
            'usage' => [
                'resources' => Resource::query()->acrossTenants()
                    ->where('tenant_id', $tenant->getKey())->count(),
                'reservations_this_month' => Reservation::query()->acrossTenants()
                    ->where('tenant_id', $tenant->getKey())
                    ->where('created_at', '>=', now()->startOfMonth())
                    ->count(),
                'reservations_total' => Reservation::query()->acrossTenants()
                    ->where('tenant_id', $tenant->getKey())->count(),
            ],

            'plans' => Plan::query()->where('is_active', true)->orderBy('sort_order')->get(),

            'history' => AuditEntry::query()
                ->where('affected_tenant_id', $tenant->getKey())
                ->newestFirst()
                ->limit(30)
                ->get(),
        ]);
    }

    /**
     * A free month, a bank transfer, a goodwill fortnight (SPEC §4.1).
     */
    public function extend(
        ExtendSubscriptionRequest $request,
        Tenant $tenant,
        ExtendSubscriptionByHand $extend,
    ): RedirectResponse {
        $subscription = $tenant->subscription;

        if (! $subscription instanceof Subscription) {
            return back()->with(
                'error',
                'This account has no subscription to extend. Put it on a plan first.'
            );
        }

        try {
            $extend->handle(
                $tenant,
                $subscription,
                CarbonImmutable::parse($request->string('until')->toString())->endOfDay(),
                $request->user(),
                $request->string('reason')->toString(),
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Extended, and written into the log.');
    }

    public function suspend(
        SuspendTenantRequest $request,
        Tenant $tenant,
        SuspendTenant $suspension,
    ): RedirectResponse {
        $suspension->handle($tenant, $request->user(), $request->string('reason')->toString());

        return back()->with('status', $tenant->name.' is suspended.');
    }

    public function restore(
        Request $request,
        Tenant $tenant,
        SuspendTenant $suspension,
    ): RedirectResponse {
        $suspension->restore($tenant, $request->user(), $request->string('reason')->toString() ?: null);

        return back()->with('status', $tenant->name.' is back on.');
    }
}
