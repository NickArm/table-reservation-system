<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Actions\Reservations\CancelReservation;
use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\BookingType;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Venue;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * One booking, and the two things a venue ever does to it: check somebody in,
 * or cancel after a phone call (SPEC §8.3, §13.3).
 */
final class ReservationController extends Controller
{
    public function show(Request $request, Reservation $reservation, TenantContext $context): View
    {
        $tenant = $context->getOrFail();
        $venue = Venue::query()->first();

        $payment = Payment::query()
            ->where('reservation_id', $reservation->id)
            ->latest('id')
            ->first();

        return view('dashboard.reservations.show', [
            'tenant' => $tenant,
            'venue' => $venue,
            'timezone' => (string) ($venue?->timezone ?? $tenant->timezone),
            'reservation' => $reservation,
            'bookingType' => BookingType::query()->find($reservation->booking_type_id),
            'area' => Area::query()->find($reservation->area_id),
            'payment' => $payment,
            'canRefund' => $payment !== null
                && $payment->isPaid()
                && $payment->refundableCents() > 0
                && $this->isOwner($request, $tenant),
            'refundableCents' => $payment?->refundableCents() ?? 0,
        ]);
    }

    /**
     * One tap, no confirmation: the guest is standing in front of them.
     */
    public function checkIn(Reservation $reservation): RedirectResponse
    {
        // Idempotent on purpose 🔒 — an offline scanner replaying its queue
        // must not care how many times this runs (SPEC §32.3).
        if (! $reservation->isCheckedIn()) {
            $reservation->forceFill([
                'checked_in_at' => now(),
                'status' => \App\Enums\ReservationStatus::CheckedIn,
            ])->save();
        }

        return back()->with('status', "{$reservation->customer_name} is in.");
    }

    public function cancel(
        Request $request,
        Reservation $reservation,
        CancelReservation $cancel,
        TenantContext $context,
    ): RedirectResponse {
        $payment = Payment::query()
            ->where('reservation_id', $reservation->id)
            ->latest('id')
            ->first();

        $refundable = $payment?->refundableCents() ?? 0;

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:160'],
            'refund' => ['required', 'in:none,full,partial'],
            'amount' => ['required_if:refund,partial', 'nullable', 'numeric', 'min:0.5', 'max:'.max(0.5, $refundable / 100)],
        ]);

        // Staff can cancel; only an owner can move money (SPEC §2.3).
        $wantsRefund = $validated['refund'] !== 'none';

        if ($wantsRefund && ! $this->isOwner($request, $context->getOrFail())) {
            return back()->withErrors([
                'refund' => 'Only the owner can refund. Cancel without a refund, or ask them.',
            ]);
        }

        $amount = match ($validated['refund']) {
            'full' => $refundable,
            'partial' => Money::toCents((string) $validated['amount']),
            default => 0,
        };

        $result = $cancel->handle(
            reservation: $reservation,
            reason: (string) $validated['reason'],
            refundCents: $amount,
            actorId: $request->user()?->id,
        );

        if ($result !== null && ! $result->accepted) {
            return back()->withErrors([
                'refund' => $result->failureReason ?? 'The refund was refused by the gateway.',
            ]);
        }

        return redirect()
            ->route('dashboard.today')
            ->with('status', $amount > 0
                ? "Cancelled. {$this->refundWording($amount)} — the guest will see it in a few working days."
                : 'Cancelled. No refund was made.');
    }

    private function refundWording(int $amountCents): string
    {
        return Money::format($amountCents).' is on its way back';
    }

    /**
     * Staff can cancel a booking; only an owner can move money (SPEC §2.3).
     */
    private function isOwner(Request $request, \App\Models\Tenant $tenant): bool
    {
        return $request->user()?->roleIn($tenant) === TenantRole::Owner;
    }
}
