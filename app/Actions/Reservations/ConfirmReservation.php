<?php

declare(strict_types=1);

namespace App\Actions\Reservations;

use App\Actions\Guests\RecordGuest;
use App\Availability\AvailabilityEngine;
use App\Availability\Exceptions\SpotNotAvailable;
use App\Enums\Channel;
use App\Enums\PaymentRequirement;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Events\ReservationWasConfirmed;
use App\Models\Area;
use App\Models\BookingType;
use App\Models\Reservation;
use App\Models\ReservationHold;
use App\Models\Resource;
use App\Models\Venue;
use App\Payments\GatewayManager;
use Illuminate\Support\Facades\DB;

/**
 * Turns a hold into a booking (SPEC §8).
 *
 * Three things happen here that must not happen anywhere else:
 *
 *  - the price is worked out again, server side, whatever the form said
 *  - availability is checked once more inside the transaction
 *  - what was booked is written down as it is today, so the voucher stays
 *    true even after the owner rearranges a set
 */
final readonly class ConfirmReservation
{
    public function __construct(
        private AvailabilityEngine $engine,
        private GatewayManager $gateways,
        private RecordGuest $guests,
    ) {}

    /**
     * @param  array<string, mixed>  $guest
     */
    public function handle(ReservationHold $hold, array $guest): Reservation
    {
        return DB::transaction(function () use ($hold, $guest): Reservation {
            $hold->refresh();

            if ($hold->hasExpired()) {
                throw SpotNotAvailable::notOffered();
            }

            $resource = Resource::query()->lockForUpdate()->findOrFail($hold->resource_id);
            $bookingType = BookingType::query()->findOrFail($hold->booking_type_id);
            $area = Area::query()->find($resource->area_id);
            $venue = Venue::query()->firstOrFail();

            $this->engine->forget();

            // Somebody else's confirmed booking can only have landed on this
            // spot if the hold was ignored, but checking costs one query and
            // the alternative is two families at one table.
            if (! $this->isStillFree($hold)) {
                throw SpotNotAvailable::taken($resource->label);
            }

            $price = $this->engine->priceFor($resource, $hold->starts_at, (int) $hold->party_size);

            $amountCents = $bookingType->needsPayment() ? $price->totalCents : 0;
            $online = $this->takesPaymentOnline($bookingType, $amountCents);

            $reservation = new Reservation;

            $reservation->fill([
                'customer_name' => $guest['name'],
                'customer_email' => $guest['email'],
                'customer_phone' => $guest['phone'] ?? null,
                'locale' => $guest['locale'] ?? 'en',
                'marketing_consent' => (bool) ($guest['marketing_consent'] ?? false),
                'marketing_consent_at' => ($guest['marketing_consent'] ?? false) ? now() : null,
                'party_size' => (int) $hold->party_size,
                'starts_at' => $hold->starts_at,
                'ends_at' => $hold->ends_at,
                'notes' => $guest['notes'] ?? null,
                // A booking that has to be paid for online is not confirmed
                // until the money lands — but it holds its spot meanwhile,
                // so nobody else can take it mid-payment (SPEC §24.1).
                'status' => $online ? ReservationStatus::PendingPayment : ReservationStatus::Confirmed,
                'payment_status' => $online ? PaymentStatus::Pending : $this->paymentStatus($bookingType),
                'amount_cents' => $amountCents,
                'deposit_label_snapshot' => $bookingType->deposit_label,
                'channel' => $guest['channel'] ?? Channel::Website,
                'referrer_domain' => $guest['referrer_domain'] ?? null,
                'resource_snapshot' => [
                    'label' => $resource->label,
                    'area' => $area?->name,
                    'components' => $resource->components,
                    'capacity_max' => $resource->capacity_max,
                    'price_rule' => $price->rule?->describe(),
                ],
            ]);

            $reservation->venue_id = $venue->id;
            $reservation->booking_type_id = $bookingType->id;
            $reservation->area_id = $resource->area_id;
            $reservation->resource_id = $resource->id;

            // Which policy they agreed to, when, and from where — the only
            // defence if a cancellation is ever disputed (SPEC §12).
            $reservation->terms_accepted_at = now();
            $reservation->terms_accepted_ip = $guest['ip'] ?? null;

            // The venue's own guest list, matched on email within this venue
            // and nowhere wider (SPEC §28.1). Inside the transaction, so a
            // booking that rolls back leaves no stranded guest behind.
            $this->guests->handle($reservation);

            $reservation->save();

            $hold->delete();

            $this->engine->forget();

            // A booking that still has to be paid for online is announced by
            // the payment settling, not here — there is nothing to confirm to
            // anybody until the money lands (SPEC §24.1).
            if ($reservation->status === ReservationStatus::Confirmed) {
                event(ReservationWasConfirmed::from($reservation));
            }

            return $reservation;
        });
    }

    /**
     * Whether the guest is about to be sent to a gateway.
     *
     * A venue that has not set one up still takes bookings — the money is
     * simply settled at the counter, which is how most of them work anyway.
     */
    private function takesPaymentOnline(BookingType $bookingType, int $amountCents): bool
    {
        if ($amountCents <= 0 || ! $bookingType->needsPayment()) {
            return false;
        }

        $setting = $this->gateways->settingsFor();

        return $setting !== null && $setting->gateway->isOnline() && $setting->isUsable();
    }

    /**
     * Nothing taken online: a booking that would need a deposit is marked as
     * payable at the venue rather than pretending money has changed hands.
     */
    private function paymentStatus(BookingType $bookingType): PaymentStatus
    {
        return $bookingType->payment_requirement === PaymentRequirement::None
            ? PaymentStatus::NotRequired
            : PaymentStatus::PayOnArrival;
    }

    private function isStillFree(ReservationHold $hold): bool
    {
        return Reservation::query()
            ->blocking()
            ->where('resource_id', $hold->resource_id)
            ->overlapping($hold->starts_at, $hold->ends_at)
            ->doesntExist();
    }
}
