<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ReservationWasConfirmed;
use App\Mail\BookingVoucher;
use App\Models\Reservation;
use App\Tenancy\Concerns\RunsAsTenant;
use App\Vouchers\Voucher;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the guest their booking confirmation (SPEC §11).
 *
 * On the queue, for one reason that matters more than tidiness: a tourist
 * standing in the sun with one bar of signal must see "booked" the instant the
 * booking exists. Rendering a PDF and waiting on a mail server is three
 * seconds this page will not spend.
 *
 * ShouldQueueAfterCommit keeps the job from starting before the row it is about
 * has been committed — otherwise, on a fast queue worker, the booking cannot be
 * found.
 */
#[Tries(3)]
#[Backoff(30, 300)]
final class SendBookingVoucher implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;
    use RunsAsTenant;

    public function handle(ReservationWasConfirmed $event): void
    {
        $this->asTenant($event->tenantId, function () use ($event): void {
            $reservation = Reservation::query()->find($event->reservationId);

            if ($reservation === null || blank($reservation->customer_email)) {
                return;
            }

            // Already sent. Reconciliation and the return page can both settle
            // the same payment, and neither should produce a second voucher.
            if ($reservation->voucher_sent_at !== null) {
                return;
            }

            Mail::to((string) $reservation->customer_email, (string) $reservation->customer_name)
                ->send(new BookingVoucher(Voucher::for($reservation)));

            $reservation->forceFill([
                'voucher_sent_at' => now(),
                'voucher_delivery' => 'sent',
            ])->save();
        });
    }
}
