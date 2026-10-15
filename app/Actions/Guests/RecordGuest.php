<?php

declare(strict_types=1);

namespace App\Actions\Guests;

use App\Models\Guest;
use App\Models\Reservation;

/**
 * Finds the person this booking belongs to, or writes them down (SPEC §28.2).
 *
 * Matching is on `(venue_id, normalised email)` and nothing else. No fuzzy
 * matching on names, no phone as a second key: families share a phone, and a
 * wrong merge is worse than a duplicate, because it hands one guest another
 * guest's history and private notes. If somebody uses two addresses they are
 * two records, and a manual merge can be added later if anybody asks.
 *
 * A booking with no email — which happens on the telephone — simply has no
 * guest record. That is honest: there is nothing to match it to next time.
 *
 * Anonymising a guest clears their address (SPEC §28.5), so somebody who books
 * again afterwards becomes a new record. The old link is severed on purpose:
 * that is what erasure means.
 *
 * The counters are not touched here. They are derived from the reservations,
 * so they are recomputed by ReservationObserver once the booking exists.
 */
final readonly class RecordGuest
{
    /**
     * Called inside the same transaction as the reservation, so a guest is
     * never left behind by a booking that rolled back.
     */
    public function handle(Reservation $reservation): ?Guest
    {
        $email = Guest::normalizeEmail($reservation->customer_email);

        if ($email === null) {
            return null;
        }

        $guest = Guest::query()
            ->where('venue_id', $reservation->venue_id)
            ->where('email', $email)
            ->first();

        if ($guest === null) {
            $guest = new Guest;
            $guest->venue_id = $reservation->venue_id;
            $guest->email = $email;
            $guest->first_seen_at = $reservation->starts_at;
        }

        // The latest wins. People change phones, and spell their own name
        // differently depending on the day.
        $guest->name = (string) $reservation->customer_name;
        $guest->locale = (string) $reservation->locale;

        if (filled($reservation->customer_phone)) {
            $guest->phone = (string) $reservation->customer_phone;
        }

        // Consent is only ever granted, never withdrawn by booking again: a
        // guest who ticked the box last summer did not untick it by making a
        // booking that happens not to show one (SPEC §28.4).
        if ($reservation->marketing_consent && ! $guest->marketing_consent) {
            $guest->marketing_consent = true;
            $guest->marketing_consent_at = $reservation->marketing_consent_at ?? now();
        }

        $guest->save();

        $reservation->guest_id = $guest->id;

        return $guest;
    }
}
