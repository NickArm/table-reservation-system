<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Availability\AreaOffer;
use App\Availability\DayStatus;
use App\Availability\ResourceOffer;
use App\Availability\TimeOffer;
use App\Models\BookingType;
use App\Models\Venue;
use App\Support\Money;

/**
 * The whole of v1's response schema, in one file on purpose (SPEC §15.2.1).
 *
 * This is a published contract, not an internal convenience: fields may be
 * added, never removed, never retyped. Keeping every shape in one readable
 * file is what makes that promise checkable — somebody changing an endpoint
 * can see the entire surface they are promising not to break, instead of
 * inferring it from five controllers.
 *
 * Three rules the shapes follow:
 *
 *  - money is sent as integer cents *and* as a formatted string. Cents so the
 *    caller can do arithmetic without parsing, the string so a form can print
 *    a price without knowing our currency rules
 *  - times are sent as the venue's local wall clock *and* as an ISO instant.
 *    A form shows "10:30"; a calendar needs the instant, and working one out
 *    from the other requires knowing the venue's timezone and its DST
 *  - nothing here carries personal data. Availability is the same information
 *    anyone sees by opening the public page
 */
final readonly class Payload
{
    /**
     * @return array<string, mixed>
     */
    public static function venue(Venue $venue): array
    {
        return [
            'slug' => $venue->tenant->slug,
            'name' => $venue->name,
            'timezone' => (string) $venue->timezone,
            'currency' => (string) $venue->tenant->currency,
            'city' => $venue->city,
            'country' => $venue->country,
            'phone' => $venue->phone,
            'booking_url' => route('public.booking', $venue->tenant),
        ];
    }

    /**
     * @param  iterable<BookingType>  $bookingTypes
     * @return array<int, array<string, mixed>>
     */
    public static function bookingTypes(iterable $bookingTypes): array
    {
        $out = [];

        foreach ($bookingTypes as $bookingType) {
            $out[] = self::bookingType($bookingType);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function bookingType(BookingType $bookingType): array
    {
        return [
            'slug' => $bookingType->slug,
            'name' => $bookingType->name,
            'resource_type' => $bookingType->resource_type->value,
            'booking_mode' => $bookingType->booking_mode->value,
            'from_price_cents' => (int) $bookingType->default_price_cents,
            'from_price' => Money::format((int) $bookingType->default_price_cents),

            // The two bounds a custom date picker needs: how far ahead a
            // guest may book, and how close to the time. Without them a form
            // offers dates we will refuse.
            'max_advance_days' => (int) $bookingType->max_advance_days,
            'min_lead_time_minutes' => (int) $bookingType->min_lead_time_minutes,
        ];
    }

    /**
     * A month at a glance, for colouring a date picker.
     *
     * @param  array<string, DayStatus>  $statuses
     * @return array<int, array<string, string>>
     */
    public static function days(array $statuses): array
    {
        $out = [];

        foreach ($statuses as $date => $status) {
            $out[] = ['date' => $date, 'status' => $status->value];
        }

        return $out;
    }

    /**
     * @param  array<int, TimeOffer>  $offers
     * @return array<int, array<string, mixed>>
     */
    public static function times(array $offers): array
    {
        $out = [];

        foreach ($offers as $offer) {
            $out[] = [
                'time' => $offer->label(),
                'starts_at' => $offer->slot->startsAt->toIso8601String(),
                'ends_at' => $offer->slot->endsAt->toIso8601String(),
                'available' => $offer->available,
                'from_price_cents' => $offer->fromCents,
                'from_price' => $offer->fromCents === null
                    ? null
                    : Money::format($offer->fromCents),
            ];
        }

        return $out;
    }

    /**
     * What is free at one time, grouped by area the way the booking page
     * shows it.
     *
     * @param  array<int, AreaOffer>  $areas
     * @return array<int, array<string, mixed>>
     */
    public static function availability(array $areas): array
    {
        $out = [];

        foreach ($areas as $area) {
            $out[] = [
                'area' => [
                    'name' => $area->area->name,
                    'description' => $area->area->description,
                ],
                'available' => $area->count(),
                'from_price_cents' => $area->fromCents(),
                'from_price' => $area->fromCents() === null
                    ? null
                    : Money::format($area->fromCents()),
                'resources' => array_map(
                    static fn (ResourceOffer $offer): array => self::resource($offer),
                    $area->offers,
                ),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function resource(ResourceOffer $offer): array
    {
        return [
            'label' => $offer->label(),

            // "2 Sunbeds · Umbrella · Side table", empty for a plain table.
            'contents' => $offer->contents(),
            'capacity_min' => (int) $offer->resource->capacity_min,
            'capacity_max' => (int) $offer->resource->capacity_max,
            'price_cents' => $offer->priceCents(),
            'price' => Money::format($offer->priceCents()),
            'starts_at' => $offer->slot->startsAt->toIso8601String(),
            'ends_at' => $offer->slot->endsAt->toIso8601String(),
            'minutes' => $offer->slot->minutes(),
        ];
    }
}
