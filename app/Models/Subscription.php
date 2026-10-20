<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingProvider;
use App\Enums\TenantStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What one account is paying for (SPEC §4.3).
 *
 * The status here is the truth; `tenants.status` is a cache of it, written by
 * App\Actions\Subscriptions\SyncTenantStatus and by nothing else.
 */
#[Fillable([
    'plan_id', 'status', 'current_period_start', 'current_period_end',
    'cancel_at_period_end', 'provider', 'price_cents', 'currency',
])]
#[Hidden(['provider_subscription_id', 'provider_customer_id'])]
class Subscription extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'provider' => BillingProvider::class,
            'current_period_start' => 'immutable_datetime',
            'current_period_end' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'payment_failed_at' => 'immutable_datetime',
            'cancel_at_period_end' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SubscriptionEvent::class);
    }

    /**
     * Subscriptions whose paid period has run out.
     *
     * The nightly sweep's own query. Cancelled ones are included: cancelling
     * keeps what was paid for until the period ends, and the end is what this
     * finds.
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopeLapsed(Builder $query, ?CarbonImmutable $at = null): Builder
    {
        return $query
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', $at ?? now());
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    /**
     * Paid for, and not yet run out.
     */
    public function isCurrent(): bool
    {
        return $this->current_period_end !== null
            && $this->current_period_end->isFuture();
    }

    public function daysLeft(): int
    {
        if ($this->current_period_end === null) {
            return 0;
        }

        return max(0, (int) now()->diffInDays($this->current_period_end, false));
    }

    /**
     * Will it renew on its own when the period ends?
     *
     * Three things have to hold, and each one fails differently: the owner
     * has not cancelled, the provider is one that renews, and there is
     * something to charge.
     */
    public function willRenew(): bool
    {
        return ! $this->cancel_at_period_end
            && $this->provider->renewsItself()
            && (int) $this->price_cents > 0;
    }

    public function price(): string
    {
        return Money::format((int) $this->price_cents);
    }
}
