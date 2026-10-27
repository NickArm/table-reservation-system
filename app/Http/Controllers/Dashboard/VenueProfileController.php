<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Enums\VenueCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\VenueProfileRequest;
use App\Models\Venue;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The venue's public profile (SPEC §33.1).
 *
 * The screen that was missing for the whole of §33: the fields have been in
 * the database since M0 and the booking page has been reading them since M4,
 * but the only way to fill them in was the seeder. Which meant the SEO work
 * built in M4 — structured data, canonical, Open Graph — had nothing to
 * describe on any real account.
 *
 * None of it is required to take a booking, and the screen says so. A thin
 * profile costs search visibility and nothing else, which is why the nudge
 * is a percentage and a list rather than a wall of red.
 */
final class VenueProfileController extends Controller
{
    public function edit(TenantContext $context): View
    {
        $venue = Venue::query()->firstOrFail();

        return view('dashboard.profile', [
            'tenant' => $context->getOrFail(),
            'venue' => $venue,
            'categories' => VenueCategory::cases(),
            'completeness' => $venue->profileCompleteness(),
        ]);
    }

    public function update(
        VenueProfileRequest $request,
        TenantContext $context,
    ): RedirectResponse {
        $context->getOrFail();

        $venue = Venue::query()->firstOrFail();

        $venue->fill($request->safe()->except(['is_indexable']));

        // A checkbox absent from a submission means off, which `fill` cannot
        // express — the key simply is not there.
        $venue->is_indexable = $request->boolean('is_indexable');

        $venue->save();

        return back()->with('status', 'Saved.');
    }
}
