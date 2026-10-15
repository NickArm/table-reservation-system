<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Enums\Channel;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\BookingType;
use App\Models\Reservation;
use App\Models\Venue;
use App\Queries\ReservationSearch;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every booking the venue has ever taken, searchable (SPEC §13.1).
 *
 * Distinct from the Today screen on purpose. Today answers "what is happening
 * now", and is read twenty times a day during service. This answers "where is
 * that booking", and is opened when somebody telephones about one — so it
 * leads with search rather than with the clock.
 */
final class ReservationListController extends Controller
{
    public function index(Request $request, TenantContext $context): View
    {
        $venue = Venue::query()->first();
        $timezone = (string) ($venue?->timezone ?? $context->getOrFail()->timezone);

        $search = ReservationSearch::fromRequest($request, $timezone);

        $reservations = $search->apply()->paginate(50)->withQueryString();

        return view('dashboard.reservations.index', [
            'tenant' => $context->getOrFail(),
            'venue' => $venue,
            'timezone' => $timezone,
            'search' => $search,
            'reservations' => $reservations,
            'bookingTypes' => BookingType::query()->orderBy('sort_order')->get(),
            'statuses' => ReservationStatus::cases(),
            'channels' => Channel::cases(),
            'areaNames' => $this->areaNames($reservations->getCollection()),
            'totals' => $this->totals($search),
        ]);
    }

    /**
     * The same filtered set as the screen above it, as a CSV.
     *
     * Streamed, so a venue with three seasons of bookings can export the lot
     * without anybody raising a memory limit.
     */
    public function export(Request $request, TenantContext $context): StreamedResponse
    {
        $venue = Venue::query()->first();
        $timezone = (string) ($venue?->timezone ?? $context->getOrFail()->timezone);

        $search = ReservationSearch::fromRequest($request, $timezone);
        $names = $this->allAreaNames();

        return response()->streamDownload(function () use ($search, $timezone, $names): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            // Without the byte order mark, Excel on a Greek Windows machine
            // reads UTF-8 as Latin-1 and mangles every accented name.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'Code', 'Date', 'Time', 'Booking type', 'Area', 'Spot',
                'Name', 'Email', 'Phone', 'People',
                'Amount (EUR)', 'Payment', 'Status', 'Channel',
                'Booked at', 'Notes',
            ]);

            $search->apply()->chunk(500, function ($reservations) use ($out, $timezone, $names): void {
                foreach ($reservations as $reservation) {
                    $local = $reservation->startsAtLocal($timezone);

                    fputcsv($out, [
                        $reservation->code,
                        $local->format('Y-m-d'),
                        $local->format('H:i'),
                        $names['types'][$reservation->booking_type_id] ?? '',
                        $names['areas'][$reservation->area_id] ?? '',
                        $reservation->resource_snapshot['label'] ?? '',
                        $reservation->customer_name,
                        $reservation->customer_email,
                        $reservation->customer_phone,
                        $reservation->party_size,
                        number_format((int) $reservation->amount_cents / 100, 2, '.', ''),
                        $reservation->payment_status->value,
                        $reservation->status->value,
                        $reservation->channel->value,
                        $reservation->created_at?->setTimezone($timezone)->format('Y-m-d H:i'),
                        $reservation->notes,
                    ]);
                }
            });

            fclose($out);
        }, 'reservations-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Totals for the whole filtered set, not just the page on screen — the
     * question behind a date filter is almost always "how much was that week".
     *
     * @return array{bookings: int, guests: int, money: string}
     */
    private function totals(ReservationSearch $search): array
    {
        $row = $search->apply()
            // The sort is dropped: ordering by a column that is not aggregated
            // is what MySQL's ONLY_FULL_GROUP_BY refuses.
            ->reorder()
            ->selectRaw('count(*) as bookings')
            ->selectRaw('sum(party_size) as guests')
            ->selectRaw('sum(amount_cents) as money')
            ->toBase()
            ->first();

        return [
            'bookings' => (int) ($row->bookings ?? 0),
            'guests' => (int) ($row->guests ?? 0),
            'money' => Money::format((int) ($row->money ?? 0)),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Reservation>  $reservations
     * @return array<int, string>
     */
    private function areaNames($reservations): array
    {
        $ids = $reservations->pluck('area_id')->filter()->unique();

        if ($ids->isEmpty()) {
            return [];
        }

        return Area::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * Both lookups in one go for the export, which cannot afford a query per
     * row across three seasons of bookings.
     *
     * @return array{types: array<int, string>, areas: array<int, string>}
     */
    private function allAreaNames(): array
    {
        return [
            'types' => BookingType::query()->pluck('name', 'id')->all(),
            'areas' => Area::query()->pluck('name', 'id')->all(),
        ];
    }
}
