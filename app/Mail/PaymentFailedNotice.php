<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Config;

/**
 * The subscription payment did not go through (SPEC §4.3).
 *
 * Three of these, on days one, three and seven, and the difference between
 * them is only how close the deadline is. The content is the same two facts
 * every time: the payment failed, and the booking page keeps working until a
 * named date. Both have to be in every one, because the owner who opens the
 * third has usually not read the first two.
 *
 * It is written to be easy to act on and hard to panic about. The venue has
 * not done anything wrong — cards expire, banks reissue numbers — and the
 * thing that actually gets it fixed is one link and one date, not urgency.
 *
 * Not queued — see the queue note in CLAUDE.md.
 */
final class PaymentFailedNotice extends Mailable
{
    public function __construct(
        public readonly Tenant $tenant,

        /** Days since the payment failed: 1, 3 or 7. */
        public readonly int $day,

        /** When the booking page stops taking new reservations. */
        public readonly ?CarbonImmutable $locksOn,

        /** What the provider said, when it said anything useful. */
        public readonly ?string $reason = null,
    ) {}

    public function envelope(): Envelope
    {
        /*
         * The subject says what is wrong, not how many days it has been. "A
         * third reminder" is our bookkeeping; the owner's question is whether
         * the booking page is about to stop.
         */
        $subject = $this->stopped()
            ? $this->tenant->name.' has stopped taking bookings'
            : 'Your payment did not go through';

        return new Envelope(
            from: new Address(
                (string) Config::string('mail.from.address'),
                (string) Config::string('app.name'),
            ),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        // `stopped` is passed explicitly: a Mailable hands the view its public
        // properties, not its methods.
        return new Content(
            view: 'mail.payment-failed',
            with: ['stopped' => $this->stopped()],
        );
    }

    /**
     * Has the booking page already stopped?
     *
     * By day seven it usually has, but not always — a card that failed early
     * in a paid month leaves the grace days starting at the end of it — so
     * this is read from the date rather than assumed from the day.
     */
    public function stopped(): bool
    {
        return $this->locksOn !== null && $this->locksOn->isPast();
    }
}
