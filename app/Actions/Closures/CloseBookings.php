<?php

declare(strict_types=1);

namespace App\Actions\Closures;

use App\Enums\StructureScope;
use App\Models\Area;
use App\Models\BookingClosure;
use App\Models\BookingType;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\Venue;
use Carbon\CarbonImmutable;

/**
 * Stops new bookings for a period (SPEC §6.2, §24.5).
 *
 * Always allowed, always instant, never blocked by what is already booked.
 * An owner watching the wind get up has to close the beach in one tap, not
 * answer a dialog with four options.
 *
 * Closing looks forward only. Reservations already paid for stay valid, and
 * the owner is told how many there are so they can ring those guests — the
 * system never cancels a paid booking on its own.
 */
final readonly class CloseBookings
{
    public function handle(
        BookingType|Area|Resource $subject,
        string $dateFrom,
        ?string $dateTo = null,
        ?string $timeFrom = null,
        ?string $timeTo = null,
        ?string $reason = null,
        bool $isQuickStop = false,
    ): BookingClosure {
        $closure = new BookingClosure;

        $closure->fill([
            'scope' => $this->scopeOf($subject),
            'scope_id' => $subject->id,
            'date_from' => $dateFrom,
            'date_to' => $dateTo ?? $dateFrom,
            'time_from' => $timeFrom,
            'time_to' => $timeTo,
            'reason' => $reason,
            'is_quick_stop' => $isQuickStop,
        ]);

        $closure->created_by = auth()->id();
        $closure->save();

        return $closure;
    }

    /**
     * "Stop bookings for today" — the one-tap button on the Today screen.
     *
     * Today means the venue's today, which at half past midnight is still
     * yesterday's shift as far as everyone working it is concerned.
     */
    public function today(BookingType|Area|Resource $subject, ?string $reason = null): BookingClosure
    {
        return $this->handle(
            subject: $subject,
            dateFrom: $this->todayAtVenue(),
            reason: $reason ?? 'Stopped for today',
            isQuickStop: true,
        );
    }

    /**
     * How many paid bookings sit inside a closure, for the notice the owner
     * sees afterwards. Information, never a blocker.
     */
    public function affectedReservations(BookingClosure $closure): int
    {
        $query = Reservation::query()
            ->blocking()
            ->whereDate('starts_at', '>=', $closure->date_from)
            ->whereDate('starts_at', '<=', $closure->date_to);

        return match ($closure->scope) {
            StructureScope::BookingType => $query->where('booking_type_id', $closure->scope_id)->count(),
            StructureScope::Area => $query->where('area_id', $closure->scope_id)->count(),
            StructureScope::Resource => $query->where('resource_id', $closure->scope_id)->count(),
        };
    }

    public function todayAtVenue(): string
    {
        $venue = Venue::query()->firstOrFail();

        return CarbonImmutable::now((string) $venue->timezone)->toDateString();
    }

    private function scopeOf(BookingType|Area|Resource $subject): StructureScope
    {
        return match (true) {
            $subject instanceof BookingType => StructureScope::BookingType,
            $subject instanceof Area => StructureScope::Area,
            $subject instanceof Resource => StructureScope::Resource,
        };
    }
}
