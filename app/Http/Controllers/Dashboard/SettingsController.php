<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Actions\Tenants\RequestAccountDeletion;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\Venue;
use App\Tenancy\TenantContext;
use Illuminate\View\View;

/**
 * The account itself, and the one irreversible thing in the product
 * (SPEC §13.1, §29).
 *
 * The counts are the point of the page. "This permanently deletes everything"
 * means nothing; "1,247 past reservations and 892 guests" is a sentence
 * somebody stops at. They are counted live rather than read off a cached
 * figure, because a number that is wrong on this screen is worse than no
 * number at all.
 */
final class SettingsController extends Controller
{
    public function __invoke(
        TenantContext $context,
        RequestAccountDeletion $deletion,
    ): View {
        $tenant = $context->getOrFail();

        return view('dashboard.settings', [
            'tenant' => $tenant,
            'venue' => Venue::query()->firstOrFail(),

            // What is standing in the way, if anything (§29.1).
            'blockers' => $deletion->blockers(),

            'counts' => [
                'resources' => Resource::query()->count(),
                'reservations' => Reservation::query()->count(),
                'upcoming' => Reservation::query()
                    ->whereIn('status', [
                        ReservationStatus::Confirmed->value,
                        ReservationStatus::CheckedIn->value,
                    ])
                    ->where('starts_at', '>=', now())
                    ->count(),
                'guests' => Guest::query()->count(),
                'people' => $tenant->users()->count(),
            ],
        ]);
    }
}
