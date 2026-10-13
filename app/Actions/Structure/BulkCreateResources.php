<?php

declare(strict_types=1);

namespace App\Actions\Structure;

use App\Enums\ResourceType;
use App\Models\Area;
use App\Models\BookingType;
use App\Models\Resource;
use App\Models\ResourceTemplate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Creates a run of resources in one go: "A1 to A20 in the 1st Row, from the
 * Beach Set template" (SPEC §5.2).
 *
 * Typing twenty near-identical records by hand is the fastest way to lose
 * someone during setup.
 */
final class BulkCreateResources
{
    /**
     * @return Collection<int, Resource>
     */
    public function handle(
        Area $area,
        string $prefix,
        int $from,
        int $to,
        ?ResourceTemplate $template = null,
        ?int $priceCentsOverride = null,
    ): Collection {
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        $bookingType = BookingType::query()->findOrFail($area->booking_type_id);

        // With no template, the booking type's own price and capacity apply.
        // Resources created with a price of zero by accident is the worst
        // possible default: the venue gives away its day without noticing.
        $base = $template?->resourceAttributes() ?? [
            'is_composite' => false,
            'components' => null,
            'capacity_min' => 1,
            'capacity_max' => $bookingType->resource_type === ResourceType::Table ? 4 : 2,
            'base_price_cents' => $bookingType->default_price_cents,
            'price_basis' => $bookingType->price_basis,
            'meta' => null,
        ];

        $base['base_price_cents'] = $priceCentsOverride ?? $base['base_price_cents'];

        return DB::transaction(function () use ($area, $prefix, $from, $to, $base): Collection {
            $existing = Resource::query()
                ->where('booking_type_id', $area->booking_type_id)
                ->pluck('label')
                ->flip();

            $sortOrder = (int) Resource::query()->where('area_id', $area->id)->max('sort_order');

            $created = new Collection;

            foreach (range($from, $to) as $number) {
                $label = $prefix.$number;

                // Labels are unique per booking type. Skipping a clash is
                // kinder than failing the whole batch halfway through.
                if ($existing->has($label)) {
                    continue;
                }

                $created->push(Resource::create([
                    ...$base,
                    'booking_type_id' => $area->booking_type_id,
                    'area_id' => $area->id,
                    'label' => $label,
                    'sort_order' => ++$sortOrder,
                ]));
            }

            return $created;
        });
    }
}
