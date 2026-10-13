<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\SendsOnBehalfOfVenue;
use App\Vouchers\Voucher;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * A nudge before the table is due (SPEC §10.1).
 *
 * Short on purpose: when, where, the code, and the phone number. Anyone who
 * wants the detail still has the original confirmation and the voucher link.
 * The venue's real interest here is fewer no-shows, not a second brochure.
 */
final class BookingReminder extends Mailable
{
    use SendsOnBehalfOfVenue;

    public function __construct(public readonly Voucher $voucher) {}

    public function envelope(): Envelope
    {
        $today = $this->voucher->startsAt()->isSameDay($this->voucher->venue->now());

        return $this->asVenue(
            $this->voucher->venue,
            ($today ? 'Today' : $this->voucher->startsAt()->format('D j M'))
                .' at '.$this->voucher->venue->name.', '
                .$this->voucher->startsAt()->format('H:i'),
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'X-Reservation' => (string) $this->voucher->reservation->uuid,
        ]);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.reminder');
    }
}
