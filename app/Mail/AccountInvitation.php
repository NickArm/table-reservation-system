<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Your account is ready — choose a password."
 *
 * Sent when the platform opens an account on somebody's behalf, which is the
 * normal way a venue starts: a telephone call, a plan agreed, and an address
 * to write to. Not queued, for the same reason as the rest of the mail in
 * this application — the queue has no tenant bound and a Mailable holding
 * models would be re-queried without one.
 */
final class AccountInvitation extends Mailable
{
    public function __construct(
        public readonly User $user,
        public readonly Tenant $tenant,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your '.config('app.name').' account is ready',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.account-invitation',
            with: [
                'days' => (int) config('tenancy.invitation_days'),
            ],
        );
    }
}
