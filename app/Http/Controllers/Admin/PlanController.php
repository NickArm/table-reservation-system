<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Platform\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Enums\BillingPeriod;
use App\Enums\Feature;
use App\Enums\PlanLimit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanRequest;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * What the platform sells (SPEC §4.1, §13.2).
 *
 * Public plans and the private ones written for one account — a negotiated
 * price for a chain of three beach bars, a season rate for a venue that only
 * opens five months a year. Both are the same row with `is_public` and
 * `custom_for_tenant_id` deciding who ever sees it.
 */
final class PlanController extends Controller
{
    public function index(): View
    {
        $plans = Plan::query()
            ->with('customForTenant')
            ->orderByDesc('is_active')
            ->orderBy('sort_order')
            ->get();

        return view('admin.plans.index', [
            'plans' => $plans,

            // How many accounts are on each, which is the only number that
            // says whether a plan may be retired.
            'inUse' => Subscription::query()
                ->acrossTenants()
                ->selectRaw('plan_id, count(*) as total')
                ->groupBy('plan_id')
                ->pluck('total', 'plan_id'),
        ]);
    }

    public function create(): View
    {
        return view('admin.plans.form', [
            'plan' => new Plan,
            'tenants' => Tenant::query()->orderBy('name')->get(),
        ]);
    }

    public function edit(Plan $plan): View
    {
        return view('admin.plans.form', [
            'plan' => $plan,
            'tenants' => Tenant::query()->orderBy('name')->get(),
        ]);
    }

    public function store(PlanRequest $request, RecordAuditEntry $audit): RedirectResponse
    {
        $plan = Plan::query()->create($this->attributes($request));

        $audit->handle(
            AuditAction::PlanCreated,
            'Plan '.$plan->name.' created at '.$plan->price().' '
                .$plan->billing_period->per().'.',
            actor: $request->user(),
            tenant: $plan->customForTenant,
            context: ['plan_id' => $plan->getKey(), 'slug' => $plan->slug],
        );

        return redirect()->route('admin.plans.index')
            ->with('status', $plan->name.' is ready. Set it up at the payment provider '
                .'and put the price id on it before anybody can buy it.');
    }

    public function update(PlanRequest $request, Plan $plan, RecordAuditEntry $audit): RedirectResponse
    {
        $before = [
            'price_cents' => (int) $plan->price_cents,
            'is_active' => (bool) $plan->is_active,
            'is_public' => (bool) $plan->is_public,
        ];

        $plan->update($this->attributes($request));

        /*
         * The price is called out separately. Changing a plan's price does
         * **not** change what anybody is already paying — `StartSubscription`
         * copies the price onto the subscription — and that is exactly the
         * kind of thing somebody will later be certain worked the other way.
         */
        $audit->handle(
            $before['is_active'] && ! $plan->is_active
                ? AuditAction::PlanRetired
                : AuditAction::PlanUpdated,
            'Plan '.$plan->name.' changed.',
            actor: $request->user(),
            tenant: $plan->customForTenant,
            context: ['plan_id' => $plan->getKey(), 'before' => $before, 'after' => [
                'price_cents' => (int) $plan->price_cents,
                'is_active' => (bool) $plan->is_active,
                'is_public' => (bool) $plan->is_public,
            ]],
        );

        return redirect()->route('admin.plans.index')
            ->with('status', $plan->name.' saved. Accounts already on it keep the price '
                .'they signed at until they renew.');
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(PlanRequest $request): array
    {
        $limits = [];

        foreach (PlanLimit::cases() as $limit) {
            $value = $request->input('limits.'.$limit->value);

            // Blank means no limit, which is a real answer and not a missing
            // one — so it is stored as null rather than skipped.
            $limits[$limit->value] = $value === null || $value === '' ? null : (int) $value;
        }

        $features = [];

        foreach (Feature::cases() as $feature) {
            $features[$feature->value] = (bool) $request->boolean('features.'.$feature->value);
        }

        $custom = $request->integer('custom_for_tenant_id') ?: null;

        return [
            'name' => $request->string('name')->toString(),
            'slug' => Str::slug($request->string('slug')->toString() ?: $request->string('name')->toString()),
            'description' => $request->string('description')->toString(),
            'billing_period' => BillingPeriod::from($request->string('billing_period')->toString()),
            'price_cents' => (int) round(((float) $request->input('price')) * 100),
            'currency' => 'EUR',
            'trial_days' => $request->integer('trial_days'),
            'stripe_price_id' => $request->string('stripe_price_id')->toString() ?: null,
            'paypal_plan_id' => $request->string('paypal_plan_id')->toString() ?: null,
            'limits' => $limits,
            'features' => $features,

            // A plan written for one account is private by definition. Left
            // to the checkbox, the first custom plan would eventually appear
            // on everybody's billing page with somebody's negotiated price.
            'is_public' => $custom === null && $request->boolean('is_public'),
            'is_active' => $request->boolean('is_active'),
            'sort_order' => $request->integer('sort_order'),
            'custom_for_tenant_id' => $custom,
        ];
    }
}
