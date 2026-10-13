<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicSite;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\BookingType;
use App\Models\Resource;
use App\Models\Tenant;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;

final class BookingPageController extends Controller
{
    public function show(Tenant $tenant): View
    {
        // Queried rather than read off the relation: strict mode forbids lazy
        // loading, and the tenant scope already narrows this to one venue.
        $venue = Venue::query()->first();

        abort_if($venue === null, 404);

        $bookingTypes = BookingType::query()->active()->get();

        return view('public.booking', [
            'tenant' => $tenant,
            'venue' => $venue,
            'bookingTypes' => $bookingTypes,
            'offerings' => $this->offerings($bookingTypes),
            'today' => \Carbon\CarbonImmutable::now((string) $venue->timezone)->toDateString(),
            'indexable' => $venue->shouldBeIndexed() && $tenant->acceptsBookings(),
            'structuredData' => $this->structuredData($tenant, $venue),
        ]);
    }

    /**
     * What is actually on offer, per booking type: the areas a guest can
     * choose from and the cheapest open spot in each.
     *
     * Two queries for the whole page, whatever the number of sunbeds — the
     * counting happens in the database, not in PHP.
     *
     * @param  Collection<int, BookingType>  $bookingTypes
     * @return array<int, array{areas: Collection<int, Area>, open: int, from_cents: int|null}>
     */
    private function offerings(Collection $bookingTypes): array
    {
        if ($bookingTypes->isEmpty()) {
            return [];
        }

        $ids = $bookingTypes->pluck('id');

        $areas = Area::query()
            ->whereIn('booking_type_id', $ids)
            ->orderBy('sort_order')
            ->get()
            ->groupBy('booking_type_id');

        $stats = Resource::query()
            ->bookable()
            ->whereIn('booking_type_id', $ids)
            ->selectRaw('booking_type_id, count(*) as open_count, min(base_price_cents) as from_cents')
            ->groupBy('booking_type_id')
            ->get()
            ->keyBy('booking_type_id');

        return $bookingTypes
            ->mapWithKeys(fn (BookingType $type): array => [
                $type->id => [
                    'areas' => $areas->get($type->id) ?? new Collection,
                    'open' => (int) ($stats->get($type->id)?->open_count ?? 0),
                    'from_cents' => $stats->get($type->id)?->from_cents === null
                        ? null
                        : (int) $stats->get($type->id)->from_cents,
                ],
            ])
            ->all();
    }

    /**
     * schema.org markup for the venue, with a ReserveAction.
     *
     * The action is here from the start so that Reserve with Google is a
     * registration away rather than a rebuild (SPEC §33.2).
     *
     * @return array<string, mixed>
     */
    private function structuredData(Tenant $tenant, Venue $venue): array
    {
        $type = match ($venue->venue_category) {
            'restaurant', 'taverna' => 'Restaurant',
            'beach_bar', 'cafe', 'club' => 'BarOrPub',
            default => 'LocalBusiness',
        };

        $data = [
            '@context' => 'https://schema.org',
            '@type' => $type,
            'name' => $venue->name,
            'url' => route('public.booking', $tenant),
        ];

        if (filled($venue->description)) {
            $data['description'] = str($venue->description)->limit(300)->value();
        }

        if (filled($venue->phone)) {
            $data['telephone'] = $venue->phone;
        }

        if (filled($venue->price_range)) {
            $data['priceRange'] = $venue->price_range;
        }

        if (filled($venue->cuisine) && $type === 'Restaurant') {
            $data['servesCuisine'] = $venue->cuisine;
        }

        if (filled($venue->address)) {
            $data['address'] = array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => $venue->address,
                'addressLocality' => $venue->city,
                'postalCode' => $venue->postal_code,
                'addressRegion' => $venue->region,
                'addressCountry' => $venue->country,
            ], static fn (?string $value): bool => filled($value));
        }

        if ($venue->latitude !== null && $venue->longitude !== null) {
            $data['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude' => $venue->latitude,
                'longitude' => $venue->longitude,
            ];
        }

        $data['potentialAction'] = [
            '@type' => 'ReserveAction',
            'target' => [
                '@type' => 'EntryPoint',
                'urlTemplate' => route('public.booking', $tenant),
                'inLanguage' => 'en',
                'actionPlatform' => [
                    'http://schema.org/DesktopWebPlatform',
                    'http://schema.org/MobileWebPlatform',
                ],
            ],
            'result' => [
                '@type' => 'Reservation',
                'name' => 'Table or sunbed reservation',
            ],
        ];

        return $data;
    }
}
