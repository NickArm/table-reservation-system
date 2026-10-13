<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingMode;
use App\Enums\Occupancy;
use App\Enums\PaymentRequirement;
use App\Enums\PriceBasis;
use App\Enums\ResourceType;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BookingTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a guest can book here (SPEC §5.1).
 *
 * A venue with one of these never shows the choice — the booking page skips
 * straight to date and party size.
 */
#[Fillable([
    'name', 'slug', 'description', 'icon',
    'resource_type', 'booking_mode',
    'slot_duration_minutes', 'slot_interval_minutes', 'turnover_buffer_minutes',
    'arrival_window_start', 'arrival_window_end',
    'occupancy', 'max_stay_minutes',
    'min_lead_time_minutes', 'max_advance_days', 'hold_duration_minutes',
    'max_per_email_24h',
    'payment_requirement', 'deposit_label',
    'default_price_cents', 'price_basis',
    'cancellation_policy', 'default_view', 'is_active', 'sort_order',
])]
class BookingType extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BookingTypeFactory> */
    use HasFactory;

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
            'resource_type' => ResourceType::class,
            'booking_mode' => BookingMode::class,
            'occupancy' => Occupancy::class,
            'payment_requirement' => PaymentRequirement::class,
            'price_basis' => PriceBasis::class,
            'cancellation_policy' => 'array',
            'is_active' => 'boolean',
            'arrival_window_start' => 'string',
            'arrival_window_end' => 'string',
        ];
    }

    /**
     * @return BelongsTo<Venue, $this>
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /**
     * @return HasMany<Area, $this>
     */
    public function areas(): HasMany
    {
        return $this->hasMany(Area::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<resource, $this>
     */
    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    /**
     * @param  Builder<BookingType>  $query
     * @return Builder<BookingType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    public function needsPayment(): bool
    {
        return $this->payment_requirement->needsGateway();
    }

    /**
     * Minutes a booking holds its resource, when the length is fixed.
     */
    public function maxStayMinutes(): ?int
    {
        return $this->occupancy === Occupancy::FixedDuration
            ? ($this->max_stay_minutes ?? $this->slot_duration_minutes)
            : null;
    }
}
