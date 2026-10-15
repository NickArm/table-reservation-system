<?php

declare(strict_types=1);

namespace App\Actions\Guests;

use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;

/**
 * Works a guest's counters out from their reservations (SPEC §28.2).
 *
 * Recomputed rather than incremented, and that is the whole point. A counter
 * that is only ever added to drifts — a status changed by a console command, a
 * row deleted, an observer that did not fire — and nobody notices until an
 * owner argues with the number on their own screen. Here the reservations are
 * the truth and the counters are a cache of them, so the worst a missed hook
 * can do is leave the cache briefly stale.
 *
 * It is one aggregate query. Cheap enough to run on every write.
 */
final readonly class RecountGuest
{
    public function handle(Guest $guest): Guest
    {
        $totals = Reservation::query()
            ->where('guest_id', $guest->id)
            ->selectRaw('count(*) as bookings')
            ->selectRaw('min(starts_at) as first_seen')
            ->selectRaw('max(starts_at) as last_seen')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as completed', [
                ReservationStatus::Completed->value,
            ])
            ->selectRaw('sum(case when status in (?, ?) then 1 else 0 end) as cancelled', [
                ReservationStatus::CancelledByVenue->value,
                ReservationStatus::Expired->value,
            ])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as no_shows', [
                ReservationStatus::NoShow->value,
            ])
            // Money the guest actually owed us, so a cancelled or expired
            // booking does not inflate what they are worth to the venue.
            ->selectRaw('sum(case when status in (?, ?, ?, ?) then amount_cents else 0 end) as spent', [
                ReservationStatus::Confirmed->value,
                ReservationStatus::CheckedIn->value,
                ReservationStatus::Completed->value,
                ReservationStatus::NoShow->value,
            ])
            // toBase keeps the tenant scope on the query but hands back a plain
            // row, so strict mode has no partially-filled model to object to.
            ->toBase()
            ->first();

        $guest->forceFill([
            'bookings_count' => (int) ($totals->bookings ?? 0),
            'completed_count' => (int) ($totals->completed ?? 0),
            'cancelled_count' => (int) ($totals->cancelled ?? 0),
            'no_show_count' => (int) ($totals->no_shows ?? 0),
            'total_spent_cents' => (int) ($totals->spent ?? 0),
            'first_seen_at' => $totals->first_seen ?? $guest->first_seen_at,
            'last_seen_at' => $totals->last_seen ?? $guest->last_seen_at,
        ])->save();

        return $guest;
    }

    /**
     * Every guest of every venue, for `guests:recount`.
     *
     * Chunked, because this is the one job in the system whose size grows with
     * the whole platform rather than with one venue's day.
     */
    public function all(): int
    {
        $counted = 0;

        Guest::query()->orderBy('id')->chunkById(200, function ($guests) use (&$counted): void {
            DB::transaction(function () use ($guests, &$counted): void {
                foreach ($guests as $guest) {
                    $this->handle($guest);
                    $counted++;
                }
            });
        });

        return $counted;
    }
}
