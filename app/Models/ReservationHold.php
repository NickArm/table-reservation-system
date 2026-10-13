<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ReservationHoldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A spot kept aside while someone pays (SPEC §6.4, §24.1).
 */
#[Fillable([
    'booking_type_id', 'resource_id',
    'starts_at', 'ends_at', 'party_size',
    'session_token', 'expires_at',
])]
class ReservationHold extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ReservationHoldFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Resource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    /**
     * Holds that still mean something.
     *
     * Expired rows are filtered here rather than trusted to the cleanup job:
     * on shared hosting cron may only run every five minutes, and a sunbed
     * must not look taken because of it (SPEC §21.2).
     *
     * @param  Builder<ReservationHold>  $query
     * @return Builder<ReservationHold>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * @param  Builder<ReservationHold>  $query
     * @return Builder<ReservationHold>
     */
    public function scopeOverlapping(
        Builder $query,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        int $bufferMinutes = 0,
    ): Builder {
        return $query
            ->where('starts_at', '<', $endsAt->addMinutes($bufferMinutes))
            ->where('ends_at', '>', $startsAt->subMinutes($bufferMinutes));
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function secondsLeft(): int
    {
        return max(0, (int) now()->diffInSeconds($this->expires_at, false));
    }
}
