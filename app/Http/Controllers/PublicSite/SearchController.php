<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicSite;

use App\Availability\AvailabilityEngine;
use App\Availability\TimeOffer;
use App\Http\Controllers\Controller;
use App\Models\BookingType;
use App\Models\Tenant;
use App\Models\Venue;
use App\Payments\GatewayManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * What is free, for a guest standing on a beach with one bar of signal
 * (SPEC §8).
 *
 * One page: the times that have something, and underneath, the spots at the
 * chosen time grouped by row. No dead options — a time with nothing free is
 * simply not shown, which is the first thing people complain about in the
 * systems we are replacing (§8.1).
 */
final class SearchController extends Controller
{
    public function __invoke(
        Request $request,
        Tenant $tenant,
        AvailabilityEngine $engine,
        GatewayManager $gateways,
    ): View {
        $venue = Venue::query()->firstOrFail();
        $timezone = (string) $venue->timezone;

        $bookingType = $this->bookingType($request);
        $partySize = $this->partySize($request);
        $date = $this->date($request, $bookingType, $timezone);

        $times = $engine->times($bookingType, $date, $partySize);
        $selected = $this->selectedTime($request, $times, $timezone);

        return view('public.results', [
            'tenant' => $tenant,
            'venue' => $venue,
            'timezone' => $timezone,
            'bookingType' => $bookingType,
            'bookingTypes' => BookingType::query()->active()->get(),
            'partySize' => $partySize,
            'date' => $date,
            'localDate' => CarbonImmutable::parse($date, $timezone),
            'times' => $times,
            'selected' => $selected,
            'areas' => $selected === null ? [] : $engine->at($bookingType, $selected->slot->startsAt, $partySize),
            'maxDate' => CarbonImmutable::now($timezone)
                ->addDays((int) $bookingType->max_advance_days)
                ->toDateString(),
            'today' => CarbonImmutable::now($timezone)->toDateString(),
            'testMode' => $this->isTestMode($gateways),
        ]);
    }

    private function isTestMode(GatewayManager $gateways): bool
    {
        $setting = $gateways->settingsFor();

        return $setting !== null && $setting->gateway->isOnline() && $setting->isTestMode();
    }

    private function bookingType(Request $request): BookingType
    {
        $slug = (string) $request->string('type');

        if ($slug !== '') {
            return BookingType::query()->active()->where('slug', $slug)->firstOrFail();
        }

        return BookingType::query()->active()->firstOrFail();
    }

    private function partySize(Request $request): int
    {
        return max(1, min(20, (int) $request->integer('people', 2)));
    }

    private function date(Request $request, BookingType $bookingType, string $timezone): string
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $requested = (string) $request->string('date');

        $date = $this->parse($requested, $timezone) ?? $today;

        // A date in the past or beyond the horizon quietly becomes today,
        // rather than an error page for someone who edited a URL.
        if ($date->lessThan($today)) {
            $date = $today;
        }

        $furthest = $today->addDays((int) $bookingType->max_advance_days);

        return $date->greaterThan($furthest)
            ? $furthest->toDateString()
            : $date->toDateString();
    }

    /**
     * @param  array<int, TimeOffer>  $times
     */
    private function selectedTime(Request $request, array $times, string $timezone): ?TimeOffer
    {
        if ($times === []) {
            return null;
        }

        $wanted = substr((string) $request->string('time'), 0, 5);

        foreach ($times as $offer) {
            if ($offer->label() === $wanted) {
                return $offer;
            }
        }

        // Nothing asked for, or a time that is no longer free: offer the
        // first one that is, rather than an empty page.
        return $times[0];
    }

    private function parse(string $date, string $timezone): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($date, $timezone)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
