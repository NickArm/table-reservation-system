<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StructureScope;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BookingClosureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A period when something is not bookable (SPEC §6.2, §24.5).
 *
 * Named BookingClosure rather than Closure: inside this namespace the latter
 * would shadow PHP's own Closure, and a model is not worth breaking every
 * callback type hint in the application for.
 */
#[Fillable([
    'scope', 'scope_id',
    'date_from', 'date_to', 'time_from', 'time_to',
    'reason', 'is_quick_stop',
])]
class BookingClosure extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BookingClosureFactory> */
    use HasFactory;

    protected $table = 'closures';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => StructureScope::class,
            'date_from' => 'date',
            'date_to' => 'date',
            'is_quick_stop' => 'boolean',
        ];
    }

    /**
     * Covers the whole day rather than part of it.
     */
    public function isAllDay(): bool
    {
        return blank($this->time_from) && blank($this->time_to);
    }

    /**
     * Closures that could apply to a date, for any of the three scopes.
     *
     * Deliberately one query for the whole page: a booking page checks the
     * same handful of closures against twenty resources.
     *
     * @param  Builder<BookingClosure>  $query
     * @param  array<int, int>  $areaIds
     * @param  array<int, int>  $resourceIds
     * @return Builder<BookingClosure>
     */
    public function scopeCovering(
        Builder $query,
        int $bookingTypeId,
        array $areaIds = [],
        array $resourceIds = [],
    ): Builder {
        return $query->where(function (Builder $query) use ($bookingTypeId, $areaIds, $resourceIds): void {
            $query->where(function (Builder $query) use ($bookingTypeId): void {
                $query->where('scope', StructureScope::BookingType->value)
                    ->where('scope_id', $bookingTypeId);
            });

            if ($areaIds !== []) {
                $query->orWhere(function (Builder $query) use ($areaIds): void {
                    $query->where('scope', StructureScope::Area->value)
                        ->whereIn('scope_id', $areaIds);
                });
            }

            if ($resourceIds !== []) {
                $query->orWhere(function (Builder $query) use ($resourceIds): void {
                    $query->where('scope', StructureScope::Resource->value)
                        ->whereIn('scope_id', $resourceIds);
                });
            }
        });
    }

    /**
     * @param  Builder<BookingClosure>  $query
     * @return Builder<BookingClosure>
     */
    public function scopeOnDate(Builder $query, string $date): Builder
    {
        return $query->where('date_from', '<=', $date)->where('date_to', '>=', $date);
    }
}
