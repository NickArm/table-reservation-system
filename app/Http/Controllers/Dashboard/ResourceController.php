<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Actions\Structure\BulkCreateResources;
use App\Actions\Structure\SetResourceAvailability;
use App\Enums\OnlineStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Structure\BulkCreateResourcesRequest;
use App\Models\Area;
use App\Models\Resource;
use App\Models\ResourceTemplate;
use App\Subscriptions\Exceptions\PlanDoesNotAllowThis;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ResourceController extends Controller
{
    public function store(
        BulkCreateResourcesRequest $request,
        BulkCreateResources $create,
    ): RedirectResponse {
        $area = Area::query()->findOrFail($request->integer('area_id'));

        $template = $request->filled('template_id')
            ? ResourceTemplate::query()->findOrFail($request->integer('template_id'))
            : null;

        try {
            $created = $create->handle(
                area: $area,
                prefix: (string) $request->string('prefix'),
                from: (int) $request->integer('from'),
                to: (int) $request->integer('to'),
                template: $template,
                priceCentsOverride: $request->priceCents(),
            );
        } catch (PlanDoesNotAllowThis $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        if ($created->isEmpty()) {
            return back()->withErrors([
                'resources' => 'Those labels all exist already. Nothing was added.',
            ]);
        }

        return back()->with('status', $created->count()." added to “{$area->name}”.");
    }

    /**
     * One tap, one resource, no confirmation. Closing is reversible and the
     * owner is standing in the middle of service (SPEC §5.4).
     */
    public function toggle(Resource $resource, SetResourceAvailability $availability): RedirectResponse
    {
        $availability->toggle($resource);

        return back()->with('status', "{$resource->label} is now {$resource->online_status->label()}.");
    }

    /**
     * Opens or closes a whole area at once — the wind-picked-up button.
     */
    public function bulkAvailability(
        Request $request,
        Area $area,
        SetResourceAvailability $availability,
    ): RedirectResponse {
        $validated = $request->validate([
            'status' => ['required_without:open_first', 'nullable', 'in:open,closed'],
            'open_first' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        if (isset($validated['open_first'])) {
            $opened = $availability->openFirst($area, (int) $validated['open_first']);

            return back()->with(
                'status',
                "{$opened} open in “{$area->name}”, the rest closed."
            );
        }

        $status = OnlineStatus::from((string) $validated['status']);
        $availability->forArea($area, $status);

        return back()->with('status', "“{$area->name}” is now {$status->label()}.");
    }

    public function destroy(Resource $resource): RedirectResponse
    {
        $label = $resource->label;
        $resource->delete();

        return back()->with('status', "{$label} removed.");
    }
}
