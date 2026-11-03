<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "These accounts pay you directly, and they are about to run out."
 *
 * To the platform, never to the customer. Nothing will charge these accounts
 * because a person invoices them, so this is the only thing between a
 * paid-up venue and a booking page that stops on a Saturday.
 *
 * @phpstan-type DueRow array{subscription: \App\Models\Subscription, tenant: \App\Models\Tenant}
 */
final class ManualRenewalsDue extends Mailable
{
    /**
     * @param  list<DueRow>  $due
     */
    public function __construct(public readonly array $due) {}

    public function envelope(): Envelope
    {
        $count = count($this->due);

        return new Envelope(
            subject: $count === 1
                ? '1 account to invoice: '.$this->due[0]['tenant']->name
                : $count.' accounts to invoice',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.manual-renewals-due');
    }
}
