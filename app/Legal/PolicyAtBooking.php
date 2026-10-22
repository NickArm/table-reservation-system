<?php

declare(strict_types=1);

namespace App\Legal;

use App\Enums\VenueDocumentType;
use App\Models\BookingType;
use App\Models\LegalDocument;
use App\Models\Venue;

/**
 * What the cancellation policy was when a booking was made (SPEC §12).
 *
 * The spec says the confirmation embeds the policy that applied, and until
 * now it did not: `Voucher::cancellationPolicy()` read the booking type's
 * live text while its own docblock claimed otherwise, so a venue that edited
 * its policy in August silently rewrote every confirmation it had sent in
 * July. This is the fix, and it is a snapshot for the same reason as
 * `deposit_label_snapshot` beside it — the only version of a term that
 * matters is the one the guest was shown.
 *
 * Three sources, in order, because venues arrive at this in three states:
 *
 *  1. a cancellation policy the venue wrote on its Legal screen
 *  2. the short line on the booking type, which existed before that screen
 *  3. "call us", which is true of every venue on this platform whether they
 *     have written anything or not (§8.3)
 *
 * Both booking paths snapshot it. Only the public one records an agreement to
 * it: nobody ticks a box over the telephone, and writing that they did would
 * make the record worthless everywhere else (§13.2.1).
 */
final readonly class PolicyAtBooking
{
    /**
     * The text to store on the booking, as the guest was shown it.
     */
    public function snapshot(BookingType $bookingType, Venue $venue): string
    {
        $document = LegalDocument::current(VenueDocumentType::CancellationPolicy);

        if ($document instanceof LegalDocument) {
            // The plain-text summary rather than the whole document: this
            // string is printed on an email, on a PDF and on a web page, and
            // the full policy lives at its own address which all three link
            // to.
            return $document->summary(400);
        }

        return $this->fallback($bookingType, $venue);
    }

    /**
     * Which version of the venue's terms a guest is agreeing to right now.
     *
     * Null when the venue has not written any, which is the normal state of a
     * new account and of every account that existed before the screen did.
     */
    public function currentTermsId(): ?int
    {
        $terms = LegalDocument::current(VenueDocumentType::Terms);

        return $terms?->getKey();
    }

    /**
     * What to show when the venue has written nothing.
     *
     * True of every venue here regardless: cancellations go through a
     * telephone call, and the number is on the confirmation (§8.3).
     */
    public function fallback(BookingType $bookingType, Venue $venue): string
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
