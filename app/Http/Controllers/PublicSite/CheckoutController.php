<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicSite;

use App\Actions\Payments\SettlePayment;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Models\Venue;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

/**
 * Where the gateway sends the guest back to (SPEC §24.1).
 *
 * The redirect is not proof of anything — a guest can type this URL. So the
 * page asks the gateway itself before showing a voucher, which also means the
 * flow works in development, where webhooks cannot reach a local machine.
 */
final class CheckoutController extends Controller
{
    public function paid(
        Tenant $tenant,
        Reservation $reservation,
        SettlePayment $settle,
    ): View|RedirectResponse {
        $payment = Payment::query()
            ->where('reservation_id', $reservation->id)
            ->latest('id')
            ->first();

        if ($payment !== null && ! $payment->isPaid()) {
            try {
                $settle->handle($payment);
            } catch (Throwable) {
                // The webhook or the hourly reconciliation will catch it. The
                // guest should not see a stack trace for our plumbing.
            }
        }

        $reservation->refresh();

        if ($reservation->status === ReservationStatus::Confirmed) {
            return redirect()->route('public.confirmed', [$tenant, $reservation]);
        }

        return view('public.pending', [
            'tenant' => $tenant,
            'venue' => Venue::query()->firstOrFail(),
            'reservation' => $reservation,
        ]);
    }

    /**
     * They changed their mind at the gateway. The spot goes back on the
     * board immediately rather than waiting for the hourly sweep.
     */
    public function cancelled(Tenant $tenant, Reservation $reservation): RedirectResponse
    {
        if ($reservation->status === ReservationStatus::PendingPayment) {
            $reservation->forceFill([
                'status' => ReservationStatus::Expired,
                'cancellation_reason' => 'The guest did not finish paying.',
            ])->save();
        }

        return redirect()
            ->route('public.search', [$tenant, 'people' => $reservation->party_size])
            ->withErrors(['spot' => 'Nothing was charged. Your spot is free again if you want another go.']);
    }
}
