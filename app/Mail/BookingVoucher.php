<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\SendsOnBehalfOfVenue;
use App\Vouchers\Voucher;
use App\Vouchers\VoucherPdf;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * The booking confirmation (SPEC §11).
 *
 * Not a tax document, and it says so — that line is not decoration, it is the
 * boundary of what this product does.
 *
 * Deliberately not queued. The job that sends it already runs on the queue and
 * binds the tenant first; queueing the message as well would serialize models
 * that cannot be restored without a tenant in context.
 */
final class BookingVoucher extends Mailable
{
    use SendsOnBehalfOfVenue;

    public function __construct(public readonly Voucher $voucher) {}

    public function envelope(): Envelope
    {
        return $this->asVenue(
            $this->voucher->venue,
            'Booked · '.$this->voucher->venue->name.' · '
                .$this->voucher->startsAt()->format('D j M, H:i'),
        );
    }

    /**
     * The booking travels in a header so that a bounce notice, which knows
     * only a message id, can be traced back to the booking it belongs to.
     */
    public function headers(): Headers
    {
        return new Headers(text: [
            'X-Reservation' => (string) $this->voucher->reservation->uuid,
        ]);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.voucher');
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $attachments = [
            Attachment::fromData(fn (): string => $this->voucher->calendar(), $this->voucher->calendarFilename())
                ->withMime('text/calendar'),
        ];

        $pdf = (new VoucherPdf)->render($this->voucher);

        if ($pdf !== null) {
            $attachments[] = Attachment::fromData(static fn (): string => $pdf, $this->voucher->pdfFilename())
                ->withMime('application/pdf');
        }

        return $attachments;
    }
}
