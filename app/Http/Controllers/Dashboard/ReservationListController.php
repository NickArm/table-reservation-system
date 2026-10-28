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
use App\Support\StatusPill;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
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
            // One tap each, instead of a form filled in while a guest waits
            // on the telephone.
            'whenFilters' => $this->whenFilters($request, $search, $timezone),
            'statusFilters' => $this->statusFilters($request, $search),
            'statusSkins' => StatusPill::map(),
        ]);
    }

    /**
     * The date ranges actually asked for, as links.
     *
     * "Upcoming" first and on by default in spirit: the telephone call is
     * nearly always about a booking that has not happened yet.
     *
     * @return list<array{label: string, href: string, on: bool}>
     */
    private function whenFilters(Request $request, ReservationSearch $search, string $timezone): array
    {
        $today = CarbonImmutable::now($timezone);
        $keep = $request->only(['q', 'type', 'channel', 'sort', 'status']);

        $ranges = [
            ['label' => 'Everything', 'from' => null, 'to' => null],
            ['label' => 'Upcoming', 'from' => $today->toDateString(), 'to' => null],
            ['label' => 'Today', 'from' => $today->toDateString(), 'to' => $today->toDateString()],
            ['label' => 'Next 7 days', 'from' => $today->toDateString(), 'to' => $today->addDays(6)->toDateString()],
            ['label' => 'Past', 'from' => null, 'to' => $today->subDay()->toDateString()],
        ];

        return array_map(
            static fn (array $range): array => [
                'label' => $range['label'],
                'href' => route('dashboard.reservations.index', array_filter(
                    [...$keep, 'from' => $range['from'], 'to' => $range['to']],
                    static fn (mixed $value): bool => $value !== null && $value !== '',
                )),
                'on' => $search->from === $range['from'] && $search->to === $range['to'],
            ],
            $ranges,
        );
    }

    /**
     * The three answers a venue wants from a status: all of them, the ones
     * that still stand, and the ones that fell through.
     *
     * Not every case the enum has. A dropdown of nine statuses is a list
     * somebody reads twice; the full set stays in the advanced form.
     *
     * @return list<array{label: string, href: string, on: bool}>
     */
    private function statusFilters(Request $request, ReservationSearch $search): array
    {
        $keep = $request->only(['q', 'type', 'channel', 'sort', 'from', 'to']);

        $options = [
            ['label' => 'Any status', 'status' => null],
            ['label' => 'Confirmed', 'status' => ReservationStatus::Confirmed->value],
            ['label' => 'Waiting to pay', 'status' => ReservationStatus::PendingPayment->value],
            ['label' => 'Cancelled', 'status' => ReservationStatus::CancelledByVenue->value],
            ['label' => 'No-show', 'status' => ReservationStatus::NoShow->value],
        ];

        return array_map(
            static fn (array $option): array => [
                'label' => $option['label'],
                'href' => route('dashboard.reservations.index', array_filter(
                    [...$keep, 'status' => $option['status']],
                    static fn (mixed $value): bool => $value !== null && $value !== '',
                )),
                'on' => $search->status === $option['status'],
            ],
            $options,
        );
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
