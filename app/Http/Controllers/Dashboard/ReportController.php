<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Enums\PaymentRequirement;
use App\Http\Controllers\Controller;
use App\Models\BookingType;
use App\Models\Venue;
use App\Reports\SeasonFigures;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * How the season went (SPEC §36).
 *
 * Four periods and no date pickers by default, because the question is asked
 * in four shapes and the fifth is rare enough to live behind "Advanced"
 * (§1.1). "This season" is the one that matters here: a Corfu beach bar does
 * not think in calendar years, it thinks in Aprils to Octobers.
 */
final class ReportController extends Controller
{
    public function __invoke(Request $request, TenantContext $context): View
    {
        $tenant = $context->getOrFail();
        $venue = Venue::query()->first();
        $timezone = (string) ($venue?->timezone ?? $tenant->timezone);

        $periods = $this->periods($timezone);
        $key = $this->selectedKey($request, $periods);
        [$from, $to] = $this->window($request, $periods, $key, $timezone);

        $figures = new SeasonFigures($from, $to, $timezone);
        $bookingTypes = BookingType::query()->active()->orderBy('sort_order')->get();

        return view('dashboard.reports', [
            'tenant' => $tenant,
            'venue' => $venue,
            'timezone' => $timezone,
            'periods' => $periods,
            'selected' => $key,
            'from' => $from,
            // Shown rather than the exclusive bound: "to 1 November" for a
            // season that ends on the 31st reads as an error.
            'to' => $to->subDay(),
            'totals' => $figures->totals(),
            'money' => $figures->money(),
            'noShowCost' => $figures->noShowCostCents(),
            'channels' => $figures->channels(),
            'weekdays' => $figures->weekdays(),
            'byType' => $figures->byBookingType($bookingTypes),
            'occupancy' => $figures->occupancy(),
            'bookingTypes' => $bookingTypes,

            /*
             * Whether this venue takes money up front at all.
             *
             * Most do not: a Greek taverna confirms the table and the guest
             * pays at it. For them the first and largest figure on the screen
             * was "€0 taken online", which gives the most important place on
             * the page to a zero — and reads as a fault rather than as how
             * they chose to run.
             */
            'takesMoney' => $bookingTypes->contains(
                fn (BookingType $type): bool => $type->payment_requirement !== PaymentRequirement::None
            ),

            // Below this, a chart is a shape drawn through noise. Six
            // bookings split five ways is not a channel mix.
            'thin' => $figures->totals()['bookings'] < 20,
        ]);
    }

    /**
     * @return array<string, array{label: string, from: CarbonImmutable, to: CarbonImmutable}>
     */
    private function periods(string $timezone): array
    {
        $now = CarbonImmutable::now($timezone);

        /*
         * April to October. Not a calendar year and not a guess: it is the
         * window a seasonal venue on this coast is open, and a report that
         * averages a August over twelve months describes nothing anybody
         * recognises. A year-round taverna still sees every one of its
         * bookings, because the months outside it are empty for them too.
         */
        $seasonStart = $now->setDate($now->year, 4, 1)->startOfDay();
        $season = $now->lt($seasonStart) ? $seasonStart->subYear() : $seasonStart;

        return [
            'month' => [
                'label' => 'This month',
                'from' => $now->startOfMonth(),
                'to' => $now->startOfMonth()->addMonth(),
            ],
            'last-month' => [
                'label' => 'Last month',
                'from' => $now->startOfMonth()->subMonth(),
                'to' => $now->startOfMonth(),
            ],
            'season' => [
                'label' => 'This season',
                'from' => $season,
                'to' => $season->addMonths(7),
            ],
            'last-season' => [
                'label' => 'Last season',
                'from' => $season->subYear(),
                'to' => $season->subYear()->addMonths(7),
            ],
        ];
    }

    /**
     * @param  array<string, array{label: string, from: CarbonImmutable, to: CarbonImmutable}>  $periods
     */
    private function selectedKey(Request $request, array $periods): string
    {
        if ($request->filled('from') && $request->filled('to')) {
            return 'custom';
        }

        $asked = (string) $request->string('period');

        return array_key_exists($asked, $periods) ? $asked : 'season';
    }

    /**
     * @param  array<string, array{label: string, from: CarbonImmutable, to: CarbonImmutable}>  $periods
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(Request $request, array $periods, string $key, string $timezone): array
    {
        if ($key !== 'custom') {
            return [$periods[$key]['from'], $periods[$key]['to']];
        }

        try {
            $from = CarbonImmutable::parse((string) $request->string('from'), $timezone)->startOfDay();
            // Inclusive to the owner, exclusive to the query: somebody asking
            // for the 31st means the whole of it.
            $to = CarbonImmutable::parse((string) $request->string('to'), $timezone)->startOfDay()->addDay();
        } catch (Throwable) {
            return [$periods['season']['from'], $periods['season']['to']];
        }

        return $to->lte($from)
            ? [$from, $from->addDay()]
            : [$from, $to];
    }
}
