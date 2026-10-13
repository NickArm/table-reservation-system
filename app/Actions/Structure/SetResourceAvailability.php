<?php

declare(strict_types=1);

namespace App\Actions\Structure;

use App\Enums\OnlineStatus;
use App\Models\Area;
use App\Models\Resource;
use Illuminate\Database\Eloquent\Builder;

/**
 * Opens or closes resources for online booking (SPEC §5.4).
 *
 * Always available, never blocked by existing bookings: an owner watching the
 * wind pick up has to be able to close the beach in one tap. Closing stops new
 * bookings only — the ones already paid for stay valid, and cancelling them is
 * a separate, deliberate act.
 */
final class SetResourceAvailability
{
    public function one(Resource $resource, OnlineStatus $status): Resource
    {
        $resource->online_status = $status;
        $resource->save();

        return $resource;
    }

    public function toggle(Resource $resource): Resource
    {
        return $this->one($resource, $resource->online_status->toggled());
    }

    /**
     * Every resource in an area.
     */
    public function forArea(Area $area, OnlineStatus $status): int
    {
        return Resource::query()
            ->where('area_id', $area->id)
            ->update(['online_status' => $status->value]);
    }

    /**
     * The first N resources of an area, in display order, with the rest closed.
     *
     * This is the "put ten of my twenty tables online" case, which is how most
     * owners actually use it.
     */
    public function openFirst(Area $area, int $count): int
    {
        $ordered = Resource::query()
            ->where('area_id', $area->id)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->pluck('id');

        $open = $ordered->take($count);
        $closed = $ordered->skip($count);

        $this->applyTo(Resource::query()->whereIn('id', $open), OnlineStatus::Open);
        $this->applyTo(Resource::query()->whereIn('id', $closed), OnlineStatus::Closed);

        return $open->count();
    }

    /**
     * @param  Builder<Resource>  $query
     */
    private function applyTo(Builder $query, OnlineStatus $status): void
    {
        if ($query->clone()->doesntExist()) {
            return;
        }

        $query->update(['online_status' => $status->value]);
    }
}
