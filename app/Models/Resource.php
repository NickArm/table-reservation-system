<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OnlineStatus;
use App\Enums\PriceBasis;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ResourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bookable thing (SPEC §5.2).
 *
 * A composite is indivisible: nobody books one of the two sunbeds in a set. An
 * owner who wants that flexibility lists them singly instead.
 */
#[Fillable([
    'label', 'is_composite', 'components',
    'capacity_min', 'capacity_max',
    'base_price_cents', 'price_basis',
    'online_status', 'is_active', 'meta', 'sort_order',
    'pos_x', 'pos_y', 'width', 'height', 'rotation', 'shape',
])]
class Resource extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ResourceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_composite' => 'boolean',
            'is_active' => 'boolean',
            'components' => 'array',
            'meta' => 'array',
            'online_status' => OnlineStatus::class,
            'price_basis' => PriceBasis::class,
            'pos_x' => 'float',
            'pos_y' => 'float',
            'width' => 'float',
            'height' => 'float',
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
     * @return BelongsTo<Area, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /**
     * Offered to guests right now.
     *
     * @param  Builder<Resource>  $query
     * @return Builder<Resource>
     */
    public function scopeBookable(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('online_status', OnlineStatus::Open->value);
    }

    public function isBookable(): bool
    {
        return $this->is_active && $this->online_status->isOpen();
    }

    /**
     * "2 sunbeds · 1 umbrella · 1 side table", for the booking page and the
     * voucher. Empty for something sold on its own.
     */
    public function componentSummary(): string
    {
        if (! $this->is_composite) {
            return '';
        }

        return collect($this->components ?? [])
            ->map(function (array $component): string {
                $quantity = (int) ($component['qty'] ?? 1);
                $label = (string) ($component['label'] ?? '');

                return $quantity > 1 ? "{$quantity} {$label}s" : $label;
            })
            ->filter()
            ->implode(' · ');
    }

    public function priceCentsFor(int $partySize): int
    {
        return $this->price_basis->totalCents($this->base_price_cents, $partySize);
    }

    public function fitsParty(int $partySize): bool
    {
        return $partySize >= $this->capacity_min && $partySize <= $this->capacity_max;
    }

    /**
     * Has a place on the floor plan.
     */
    public function isPlaced(): bool
    {
        return $this->pos_x !== null && $this->pos_y !== null;
    }
}
