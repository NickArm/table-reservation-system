<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Guests\RecountGuest;
use App\Models\Guest;
use App\Models\Reservation;

/**
 * Keeps a guest's counters in step with their reservations (SPEC §28.2).
 *
 * An observer rather than a line in each action, because a booking's status
 * changes from a great many places — the booking flow, a cancellation, a
 * check-in, a no-show, the hourly reconciliation, a console command — and the
 * one thing worse than a stale counter is a counter that is right in four of
 * those places and wrong in the fifth.
 *
 * It recomputes rather than increments, so a missed event leaves the number
 * briefly stale instead of permanently wrong, and `guests:recount` can always
 * put it right.
 */
final readonly class ReservationObserver
{
    /** Columns that change what a guest's counters should say. */
    private const WATCHED = ['status', 'amount_cents', 'guest_id', 'starts_at'];

    public function __construct(private RecountGuest $recount) {}

    public function saved(Reservation $reservation): void
    {
        if (! $reservation->wasRecentlyCreated && ! $reservation->wasChanged(self::WATCHED)) {
            return;
        }

        // A booking moved from one guest to another leaves two to put right.
        $previous = $reservation->getOriginal('guest_id');

        if ($previous !== $reservation->guest_id) {
            $this->recountFor($previous);
        }

        $this->recountFor($reservation->guest_id);
    }

    public function deleted(Reservation $reservation): void
    {
        $this->recountFor($reservation->guest_id);
    }

    private function recountFor(mixed $guestId): void
    {
        if (! is_int($guestId) && ! ctype_digit((string) $guestId)) {
            return;
        }

        $guest = Guest::query()->find((int) $guestId);

        if ($guest !== null) {
            $this->recount->handle($guest);
        }
    }
}
