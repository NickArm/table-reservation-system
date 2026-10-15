<?php

declare(strict_types=1);

namespace App\Actions\Guests;

use App\Models\Guest;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;

/**
 * A guest asks to be forgotten (SPEC §28.5).
 *
 * Anonymisation, not deletion, and the distinction is the point. The venue
 * keeps what it needs to run a business — how many covers it did in August,
 * what it took — while the person disappears from the database. Deleting the
 * reservations instead would quietly rewrite last season's figures, which is
 * not what anybody asked for and would make the books wrong.
 *
 * Clearing the email also releases the unique key, so the venue can take a
 * booking from that address again later as a new record.
 */
final readonly class AnonymizeGuest
{
    public function handle(Guest $guest): Guest
    {
        DB::transaction(function () use ($guest): void {
            // The personal columns on the reservations go too. What was
            // booked, when, and for how much all stay.
            Reservation::query()
                ->where('guest_id', $guest->id)
                ->update([
                    'customer_name' => 'Deleted guest',
                    'customer_email' => null,
                    'customer_phone' => null,
                    // Guests write things like "wheelchair access please" in
                    // the notes, which is exactly the sort of detail an
                    // erasure request is about.
                    'notes' => null,
                ]);

            $guest->forceFill([
                'name' => 'Deleted guest',
                'email' => null,
                'phone' => null,
                'internal_notes' => null,
                'tags' => null,
                'marketing_consent' => false,
                'marketing_consent_at' => null,
                'anonymized_at' => now(),
            ])->save();
        });

        return $guest;
    }
}
