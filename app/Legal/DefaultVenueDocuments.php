<?php

declare(strict_types=1);

namespace App\Legal;

use App\Enums\VenueDocumentType;
use App\Models\Venue;

/**
 * The text a venue starts from (SPEC §12.1).
 *
 * The spec asks for "default templates the super admin provides that the
 * tenant edits". These are those templates, in code rather than in a database
 * row the admin edits — a deliberate trade, and worth stating.
 *
 * Editing the default would change nothing for any existing venue: each one
 * gets its own copy the first time it opens the screen, and from then on the
 * copy is theirs. So an admin screen for the default would affect only venues
 * that sign up afterwards, which is not worth a table, a controller and a
 * form. When there are enough venues for that to matter, the text moves and
 * nothing else does.
 *
 * What the text has to do is harder than being legally complete, and it is
 * the same problem as the placeholder on the platform side in reverse: this
 * one **will** be published, by somebody who will not read it. So it says the
 * things that are true of this product however the venue is run — the money
 * is a deposit, cancellations go through a telephone call, the venue is the
 * seller — and leaves the one genuinely local decision, how long before
 * arrival a refund is given, as an obvious blank the owner fills in.
 */
final readonly class DefaultVenueDocuments
{
    public function for(VenueDocumentType $type, Venue $venue): string
    {
        $name = $venue->name;
        $phone = filled($venue->phone) ? $venue->phone : 'our telephone number';

        return match ($type) {
            VenueDocumentType::Terms => <<<MD
                ## Booking with {$name}

                A reservation is confirmed once you have completed it online and
                received a booking confirmation by email. The confirmation carries a
                booking code; please have it with you when you arrive.

                ## What you pay online

                Any amount you pay when booking is a **deposit against your final
                bill** unless the booking page said otherwise. It is not an extra
                charge, and it is not a service fee — it comes off what you owe at the
                end.

                ## Arriving

                Please arrive within fifteen minutes of your booked time. After that we
                may release the table or sunbed to somebody else, and the cancellation
                policy applies.

                If you are running late, call us on {$phone} and we will hold it if we
                can.

                ## Changes and cancellations

                We do not take changes or cancellations online. Call us on {$phone} and
                we will sort it out with you. What happens to a payment is set out in
                our cancellation policy.

                ## Who you are buying from

                You are booking with **{$name}**. The payment goes to us, and any
                receipt or invoice you need comes from us. The booking software is
                provided by a third party and is not a party to this agreement.

                ## House rules

                Please add anything specific here — dress code, pets, children, smoking
                areas, whether outside food and drink are allowed.
                MD,

            VenueDocumentType::CancellationPolicy => <<<MD
                ## How to cancel

                Call us on {$phone}. We do not take cancellations by email or online,
                because a cancellation usually needs a short conversation — and
                often we can move your booking instead.

                ## Refunds

                **Cancel at least 24 hours before your booking and we refund what you
                paid in full.**

                Please change this to the notice period that suits you — 24 hours, 48
                hours, the morning of, or no refund at all. Whatever it says here is
                what your guests agree to, so it is worth a minute's thought.

                Within that notice period, we are not able to refund the deposit. It is
                a table or a sunbed that stays empty on a day we could have sold it.

                ## If you do not arrive

                A booking nobody turns up for is treated as a cancellation inside the
                notice period, and the deposit is not refunded.

                ## If we have to cancel

                If we cancel your booking for any reason — weather, a closure, anything
                on our side — you get everything you paid back in full, and we will try
                to offer you another day first.

                ## How long a refund takes

                Refunds go back to the card or account you paid from. Banks usually
                take five to ten working days to show it, which is outside our control.
                MD,
        };
    }
}
