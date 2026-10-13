<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PriceBasis;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ResourceTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Describe the thing once, stamp it out twenty times (SPEC §5.2).
 */
#[Fillable([
    'name', 'is_composite', 'components',
    'capacity_min', 'capacity_max',
    'base_price_cents', 'price_basis', 'meta',
])]
class ResourceTemplate extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ResourceTemplateFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_composite' => 'boolean',
            'components' => 'array',
            'meta' => 'array',
            'price_basis' => PriceBasis::class,
        ];
    }

    /**
     * What a resource made from this template starts with.
     *
     * @return array<string, mixed>
     */
    public function resourceAttributes(): array
    {
        return [
            'is_composite' => $this->is_composite,
            'components' => $this->components,
            'capacity_min' => $this->capacity_min,
            'capacity_max' => $this->capacity_max,
            'base_price_cents' => $this->base_price_cents,
            'price_basis' => $this->price_basis,
            'meta' => $this->meta,
        ];
    }
}
