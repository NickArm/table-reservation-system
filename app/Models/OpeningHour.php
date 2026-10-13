<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\OpeningHourFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Opening hours for either a venue or one booking type (SPEC §33.1).
 */
#[Fillable(['scope', 'scope_id', 'day_of_week', 'opens_at', 'closes_at', 'closes_next_day'])]
class OpeningHour extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<OpeningHourFactory> */
    use HasFactory;

    public const SCOPE_VENUE = 'venue';

    public const SCOPE_BOOKING_TYPE = 'booking_type';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'closes_next_day' => 'boolean',
        ];
    }

    /**
     * @param  Builder<OpeningHour>  $query
     * @return Builder<OpeningHour>
     */
    public function scopeForVenue(Builder $query, Venue $venue): Builder
    {
        return $query
            ->where('scope', self::SCOPE_VENUE)
            ->where('scope_id', $venue->id)
            ->orderBy('day_of_week')
            ->orderBy('opens_at');
    }

    /**
     * @param  Builder<OpeningHour>  $query
     * @return Builder<OpeningHour>
     */
    public function scopeForBookingType(Builder $query, BookingType $bookingType): Builder
    {
        return $query
            ->where('scope', self::SCOPE_BOOKING_TYPE)
            ->where('scope_id', $bookingType->id)
            ->orderBy('day_of_week')
            ->orderBy('opens_at');
    }

    public function dayName(): string
    {
        return ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$this->day_of_week];
    }

    public function range(): string
    {
        return substr((string) $this->opens_at, 0, 5).'–'.substr((string) $this->closes_at, 0, 5);
    }
}
