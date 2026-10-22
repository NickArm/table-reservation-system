<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\BookingType;
use App\Models\Guest;
use App\Models\LegalDocument;
use App\Models\OpeningHour;
use App\Models\PriceRule;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\Venue;
use App\Tenancy\TenantContext;
use Illuminate\Http\Response;

/**
 * Everything in the account, as one JSON file (SPEC §29.2, §4.4).
 *
 * The CSV exports answer "what did August look like"; this one answers "give
 * me my data", which is a different question and a legal one — and the answer
 * has to be complete rather than convenient.
 *
 * Available in **every** state, including expired and awaiting deletion. The
 * spec promises that twice (§4.4, §29.2), and an export that stops working
 * when a subscription lapses is an export that is missing exactly when it is
 * needed.
 *
 * Two things are deliberately **not** in it:
 *
 *  - **payment gateway credentials.** They are the venue's own keys, they are
 *    encrypted at rest, and no endpoint in this application has ever returned
 *    them (§7.4). A download that leaks them into somebody's Downloads folder
 *    would undo that in one click.
 *  - **API token hashes.** Useless to the owner — the token itself is on the
 *    Developer screen — and a hash in a file is a hash somebody can try.
 */
final class AccountExportController extends Controller
{
    public function __invoke(TenantContext $context): Response
    {
        $tenant = $context->getOrFail();

        $payload = [
            'exported_at' => now()->toIso8601String(),
            'account' => [
                'name' => $tenant->name,
                'booking_page' => route('public.booking', $tenant),
                'timezone' => $tenant->timezone,
                'currency' => $tenant->currency,
                'created_at' => $tenant->created_at?->toIso8601String(),
            ],

            'venue' => Venue::query()->get()->map(
                static fn (Venue $venue): array => $venue->toArray()
            ),

            'booking_types' => BookingType::query()->orderBy('sort_order')->get()->toArray(),
            'areas' => Area::query()->orderBy('sort_order')->get()->toArray(),
            'resources' => Resource::query()->orderBy('sort_order')->get()->toArray(),
            'opening_hours' => OpeningHour::query()->get()->toArray(),
            'price_rules' => PriceRule::query()->get()->toArray(),

            /*
             * The bookings carry guest names, emails and telephone numbers,
             * which is the whole reason somebody asks for this file. It is
             * their data to hold; it is also the reason the download is a
             * one-off rather than a URL that can be shared.
             */
            'reservations' => Reservation::query()->orderBy('starts_at')->get()->toArray(),
            'guests' => Guest::query()->orderBy('id')->get()->toArray(),

            'legal_documents' => LegalDocument::query()->orderBy('id')->get()->toArray(),
        ];

        $name = $tenant->slug.'-export-'.now()->format('Y-m-d').'.json';

        /*
         * Built in memory rather than streamed. At this scale the whole
         * account is a few megabytes of JSON, and a streamed response that
         * fails half way through produces a file that looks complete and is
         * not — which is the worst possible outcome for a download somebody
         * takes because they are leaving.
         */
        return response(
            (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            200,
            [
                'Content-Type' => 'application/json',
                'Content-Disposition' => 'attachment; filename="'.$name.'"',
            ],
        );
    }
}
