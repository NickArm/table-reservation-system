<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Structure\StoreAreaRequest;
use App\Models\Area;
use App\Models\BookingType;
use App\Models\Resource;
use Illuminate\Http\RedirectResponse;

final class AreaController extends Controller
{
    public function store(StoreAreaRequest $request, BookingType $bookingType): RedirectResponse
    {
        $area = new Area;

        $area->fill($request->attributesForArea());
        $area->booking_type_id = $bookingType->id;
        $area->sort_order = (int) Area::query()
            ->where('booking_type_id', $bookingType->id)
            ->max('sort_order') + 1;
        $area->save();

        return back()->with('status', "“{$area->name}” added.");
    }

    public function update(StoreAreaRequest $request, Area $area): RedirectResponse
    {
        $area->fill($request->attributesForArea());
        $area->save();

        return back()->with('status', "“{$area->name}” saved.");
    }

    /**
     * Refused while the area still holds resources, so that nobody deletes a
     * row of twenty sunbeds by reaching for the wrong button.
     */
    public function destroy(Area $area): RedirectResponse
    {
        $held = Resource::query()->where('area_id', $area->id)->count();

        if ($held > 0) {
            return back()->withErrors([
                'area' => "“{$area->name}” still has {$held}. Move or remove them first.",
            ]);
        }

        $name = $area->name;
        $area->delete();

        return back()->with('status', "“{$name}” removed.");
    }
}
