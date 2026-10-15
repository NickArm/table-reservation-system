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
use App\Models\Resource;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A booking taken by the venue itself — over the telephone, or at the door
 * (SPEC §13.1).
 *
 * The same spot, the same guest list, the same voucher as a booking made
 * online; what differs is who is typing and what they are allowed to decide.
 *
 * Three deliberate differences from the public flow:
 *
 *  - **No hold and no online payment.** Money changes hands at the venue.
 *  - **The price can be overridden.** A regular rung up and was given the
 *    corner table at last year's rate; the owner knows that and the system
 *    does not. The computed price is the default, not the law.
 *  - **Availability can be overridden, on purpose.** If an owner says a table
 *    is free, it is free: they are standing in the room and we are not. The
 *    override is recorded rather than silent, so an impossible-looking double
 *    booking can be explained later.
 *
 * Terms are not recorded as accepted. Nobody ticked a box on the telephone,
 * and pretending otherwise would make the one piece of evidence that matters
 * in a dispute worthless everywhere else.
 */
final readonly class CreateManualReservation
{
    public function __construct(
        private AvailabilityEngine $engine,
        private RecordGuest $guests,
    ) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public function handle(
        Resource $resource,
        CarbonImmutable $startsAt,
        int $partySize,
        array $details,
        ?int $actorId = null,
        bool $force = false,
    ): Reservation {
        $reservation = DB::transaction(function () use (
            $resource, $startsAt, $partySize, $details, $actorId, $force
        ): Reservation {
            $locked = Resource::query()->whereKey($resource->id)->lockForUpdate()->firstOrFail();

            $bookingType = BookingType::query()->findOrFail($locked->booking_type_id);
            $area = Area::query()->find($locked->area_id);
            $venue = Venue::query()->firstOrFail();

            $this->engine->forget();

            if (! $force && ! $this->engine->canBook($locked, $startsAt, $partySize)) {
                throw SpotNotAvailable::taken($locked->label);
            }

            $price = $this->engine->priceFor($locked, $startsAt, $partySize);

            $amountCents = array_key_exists('amount_cents', $details) && $details['amount_cents'] !== null
                ? max(0, (int) $details['amount_cents'])
                : ($bookingType->needsPayment() ? $price->totalCents : 0);

            $reservation = new Reservation;

            $reservation->fill([
                'customer_name' => (string) $details['name'],
                'customer_email' => $details['email'] ?? null,
                'customer_phone' => $details['phone'] ?? null,
                'locale' => 'en',
                'party_size' => $partySize,
                'starts_at' => $startsAt,
                'ends_at' => $this->engine->endsAtFor($bookingType, $area, $startsAt),
                'notes' => $details['notes'] ?? null,
                'status' => ReservationStatus::Confirmed,
                'payment_status' => $this->paymentStatus($bookingType, $amountCents, (bool) ($details['paid'] ?? false)),
                'amount_cents' => $amountCents,
                'deposit_label_snapshot' => $bookingType->deposit_label,
                'channel' => $details['channel'] ?? Channel::Phone,
                'resource_snapshot' => [
                    'label' => $locked->label,
                    'area' => $area?->name,
                    'components' => $locked->components,
                    'capacity_max' => $locked->capacity_max,
                    'price_rule' => $price->rule?->describe(),
                ],
            ]);

            $reservation->venue_id = $venue->id;
            $reservation->booking_type_id = $bookingType->id;
            $reservation->area_id = $locked->area_id;
            $reservation->resource_id = $locked->id;
            $reservation->created_by = $actorId;

            if ($force) {
                $reservation->notes = trim(
                    'Booked over availability by the venue.'
                    .(filled($reservation->notes) ? "\n".$reservation->notes : '')
                );
            }

            $this->guests->handle($reservation);

            $reservation->save();

            return $reservation;
        });

        $this->engine->forget();

        // The guest gets the same voucher as anybody else — assuming they gave
        // an address. The venue is not told about a booking it just made
        // itself; see AlertTheVenue.
        event(ReservationWasConfirmed::from($reservation));

        return $reservation;
    }

    private function paymentStatus(BookingType $bookingType, int $amountCents, bool $paid): PaymentStatus
    {
        if ($amountCents <= 0) {
            return PaymentStatus::NotRequired;
        }

        if ($paid) {
            return PaymentStatus::Paid;
        }

        return $bookingType->payment_requirement === PaymentRequirement::None
            ? PaymentStatus::NotRequired
            : PaymentStatus::PayOnArrival;
    }
}
