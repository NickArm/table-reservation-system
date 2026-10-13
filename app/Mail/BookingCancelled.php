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
 * The venue has cancelled a booking (SPEC §8.3).
 *
 * This message carries bad news and money, so it says plainly what was
 * cancelled, how much is coming back, and roughly when the bank will show it.
 * A guest who reads "refunded" and sees nothing for five days telephones the
 * venue, and the venue telephones us.
 */
final class BookingCancelled extends Mailable
{
    use SendsOnBehalfOfVenue;

    public function __construct(
        public readonly Voucher $voucher,
        public readonly int $refundedCents = 0,
        public readonly ?string $reason = null,
    ) {}

    public function envelope(): Envelope
    {
        return $this->asVenue(
            $this->voucher->venue,
            'Cancelled · '.$this->voucher->venue->name.' · '
                .$this->voucher->startsAt()->format('D j M, H:i'),
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
        return new Content(view: 'mail.cancelled');
    }
}
