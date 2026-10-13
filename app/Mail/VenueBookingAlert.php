<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Reservation;
use App\Models\Venue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Config;

/**
 * What the venue gets when a booking lands (SPEC §10.2).
 *
 * The subject line carries the whole booking, because this is read on a phone
 * behind a bar and most of the time the notification panel is as far as it
 * gets: "New booking · Sat 19 Jul 20:30 · A4 · 2 people".
 *
 * Reply-To is the guest, so that hitting reply writes to the person who
 * booked rather than to us.
 */
final class VenueBookingAlert extends Mailable
{
    public function __construct(
        public readonly Venue $venue,
        public readonly Reservation $reservation,
        public readonly string $timezone,
    ) {}

    public function envelope(): Envelope
    {
        $local = $this->reservation->startsAtLocal($this->timezone);

        $what = $this->reservation->resource_snapshot['label'] ?? null;

        $subject = 'New booking · '.$local->format('D j M H:i')
            .(filled($what) ? ' · '.$what : '')
            .' · '.$this->reservation->party_size.'p';

        return new Envelope(
            from: new Address((string) Config::string('mail.from.address'), (string) $this->venue->name),
            replyTo: array_values(array_filter([
                filled($this->reservation->customer_email)
                    ? new Address((string) $this->reservation->customer_email, (string) $this->reservation->customer_name)
                    : null,
            ])),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.venue.new-booking');
    }
}
