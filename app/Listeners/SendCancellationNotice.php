<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ReservationWasCancelled;
use App\Mail\BookingCancelled;
use App\Models\Reservation;
use App\Tenancy\Concerns\RunsAsTenant;
use App\Vouchers\Voucher;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * Tells the guest their booking is off, and what is coming back (SPEC §8.3).
 *
 * This one is not optional and not conditional on settings. A venue cancelling
 * a paid booking without telling anyone is the single fastest way to a
 * chargeback, and the guest is owed the message regardless.
 */
#[Tries(3)]
final class SendCancellationNotice implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;
    use RunsAsTenant;

    public function handle(ReservationWasCancelled $event): void
    {
        $this->asTenant($event->tenantId, function () use ($event): void {
            $reservation = Reservation::query()->find($event->reservationId);

            if ($reservation === null || blank($reservation->customer_email)) {
                return;
            }

            Mail::to((string) $reservation->customer_email, (string) $reservation->customer_name)
                ->send(new BookingCancelled(
                    Voucher::for($reservation),
                    $event->refundedCents,
                    $event->reason,
                ));
        });
    }
}
