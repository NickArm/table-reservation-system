<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PlatformDocumentType;
use App\Models\PlatformDocument;
use Illuminate\Database\Seeder;

/**
 * Placeholder legal texts (SPEC §12.2).
 *
 * The real wording comes from a lawyer and is pasted in through the admin
 * screen without a deploy. Until then these exist so that the links in the
 * sign-up form and in emails lead somewhere, and so that the acceptance
 * machinery has a version to point at.
 *
 * Every one carries an HTML marker that makes the public page say, loudly,
 * that the text is a draft pending legal review. That is deliberate: the
 * danger with placeholder legal text is not that it is missing, it is that it
 * reads like the real thing and somebody relies on it.
 *
 * The content is not filler either. It says what we actually intend —
 * especially the three points that matter commercially and are easy to get
 * wrong: the venue sells to its own guests, tax compliance is the venue's,
 * and we are the processor rather than the controller of a venue's guest
 * list. That gives the lawyer something to correct rather than a blank page.
 *
 * Idempotent, so a deploy can run it again. It never touches a version that
 * has been published by a person — only its own placeholder.
 */
class PlatformDocumentSeeder extends Seeder
{
    private const PLACEHOLDER_VERSION = '0.1-draft';

    public function run(): void
    {
        foreach (PlatformDocumentType::cases() as $type) {
            // Anything published by a person wins. The placeholder is only
            // for the gap before the real text arrives.
            $published = PlatformDocument::query()
                ->where('type', $type->value)
                ->whereNotNull('published_at')
                ->where('version', '!=', self::PLACEHOLDER_VERSION)
                ->exists();

            if ($published) {
                continue;
            }

            PlatformDocument::query()->updateOrCreate(
                ['type' => $type->value, 'version' => self::PLACEHOLDER_VERSION],
                [
                    'content' => $this->content($type),
                    'change_summary' => null,
                    'needs_reacceptance' => false,
                    'published_at' => now(),
                    'is_current' => true,
                ],
            );
        }
    }

    private function content(PlatformDocumentType $type): string
    {
        $app = (string) config('app.name');

        $header = "<!--placeholder-->\n\n"
            .'> **This text is a placeholder, not legal advice and not a final '
            .'contract.** It describes what we intend to say. The wording will be '
            ."replaced by our lawyer's.\n\n";

        return $header.match ($type) {
            PlatformDocumentType::Terms => <<<MD
                ## What this service is

                {$app} is booking software. Venues use it to take reservations for
                tables and sunbeds from their own guests.

                ## Who sells what to whom

                **The venue is the seller.** When a guest pays for a reservation, they
                are paying the venue, through the venue's own payment provider and into
                the venue's own account. We never hold that money and we are not a
                party to that transaction.

                What we sell is the software, by subscription, to the venue.

                ## Tax and receipts

                **Issuing tax documents is the venue's responsibility.** A guest
                receives a booking confirmation from this service, which is explicitly
                not a tax document. We do not issue invoices on a venue's behalf and we
                do not transmit anything to any tax authority.

                ## Availability

                We aim to keep the service available and make no guarantee of
                uninterrupted operation. Scheduled maintenance is announced in advance
                where it is likely to be noticed.

                ## Subscriptions and cancellation

                A subscription runs for the period it was bought for. Cancelling stops
                the next renewal and leaves the current period intact. After a
                subscription ends, an account's data is kept for twelve months so that
                a seasonal venue finds it again the following season.

                ## Ending an account

                A venue can delete its account at any time, subject to having no
                upcoming reservations that guests have already paid for.

                We may suspend an account for non-payment, for abuse, or where
                continuing would put us in breach of the law.

                ## Liability

                To be drafted.

                ## Governing law

                To be drafted.
                MD,

            PlatformDocumentType::Privacy => <<<'MD'
                ## What we hold, and for whom

                Two different things, and the distinction matters:

                **Venue account data** — the name, email and password of whoever runs
                a venue, and what they set up in the system. We decide how this is
                handled; we are the controller.

                **Guest data** — the name, email and telephone number of somebody who
                booked a table. The **venue** decides what happens to it. We only
                process it on the venue's instructions, and the terms of that are in
                the [Data Processing Agreement](/dpa).

                ## What we do with it

                Run the service: take bookings, send confirmations and reminders, take
                subscription payments, and answer support questions.

                We do not sell personal data. We do not use a guest's details to market
                anything to them.

                ## Who else sees it

                Only the suppliers the service needs to work: our email provider for
                sending confirmations, our payment providers for subscriptions, and our
                hosting provider. Each is bound by its own agreement with us.

                A venue's own payment provider — the one that takes a guest's money —
                is chosen and controlled by the venue, not by us.

                ## How long

                Guest data for as long as the venue keeps it, and a venue's own data
                for twelve months after a subscription ends, then thirty days after a
                deletion request. Our own accounting records for as long as tax law
                requires.

                ## Your rights

                Access, correction, erasure, portability and objection. For guest data,
                the request goes to the venue, which is the controller; we help the
                venue carry it out. For account data, write to us.

                ## Cookies

                One session cookie and one anti-forgery token, both strictly necessary.
                No analytics, no advertising, no third-party tracking of any kind — see
                the [Cookie Notice](/cookies).
                MD,

            PlatformDocumentType::Dpa => <<<MD
                ## Roles

                For the personal data of a venue's guests:

                - the **venue** is the data controller
                - **{$app}** is the data processor

                The venue decides what is collected and why. We act on its
                instructions, which in practice means operating the booking system it
                has configured.

                ## What we process, and why

                A guest's name, email address, telephone number, the reservation
                itself, and any note the guest wrote to the venue. For the purpose of
                taking and fulfilling that reservation, and for nothing else.

                ## Our commitments

                - process guest data only as instructed, never for our own purposes
                - keep it confidential, and bind our staff to the same
                - secure it appropriately: encryption in transit, payment credentials
                  encrypted at rest, access limited to what support requires and
                  logged when used
                - tell the venue without undue delay if there is a breach
                - help the venue answer a guest's access or erasure request
                - delete or return guest data when the account ends

                ## Sub-processors

                Our email provider, our hosting provider, and our own payment
                providers. A venue is told before a new one is added.

                ## Where the data is

                Within the European Economic Area.

                ## Erasure

                A guest asking the venue to be forgotten is handled by anonymising:
                the person's name, email, telephone and any private note are removed,
                and the reservations stay as operational records with nothing personal
                in them. The venue keeps its figures, the individual disappears.
                MD,

            PlatformDocumentType::Cookies => <<<'MD'
                ## There is no cookie banner, and that is on purpose

                This service sets **two** cookies, both strictly necessary for it to
                work at all:

                | Cookie | What it does |
                |---|---|
                | Session | Remembers which selection is being held while a guest is paying, and keeps whoever is signed in signed in |
                | Anti-forgery token | Stops a third-party site submitting a form on your behalf |

                Strictly necessary cookies do not require consent, which is why there
                is nothing to click here.

                ## What we deliberately do not use

                - no analytics of any kind
                - no advertising or social media pixels
                - no fonts loaded from somebody else's server
                - no embedded third-party maps — a static image with a link instead
                - the booking widget that venues put on their own websites stores
                  nothing in cookies at all, so it does not add a line to anybody
                  else's cookie banner either

                This is a commitment rather than a current state of affairs. Adding any
                of the above would mean a banner, and a banner is one more obstacle
                between a guest and a booking.
                MD,
        };
    }
}
