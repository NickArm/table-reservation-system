<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use App\Models\Venue;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Config;

/**
 * The From and Reply-To split that every message in this system uses
 * (SPEC §10.3).
 *
 *   From:     "Demo Beach Bar" <noreply@tablereserved.com>
 *   Reply-To: info@demobeachbar.gr
 *
 * The guest sees the venue's name, replies reach the venue, and the message is
 * signed by a domain we control. Sending as the venue's own address would mean
 * asking every owner for DNS access before their first booking, and most of
 * them do not have it.
 */
trait SendsOnBehalfOfVenue
{
    /**
     * @param  list<string>  $replyTo
     */
    protected function asVenue(Venue $venue, string $subject, array $replyTo = []): Envelope
    {
        $from = new Address(
            (string) Config::string('mail.from.address'),
            (string) $venue->name,
        );

        if ($replyTo === []) {
            $replyTo = array_values(array_filter([
                filled($venue->public_email) ? (string) $venue->public_email : null,
            ]));
        }

        return new Envelope(
            from: $from,
            replyTo: $replyTo,
            subject: $subject,
        );
    }
}
