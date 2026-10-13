<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicSite;

use App\Actions\Reservations\HoldResource;
use App\Availability\Exceptions\SpotNotAvailable;
use App\Http\Controllers\Controller;
use App\Http\Controllers\PublicSite\Concerns\IdentifiesBookingSession;
use App\Models\ReservationHold;
use App\Models\Resource;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Taking a spot off the board while the guest fills in their name (SPEC §6.4).
 */
final class HoldController extends Controller
{
    use IdentifiesBookingSession;

    public function store(Request $request, Tenant $tenant, HoldResource $holds): RedirectResponse
    {
        $validated = $request->validate([
            'resource' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'people' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $resource = Resource::query()->findOrFail($validated['resource']);

        // Release anything this browser was already holding: going back a
        // step and choosing a different spot should not keep the first one.
        $this->releaseOwnHolds($request);

        try {
            $hold = $holds->handle(
                resource: $resource,
                startsAt: CarbonImmutable::parse($validated['starts_at'])->utc(),
                partySize: (int) $validated['people'],
                sessionToken: $this->bookingToken($request),
            );
        } catch (SpotNotAvailable $exception) {
            return back()->withErrors(['spot' => $exception->getMessage()]);
        }

        return redirect()->route('public.book', [$tenant, $hold]);
    }

    public function destroy(
        Request $request,
        Tenant $tenant,
        ReservationHold $hold,
        HoldResource $holds,
    ): RedirectResponse {
        abort_unless($hold->session_token === $this->bookingToken($request), 403);

        $holds->release($hold);

        return redirect()->route('public.search', [
            $tenant,
            'type' => $hold->bookingType()->first()?->slug,
            'people' => $hold->party_size,
        ]);
    }

    private function releaseOwnHolds(Request $request): void
    {
        ReservationHold::query()
            ->where('session_token', $this->bookingToken($request))
            ->delete();
    }
}
