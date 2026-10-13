<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Reservation;
use Illuminate\Mail\Events\MessageSent;
use Throwable;

/**
 * Remembers which message carried which booking (SPEC §10.3).
 *
 * A bounce notice from the mail provider arrives knowing a message id and
 * nothing else — no tenant, no booking, no guest. Writing the id down as the
 * message leaves is what lets that notice find its way home, and what lets the
 * venue be told to pick up the phone.
 *
 * Runs inline and never throws: it is bookkeeping, and bookkeeping must not be
 * able to fail a confirmation that has already been sent.
 */
final class RecordVoucherDelivery
{
    public function handle(MessageSent $event): void
    {
        try {
            $header = $event->message->getHeaders()->get('X-Reservation');

            if ($header === null) {
                return;
            }

            $uuid = trim($header->getBodyAsString());

            if ($uuid === '') {
                return;
            }

            $messageId = $event->sent->getMessageId();

            if (! is_string($messageId) || $messageId === '') {
                return;
            }

            Reservation::query()
                ->acrossTenants()
                ->where('uuid', $uuid)
                ->update(['voucher_message_id' => trim($messageId, '<>')]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
