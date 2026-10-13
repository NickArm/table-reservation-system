<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\VenueBookingAlert;
use App\Models\Reservation;
use App\Models\Venue;
use App\Tenancy\Concerns\RunsAsTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Mail;

/**
 * The venue's copy of a new booking, as its own job so that quiet hours can
 * simply delay it (SPEC §10.2).
 *
 * Carries identifiers rather than models. A delayed job may sit on the queue
 * for hours, and a serialized tenant-scoped model cannot be restored without
 * a tenant bound — see RunsAsTenant.
 */
#[Tries(3)]
#[Backoff(60, 600)]
final class EmailVenueAlert implements ShouldQueue
{
    use Queueable;
    use RunsAsTenant;

    /**
     * @param  list<string>  $recipients
     */
    public function __construct(
        private readonly int $tenantId,
        private readonly int $reservationId,
        private readonly array $recipients,
    ) {}

    public function handle(): void
    {
        $this->asTenant($this->tenantId, function (): void {
            $reservation = Reservation::query()->find($this->reservationId);

            if ($reservation === null) {
                return;
            }

            $venue = Venue::query()->find($reservation->venue_id);

            if ($venue === null) {
                return;
            }

            $mailable = new VenueBookingAlert($venue, $reservation, (string) $venue->timezone);

            foreach ($this->recipients as $recipient) {
                Mail::to($recipient)->send($mailable);
            }
        });
    }
}
