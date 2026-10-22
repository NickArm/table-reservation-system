<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Config;

/**
 * Your account will be deleted on this date (SPEC §29.3).
 *
 * The one email in the system whose job is to be **undoable**. Thirty days
 * exist precisely so that a regretted click can be taken back, and the only
 * way back is a reply to this message — so the date and the instruction are
 * the two things it has to carry, and it carries nothing else.
 *
 * Sent to everybody on the account rather than only to whoever pressed the
 * button: one of two partners deleting the business is a thing the other
 * should hear about inside the thirty days, not after.
 *
 * Not queued — see the queue note in CLAUDE.md.
 */
final class AccountDeletionRequested extends Mailable
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly ?CarbonInterface $purgeAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                (string) Config::string('mail.from.address'),
                (string) Config::string('app.name'),
            ),
            subject: $this->tenant->name.' will be deleted on '
                .($this->purgeAt?->format('j F Y') ?? 'the agreed date'),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.account-deletion');
    }
}
