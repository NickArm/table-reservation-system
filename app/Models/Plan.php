<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingPeriod;
use App\Enums\BillingProvider;
use App\Enums\Feature;
use App\Enums\PlanLimit;
use App\Support\Money;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing an account can buy (SPEC §4.1).
 *
 * Deliberately *not* using BelongsToTenant — see the migration. A plan
 * belongs to the platform; `custom_for_tenant_id` only records who a private
 * plan was made for.
 */
#[Fillable([
    'name', 'slug', 'description', 'billing_period',
    'price_cents', 'currency', 'trial_days',
    'stripe_price_id', 'paypal_plan_id',
    'limits', 'features', 'is_public', 'is_active', 'sort_order',
    'custom_for_tenant_id',
])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'billing_period' => BillingPeriod::class,
            'limits' => 'array',
            'features' => 'array',
            'is_public' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function customForTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'custom_for_tenant_id');
    }

    /**
     * What the pricing page offers, plus anything made for this account.
     *
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopeOfferedTo(Builder $query, ?Tenant $tenant): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(function (Builder $query) use ($tenant): void {
                $query->where(function (Builder $query): void {
                    $query->where('is_public', true)->whereNull('custom_for_tenant_id');
                });

                if ($tenant !== null) {
                    $query->orWhere('custom_for_tenant_id', $tenant->getKey());
                }
            })
            ->orderBy('sort_order');
    }

    /**
     * Is this feature part of the plan?
     *
     * Absent means off. A plan written before a feature existed should not
     * acquire it by default on the deploy that adds it.
     */
    public function allows(Feature $feature): bool
    {
        return (bool) (($this->features ?? [])[$feature->value] ?? false);
    }

    /**
     * How many of these the plan covers, or null for no limit.
     */
    public function limit(PlanLimit $limit): ?int
    {
        $value = ($this->limits ?? [])[$limit->value] ?? null;

        return $value === null ? null : (int) $value;
    }

    public function isFree(): bool
    {
        return (int) $this->price_cents === 0;
    }

    /**
     * Can this plan be bought through that provider?
     *
     * A plan has to exist at the provider before anybody can subscribe to it,
     * and one may be set up at Stripe weeks before PayPal. The billing page
     * asks this rather than offering a button that fails on the other side.
     */
    public function sellableThrough(BillingProvider $provider): bool
    {
        return match ($provider) {
            BillingProvider::Stripe => filled($this->stripe_price_id),
            BillingProvider::PayPal => filled($this->paypal_plan_id),
            BillingProvider::Manual => true,
        };
    }

    public function price(): string
    {
        return Money::format((int) $this->price_cents);
    }

    /**
     * What a yearly plan works out to a month, for the pricing page.
     *
     * The honest comparison: €500 a year is the reason anybody chooses it
     * over €70 a month, and making the reader divide is making them work.
     */
    public function monthlyEquivalent(): string
    {
        return Money::formatExact(
            (int) round((int) $this->price_cents / $this->billing_period->months())
        );
    }
}
