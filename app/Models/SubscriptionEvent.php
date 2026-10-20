<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriptionEventType;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Money;
use Database\Factories\SubscriptionEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of billing history, and where a person did it, one line of audit
 * trail (SPEC §4.1, §4.3).
 *
 * Append-only. Nothing in the application updates or deletes a row here, and
 * nothing should: a history that can be tidied up afterwards is not evidence
 * of anything.
 */
class SubscriptionEvent extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SubscriptionEventFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'subscription_id', 'plan_name', 'type', 'summary',
        'actor_id', 'reason', 'amount_cents', 'currency', 'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SubscriptionEventType::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @param  Builder<SubscriptionEvent>  $query
     * @return Builder<SubscriptionEvent>
     */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function amount(): ?string
    {
        return $this->amount_cents === null
            ? null
            : Money::format((int) $this->amount_cents);
    }

    /** Did a person do this, rather than a gateway or the nightly sweep? */
    public function wasByHand(): bool
    {
        return $this->actor_id !== null;
    }
}
