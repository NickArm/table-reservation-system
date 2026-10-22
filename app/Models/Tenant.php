<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantStatus;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One venue account: the thing that holds a subscription and owns every other
 * record in the system.
 *
 * Not tenant-scoped itself, for the obvious reason.
 */
#[Fillable(['name', 'slug', 'status', 'timezone', 'locale', 'currency', 'trial_ends_at'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use SoftDeletes;

    protected static function booted(): void
    {
        static::creating(static function (self $tenant): void {
            if (blank($tenant->getAttribute('uuid'))) {
                $tenant->setAttribute('uuid', (string) Str::uuid7());
            }
        });
    }

    /**
     * The slug is what appears in /reservation/{slug}, so bind on it.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'trial_ends_at' => 'datetime',
            'deletion_requested_at' => 'datetime',
            'purge_at' => 'datetime',
            'accepted_terms_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Venue, $this>
     */
    public function venues(): HasMany
    {
        return $this->hasMany(Venue::class);
    }

    /**
     * One venue per account in v1 (SPEC §1.3). The table supports more, the
     * interface does not show them yet.
     *
     * @return HasOne<Venue, $this>
     */
    public function venue(): HasOne
    {
        return $this->hasOne(Venue::class)->oldestOfMany();
    }

    /**
     * The one subscription this account has (SPEC §4.3).
     *
     * Reachable only where tenant scoping is off or this account is the one
     * in context — the admin area and console work. Inside a venue's own
     * dashboard the subscription is read directly, because `Subscription` is
     * already filtered to that account and going through the tenant would be
     * a longer way to the same row.
     *
     * @return HasOne<Subscription, $this>
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function acceptsBookings(): bool
    {
        return $this->status->acceptsBookings();
    }

    public function isReadOnly(): bool
    {
        return $this->status->isReadOnly();
    }

    public function isOnTrial(): bool
    {
        return $this->status === TenantStatus::Trialing
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }

    public function trialDaysLeft(): int
    {
        if ($this->trial_ends_at === null) {
            return 0;
        }

        return max(0, (int) now()->diffInDays($this->trial_ends_at, false));
    }
}
