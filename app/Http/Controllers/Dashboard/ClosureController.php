<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Actions\Closures\CloseBookings;
use App\Actions\Closures\ReopenBookings;
use App\Enums\StructureScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Closures\StoreClosureRequest;
use App\Models\Area;
use App\Models\BookingClosure;
use App\Models\BookingType;
use App\Models\Resource;
use Illuminate\Http\RedirectResponse;

final class ClosureController extends Controller
{
    public function store(StoreClosureRequest $request, CloseBookings $close): RedirectResponse
    {
        $subject = $this->subject($request->scope(), $request->scopeId());

        $closure = $close->handle(
            subject: $subject,
            dateFrom: (string) $request->string('date_from'),
            dateTo: $request->filled('date_to') ? (string) $request->string('date_to') : null,
            timeFrom: $request->filled('time_from') ? (string) $request->string('time_from') : null,
            timeTo: $request->filled('time_to') ? (string) $request->string('time_to') : null,
            reason: $request->input('reason'),
        );

        $affected = $close->affectedReservations($closure);

        // Information, never a block: the owner decides who to ring and what
        // to refund, and the system cancels nothing on its own (SPEC §24.5).
        $standing = $affected === 1
            ? '1 existing reservation stays valid'
            : "{$affected} existing reservations stay valid";

        return back()->with('status', $affected === 0
            ? 'Closed. No new bookings for those dates.'
            : "Closed. {$standing} — call those guests if you need to.");
    }

    public function destroy(BookingClosure $closure, ReopenBookings $reopen): RedirectResponse
    {
        $reopen->handle($closure);

        return back()->with('status', 'Reopened.');
    }

    /**
     * The one-tap button on the Today screen (SPEC §13.3).
     */
    public function stopToday(BookingType $bookingType, CloseBookings $close): RedirectResponse
    {
        $closure = $close->today($bookingType);
        $affected = $close->affectedReservations($closure);

        return back()->with('status', $affected === 0
            ? "No more bookings today for {$bookingType->name}."
            : "No more bookings today for {$bookingType->name}. {$affected} already booked —"
                .' they are still expected.');
    }

    public function resumeToday(BookingType $bookingType, ReopenBookings $reopen): RedirectResponse
    {
        $reopen->today($bookingType);

        return back()->with('status', "{$bookingType->name} is taking bookings again.");
    }

    private function subject(StructureScope $scope, int $id): BookingType|Area|Resource
    {
        return match ($scope) {
            StructureScope::BookingType => BookingType::query()->findOrFail($id),
            StructureScope::Area => Area::query()->findOrFail($id),
            StructureScope::Resource => Resource::query()->findOrFail($id),
        };
    }
}
