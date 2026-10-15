<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ReservationWasConfirmed;
use App\Jobs\EmailVenueAlert;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\BookingArrived;
use App\Tenancy\Concerns\RunsAsTenant;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Tells the venue a booking has arrived (SPEC §10.2).
 *
 * Two channels, for two different moments. The bell is for an owner already
 * looking at the dashboard. The email is for the other twenty-three hours, and
 * goes to every address they have listed — a shared inbox, the manager, the
 * kiosk on the beach.
 *
 * Quiet hours delay the email rather than dropping it. A booking made at
 * half past three in the morning is still a booking; waking somebody over it
 * is what loses us the account. The bell is written immediately either way,
 * because nothing about it makes a noise.
 */
#[Tries(3)]
final class AlertTheVenue implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;
    use RunsAsTenant;

    public function handle(ReservationWasConfirmed $event): void
    {
        $this->asTenant($event->tenantId, function () use ($event): void {
            $reservation = Reservation::query()->find($event->reservationId);

            if ($reservation === null) {
                return;
            }

            // A booking the venue typed in itself needs no announcement: the
            // person who entered it already knows, and an email about it is
            // the kind of noise that gets a sender filtered.
            if ($reservation->created_by !== null) {
                return;
            }

            $venue = Venue::query()->find($reservation->venue_id);

            if ($venue === null) {
                return;
            }

            $timezone = (string) $venue->timezone;

            $this->ringTheBell($venue, $reservation, $timezone);

            if (! $venue->notify_on_booking) {
                return;
            }

            $recipients = $venue->notificationRecipients();

            if ($recipients === []) {
                return;
            }

            $deliverAt = $venue->audibleAfter(now()->toImmutable());

            $email = EmailVenueAlert::dispatch(
                $event->tenantId,
                (int) $reservation->getKey(),
                $recipients,
            );

            if ($deliverAt->greaterThan(now())) {
                $email->delay($deliverAt);
            }
        });
    }

    private function ringTheBell(Venue $venue, Reservation $reservation, string $timezone): void
    {
        $staff = User::query()
            ->whereHas('tenants', fn (Builder $query) => $query->whereKey($venue->tenant_id))
            ->get();

        foreach ($staff as $member) {
            $member->notify(new BookingArrived($reservation, $timezone));
        }
    }
}
