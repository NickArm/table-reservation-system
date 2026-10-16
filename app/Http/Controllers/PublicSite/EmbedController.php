<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicSite;

use App\Availability\AvailabilityEngine;
use App\Http\Controllers\Controller;
use App\Models\BookingType;
use App\Models\Tenant;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The bare-iframe version of the search bar (SPEC §9.2, option 2).
 *
 * The script tag is the recommended route and does more; this exists for the
 * site builder who cannot add a script — a locked-down CMS, a marketing page
 * behind someone else's template, a customer who simply trusts an iframe more
 * than a script. It is the same bar, rendered server-side.
 *
 * It needs no JavaScript at all. The form carries `target="_top"`, so
 * pressing Search navigates the page the iframe sits in, which is what a
 * visitor expects and what a postMessage dance would have to reimplement.
 */
final class EmbedController extends Controller
{
    public function search(
        Request $request,
        Tenant $tenant,
        AvailabilityEngine $engine,
    ): Response {
        $venue = Venue::query()->firstOrFail();
        $timezone = (string) $venue->timezone;

        $bookingTypes = BookingType::query()->active()->get();

        $bookingType = $bookingTypes->firstWhere('slug', (string) $request->string('type'))
            ?? $bookingTypes->first();

        $partySize = max(1, min(12, (int) ($request->integer('people') ?: 2)));

        $today = CarbonImmutable::now($timezone)->toDateString();
        $date = (string) $request->string('date');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && $date >= $today ? $date : $today;

        $response = response()->view('public.embed', [
            'tenant' => $tenant,
            'venue' => $venue,
            'bookingTypes' => $bookingTypes,
            'bookingType' => $bookingType,
            'partySize' => $partySize,
            'date' => $date,
            'today' => $today,
            'theme' => $request->string('theme')->toString() === 'dark' ? 'dark' : 'light',

            // Offered times, so the dropdown holds nothing that would then be
            // refused. Empty when the venue has nothing set up yet.
            'times' => $bookingType === null ? [] : $engine->times($bookingType, $date, $partySize),

            'maxDate' => $bookingType === null
                ? $today
                : CarbonImmutable::now($timezone)
                    ->addDays((int) $bookingType->max_advance_days)
                    ->toDateString(),
        ]);

        /*
         * Framing is the whole point of this address, so it says so rather
         * than relying on the absence of a header. When a venue later asks to
         * restrict which sites may embed it, the allowlist belongs here
         * (SPEC §9.3).
         */
        return $response->header('Content-Security-Policy', 'frame-ancestors *');
    }
}
