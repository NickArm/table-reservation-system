<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicSite;

use App\Actions\Payments\StartCheckout;
use App\Actions\Reservations\ConfirmReservation;
use App\Availability\AvailabilityEngine;
use App\Availability\Exceptions\SpotNotAvailable;
use App\Enums\Channel;
use App\Events\ReservationWasConfirmed;
use App\Http\Controllers\Controller;
use App\Http\Controllers\PublicSite\Concerns\IdentifiesBookingSession;
use App\Http\Requests\PublicSite\StoreReservationRequest;
use App\Models\Area;
use App\Models\BookingType;
use App\Models\Reservation;
use App\Models\ReservationHold;
use App\Models\Resource;
use App\Models\Tenant;
use App\Models\Venue;
use App\Payments\GatewayManager;
use App\Support\PhoneNumber;
use App\Vouchers\Voucher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * The last screen before a booking exists, and the one after it (SPEC §8).
 */
final class ReservationController extends Controller
{
    use IdentifiesBookingSession;

    public function create(
        Request $request,
        Tenant $tenant,
        ReservationHold $hold,
        AvailabilityEngine $engine,
        GatewayManager $gateways,
    ): View|RedirectResponse {
        abort_unless($hold->session_token === $this->bookingToken($request), 403);

        if ($hold->hasExpired()) {
            return redirect()
                ->route('public.search', [$tenant, 'people' => $hold->party_size])
                ->withErrors(['spot' => 'Your spot was only held for a few minutes. Please choose again.']);
        }

        $resource = Resource::query()->findOrFail($hold->resource_id);
        $bookingType = BookingType::query()->findOrFail($hold->booking_type_id);
        $venue = Venue::query()->firstOrFail();

        return view('public.book', [
            'tenant' => $tenant,
            'venue' => $venue,
            'timezone' => (string) $venue->timezone,
            'hold' => $hold,
            'resource' => $resource,
            'area' => Area::query()->find($resource->area_id),
            'bookingType' => $bookingType,
            'price' => $engine->priceFor($resource, $hold->starts_at, (int) $hold->party_size),
            'callingCodes' => PhoneNumber::callingCodes(),
            'cancellationPolicy' => $this->cancellationPolicy($bookingType, $venue),
            'payOnline' => $this->paysOnline($gateways, $bookingType->needsPayment()),
            'testMode' => $gateways->settingsFor()?->gateway->isOnline()
                && $gateways->settingsFor()?->isTestMode(),
        ]);
    }

    public function store(
        StoreReservationRequest $request,
        Tenant $tenant,
        ReservationHold $hold,
        ConfirmReservation $confirm,
        StartCheckout $checkout,
    ): RedirectResponse {
        abort_unless($hold->session_token === $this->bookingToken($request), 403);

        try {
            $reservation = $confirm->handle($hold, [
                'name' => (string) $request->string('name'),
                'email' => $request->normalizedEmail(),
                'phone' => $request->normalizedPhone(),
                'notes' => $request->input('notes'),
                'marketing_consent' => $request->boolean('marketing'),
                'ip' => $request->ip(),
                'channel' => $this->channel($request),
                'referrer_domain' => $this->referrerDomain($request),
            ]);
        } catch (SpotNotAvailable $exception) {
            return redirect()
                ->route('public.search', [$tenant, 'people' => $hold->party_size])
                ->withErrors(['spot' => $exception->getMessage()]);
        }

        if ($reservation->status === \App\Enums\ReservationStatus::PendingPayment) {
            try {
                $session = $checkout->handle($reservation, $tenant);
            } catch (Throwable $exception) {
                // The venue's keys are wrong or the gateway is down. Better a
                // booking to settle at the counter than a lost guest.
                $reservation->forceFill([
                    'status' => \App\Enums\ReservationStatus::Confirmed,
                    'payment_status' => \App\Enums\PaymentStatus::PayOnArrival,
                ])->save();

                // The booking stands, so the guest still gets a voucher — with
                // the amount marked as payable on arrival.
                event(ReservationWasConfirmed::from($reservation));

                report($exception);

                return redirect()->route('public.confirmed', [$tenant, $reservation]);
            }

            if ($session->url !== null) {
                return redirect()->away($session->url);
            }
        }

        return redirect()->route('public.confirmed', [$tenant, $reservation]);
    }

    public function show(Tenant $tenant, Reservation $reservation): View
    {
        $venue = Venue::query()->firstOrFail();
        $bookingType = BookingType::query()->findOrFail($reservation->booking_type_id);

        return view('public.confirmed', [
            'tenant' => $tenant,
            'venue' => $venue,
            'timezone' => (string) $venue->timezone,
            'reservation' => $reservation,
            'bookingType' => $bookingType,
            'cancellationPolicy' => $this->cancellationPolicy($bookingType, $venue),
            // The same object the email and the PDF are built from, so this
            // page cannot drift away from what the guest was sent.
            'voucher' => Voucher::for($reservation),
        ]);
    }

    /**
     * Where the booking came from, for the one number that makes an owner
     * renew (SPEC §9.5).
     */
    private function channel(Request $request): Channel
    {
        $claimed = (string) $request->string('channel');

        return Channel::tryFrom($claimed) ?? Channel::Website;
    }

    private function referrerDomain(Request $request): ?string
    {
        $referrer = $request->headers->get('referer');

        if (! is_string($referrer) || $referrer === '') {
            return null;
        }

        $host = parse_url($referrer, PHP_URL_HOST);

        return is_string($host) && $host !== $request->getHost() ? $host : null;
    }

    private function paysOnline(GatewayManager $gateways, bool $needsPayment): bool
    {
        if (! $needsPayment) {
            return false;
        }

        $setting = $gateways->settingsFor();

        return $setting !== null && $setting->gateway->isOnline() && $setting->isUsable();
    }

    private function cancellationPolicy(BookingType $bookingType, Venue $venue): string
    {
        $policy = $bookingType->cancellation_policy;

        if (is_array($policy) && filled($policy['text'] ?? null)) {
            return (string) $policy['text'];
        }

        return filled($venue->phone)
            ? 'To change or cancel, please call us on '.$venue->phone.'.'
            : 'To change or cancel, please call the venue.';
    }
}
