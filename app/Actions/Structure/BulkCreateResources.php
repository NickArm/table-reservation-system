<?php

declare(strict_types=1);

namespace App\Actions\Structure;

use App\Enums\PlanLimit;
use App\Enums\ResourceType;
use App\Models\Area;
use App\Models\BookingType;
use App\Models\Resource;
use App\Models\ResourceTemplate;
use App\Subscriptions\Entitlements;
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
    public function __construct(private readonly Entitlements $entitlements) {}

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

        /*
         * The whole batch is checked against the limit before any of it is
         * written, so "A1 to A20" with room for twelve is refused with a
         * number rather than creating twelve and stopping. Half a row of
         * sunbeds is worse than none: the owner has to work out which ones
         * exist before they can try again.
         */
        $this->entitlements->ensureRoomFor(PlanLimit::Resources, $to - $from + 1);

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

                $resource = Resource::make([
                    ...$base,
                    'label' => $label,
                    'sort_order' => ++$sortOrder,
                ]);

                // The two structural keys are deliberately absent from the
                // model's fillable list and are set here instead: a request
                // must never be able to move a resource to another booking
                // type or area by posting an id with the rest of the form.
                $resource->booking_type_id = $area->booking_type_id;
                $resource->area_id = $area->id;

                $resource->save();

                $created->push($resource);
            }

            return $created;
        });
    }
}
