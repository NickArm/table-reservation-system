<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Occupancy;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AreaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A row, a terrace, a part of the room (SPEC §5.1).
 *
 * Can override how long its resources are held, which is what lets one beach
 * sell the front row by the day and the second row by the half day.
 */
#[Fillable([
    'name', 'description',
    'occupancy', 'max_stay_minutes', 'turnover_buffer_minutes',
    'sort_order',
])]
class Area extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AreaFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occupancy' => Occupancy::class,
        ];
    }

    /**
     * @return BelongsTo<BookingType, $this>
     */
    public function bookingType(): BelongsTo
    {
        return $this->belongsTo(BookingType::class);
    }

    /**
     * @return HasMany<resource, $this>
     */
    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class)->orderBy('sort_order')->orderBy('label');
    }

    /**
     * The area's own rule, or the booking type's if it has none.
     *
     * Pass the booking type in rather than reading the relation: strict mode
     * forbids lazy loading, and callers almost always have it already.
     */
    public function effectiveOccupancy(BookingType $bookingType): Occupancy
    {
        return $this->occupancy ?? $bookingType->occupancy;
    }

    public function effectiveMaxStayMinutes(BookingType $bookingType): ?int
    {
        if ($this->effectiveOccupancy($bookingType) !== Occupancy::FixedDuration) {
            return null;
        }

        return $this->max_stay_minutes
            ?? $bookingType->max_stay_minutes
            ?? $bookingType->slot_duration_minutes;
    }

    public function effectiveBufferMinutes(BookingType $bookingType): int
    {
        return $this->turnover_buffer_minutes ?? $bookingType->turnover_buffer_minutes;
    }
}
