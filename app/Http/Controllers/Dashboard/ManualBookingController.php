<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Actions\Reservations\CreateManualReservation;
use App\Availability\AvailabilityEngine;
use App\Availability\Exceptions\SpotNotAvailable;
use App\Enums\Channel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\StoreManualReservationRequest;
use App\Models\BookingType;
use App\Models\Resource;
use App\Models\Venue;
use App\Support\PhoneNumber;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Taking a booking over the telephone (SPEC §13.1).
 *
 * It follows the same three steps as the public page, for one reason: the
 * owner has watched guests do it, so the shape is already familiar. Pick what
 * and when, pick the spot, write down who.
 *
 * No JavaScript is needed to get through it. The only script on the page is
 * the autocomplete on the name field, and the form works without it.
 */
final class ManualBookingController extends Controller
{
    public function create(Request $request, TenantContext $context, AvailabilityEngine $engine): View
    {
        $venue = Venue::query()->firstOrFail();
        $timezone = (string) $venue->timezone;

        $bookingTypes = BookingType::query()->active()->orderBy('sort_order')->get();

        $bookingType = $bookingTypes->firstWhere('slug', $request->string('type')->value())
            ?? $bookingTypes->first();

        $partySize = max(1, (int) $request->integer('people', 2));
        $date = $this->date($request, $timezone);

        $times = $bookingType === null ? [] : $engine->times($bookingType, $date, $partySize);

        // The second step only appears once a time has been chosen, exactly
        // as it does for a guest.
        $chosen = $this->chosenTime($request, $times);
        $offers = $bookingType !== null && $chosen !== null
            ? $engine->at($bookingType, $chosen, $partySize)
            : [];

        return view('dashboard.bookings.create', [
            'tenant' => $context->getOrFail(),
            'venue' => $venue,
            'timezone' => $timezone,
            'bookingTypes' => $bookingTypes,
            'bookingType' => $bookingType,
            'partySize' => $partySize,
            'date' => $date,
            'times' => $times,
            'chosen' => $chosen,
            'offers' => $offers,
            'callingCodes' => PhoneNumber::callingCodes(),
            'channels' => [Channel::Phone, Channel::Walkin],
        ]);
    }

    public function store(
        StoreManualReservationRequest $request,
        CreateManualReservation $create,
    ): RedirectResponse {
        $resource = Resource::query()->findOrFail($request->integer('resource'));

        try {
            $reservation = $create->handle(
                resource: $resource,
                startsAt: CarbonImmutable::parse((string) $request->string('starts_at'))->utc(),
                partySize: (int) $request->integer('people'),
                details: [
                    'name' => (string) $request->string('name'),
                    'email' => $request->normalizedEmail(),
                    'phone' => $request->normalizedPhone(),
                    'notes' => $request->input('notes'),
                    'channel' => $request->channel(),
                    'amount_cents' => $request->amountCents(),
                    'paid' => $request->boolean('paid'),
                ],
                actorId: $request->user()?->id,
                force: $request->boolean('force'),
            );
        } catch (SpotNotAvailable $exception) {
            return back()
                ->withInput()
                ->withErrors(['resource' => $exception->getMessage().' Tick "book it anyway" to override.']);
        }

        return redirect()
            ->route('dashboard.reservations.show', $reservation)
            ->with('status', 'Booked. '.($reservation->customer_email !== null
                ? 'The guest has been emailed their confirmation.'
                : 'No email address, so nothing was sent to the guest.'));
    }

    private function date(Request $request, string $timezone): string
    {
        $raw = (string) $request->string('date');

        try {
            $date = $raw === ''
                ? CarbonImmutable::now($timezone)
                : CarbonImmutable::parse($raw, $timezone);
        } catch (Throwable) {
            $date = CarbonImmutable::now($timezone);
        }

        return $date->toDateString();
    }

    /**
     * @param  array<int, \App\Availability\TimeOffer>  $times
     */
    private function chosenTime(Request $request, array $times): ?CarbonImmutable
    {
        $raw = (string) $request->string('at');

        if ($raw === '') {
            return null;
        }

        try {
            $wanted = CarbonImmutable::parse($raw)->utc();
        } catch (Throwable) {
            return null;
        }

        // Only a time the engine actually offered: a timestamp typed into the
        // address bar should not open a booking form for a closed day.
        foreach ($times as $offer) {
            if ($offer->slot->startsAt->equalTo($wanted)) {
                return $wanted;
            }
        }

        return null;
    }
}
