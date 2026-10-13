<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Actions\Structure\CreateBookingType;
use App\Enums\BookingMode;
use App\Enums\Occupancy;
use App\Enums\PaymentRequirement;
use App\Enums\PriceRuleType;
use App\Enums\ResourceType;
use App\Enums\StructureScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Structure\StoreBookingTypeRequest;
use App\Models\Area;
use App\Models\BookingClosure;
use App\Models\BookingType;
use App\Models\PriceRule;
use App\Models\Resource;
use App\Models\ResourceTemplate;
use App\Models\Venue;
use App\Support\BookingTypePresets;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\View\View;

final class BookingTypeController extends Controller
{
    public function index(TenantContext $context): View
    {
        $tenant = $context->getOrFail();

        return view('dashboard.booking-types.index', [
            'tenant' => $tenant,
            'venue' => Venue::query()->first(),
            'bookingTypes' => BookingType::query()->orderBy('sort_order')->get(),
            'counts' => Resource::query()
                ->selectRaw('booking_type_id, count(*) as total')
                ->groupBy('booking_type_id')
                ->pluck('total', 'booking_type_id'),
            'presets' => BookingTypePresets::all(),
            'modes' => BookingMode::cases(),
            'resourceTypes' => ResourceType::cases(),
            'paymentOptions' => PaymentRequirement::cases(),
        ]);
    }

    public function store(
        StoreBookingTypeRequest $request,
        CreateBookingType $create,
    ): RedirectResponse {
        $venue = Venue::query()->firstOrFail();

        $mode = BookingMode::from((string) $request->string('booking_mode'));

        $bookingType = $create->handle($venue, [
            ...CreateBookingType::defaultsFor($mode),
            'name' => (string) $request->string('name'),
            'description' => $request->input('description'),
            'resource_type' => ResourceType::from((string) $request->string('resource_type')),
            'booking_mode' => $mode,
            'payment_requirement' => PaymentRequirement::from((string) $request->string('payment_requirement')),
            'deposit_label' => $request->input('deposit_label'),
            'default_price_cents' => $request->priceCents(),
            'min_lead_time_minutes' => (int) $request->integer('min_lead_time_minutes'),
        ]);

        return redirect()
            ->route('dashboard.booking-types.show', $bookingType)
            ->with('status', "“{$bookingType->name}” created. Add your ".$bookingType->resource_type->plural().' next.');
    }

    public function show(BookingType $bookingType, TenantContext $context): View
    {
        $tenant = $context->getOrFail();

        $areas = Area::query()
            ->where('booking_type_id', $bookingType->id)
            ->orderBy('sort_order')
            ->get();

        $resources = Resource::query()
            ->where('booking_type_id', $bookingType->id)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get()
            ->groupBy('area_id');

        return view('dashboard.booking-types.show', [
            'tenant' => $tenant,
            'venue' => Venue::query()->first(),
            'bookingType' => $bookingType,
            'areas' => $areas,
            'resources' => $resources,
            'templates' => ResourceTemplate::query()->orderBy('name')->get(),
            'suggestedAreas' => $bookingType->resource_type->suggestedAreas(),
            'occupancyOptions' => Occupancy::cases(),
            'closures' => BookingClosure::query()
                ->covering(
                    $bookingType->id,
                    $areas->pluck('id')->all(),
                    $resources->collapse()->pluck('id')->all(),
                )
                ->where('date_to', '>=', now()->toDateString())
                ->orderBy('date_from')
                ->get(),
            'closureNames' => $this->closureNames($areas, $resources->collapse()),
            'priceRules' => PriceRule::query()
                ->covering(
                    $bookingType->id,
                    $areas->pluck('id')->all(),
                    $resources->collapse()->pluck('id')->all(),
                )
                ->orderByDesc('priority')
                ->get(),
            'priceTypes' => PriceRuleType::cases(),
            'weekdays' => [
                1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu',
                5 => 'Fri', 6 => 'Sat', 0 => 'Sun',
            ],
        ]);
    }

    /**
     * What each closure is actually closing, so the list reads "Garden row"
     * rather than "area 4".
     *
     * @param  Collection<int, Area>  $areas
     * @param  Collection<int, Resource>  $resources
     * @return array<string, string>
     */
    private function closureNames(Collection $areas, Collection $resources): array
    {
        $names = [];

        foreach ($areas as $area) {
            $names[StructureScope::Area->value.':'.$area->id] = $area->name;
        }

        foreach ($resources as $resource) {
            $names[StructureScope::Resource->value.':'.$resource->id] = $resource->label;
        }

        return $names;
    }
}
