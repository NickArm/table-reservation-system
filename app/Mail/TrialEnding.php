<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Config;

/**
 * The trial is nearly over (SPEC §4.2).
 *
 * The one email in this system whose job is to sell something, so it does it
 * the only way that works on a taverna owner in August: by showing what the
 * fortnight actually produced. "You have taken 23 bookings worth €460" is an
 * argument. "Your trial expires in 3 days" is a nuisance.
 *
 * Not queued — see the queue note in CLAUDE.md. The job that sends it is what
 * sits on the queue.
 */
final class TrialEnding extends Mailable
{
    /**
     * @param  array{bookings: int, revenue_cents: int, guests: int}  $sofar
     */
    public function __construct(
        public readonly Tenant $tenant,
        public readonly int $daysLeft,
        public readonly array $sofar,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->daysLeft <= 1
            ? 'Your trial ends tomorrow'
            : 'Your trial ends in '.$this->daysLeft.' days';

        // With something to show for it, the number leads: it is the reason
        // to open the email at all.
        if ($this->sofar['bookings'] > 0) {
            $subject = $this->sofar['bookings'].' bookings so far — '.lcfirst($subject);
        }

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
        return new Content(view: 'mail.trial-ending');
    }
}
