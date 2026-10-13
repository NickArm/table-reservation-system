<?php

declare(strict_types=1);

namespace App\Actions\Closures;

use App\Enums\StructureScope;
use App\Models\Area;
use App\Models\BookingClosure;
use App\Models\BookingType;
use App\Models\Resource;

/**
 * Undoes a closure (SPEC §24.5).
 *
 * Separate from closing because the two are not symmetrical: closing is a
 * reflex in the middle of service, reopening is a considered act afterwards.
 */
final readonly class ReopenBookings
{
    public function __construct(private CloseBookings $closures) {}

    public function handle(BookingClosure $closure): void
    {
        $closure->delete();
    }

    /**
     * Cancels the "stopped for today" tap, and only that — a seasonal closure
     * covering the same date stays exactly where it is.
     */
    public function today(BookingType|Area|Resource $subject): int
    {
        $scope = match (true) {
            $subject instanceof BookingType => StructureScope::BookingType,
            $subject instanceof Area => StructureScope::Area,
            $subject instanceof Resource => StructureScope::Resource,
        };

        return BookingClosure::query()
            ->where('scope', $scope->value)
            ->where('scope_id', $subject->id)
            ->where('is_quick_stop', true)
            ->whereDate('date_from', '<=', $this->closures->todayAtVenue())
            ->whereDate('date_to', '>=', $this->closures->todayAtVenue())
            ->delete();
    }
}
