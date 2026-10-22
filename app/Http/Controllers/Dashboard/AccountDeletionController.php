<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Actions\Tenants\RequestAccountDeletion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\DeleteAccountRequest;
use App\Mail\AccountDeletionRequested;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Asking for an account to be deleted (SPEC §29).
 *
 * Only the owner. Staff run the floor and do not get to end the business
 * (§2.3), and this is the one action in the product that cannot be undone
 * from inside it.
 */
final class AccountDeletionController extends Controller
{
    public function store(
        DeleteAccountRequest $request,
        TenantContext $context,
        RequestAccountDeletion $deletion,
    ): RedirectResponse {
        $tenant = $context->getOrFail();

        /** @var User $actor */
        $actor = $request->user();

        try {
            $deletion->handle($tenant, $actor, $request->ip());
        } catch (Throwable $exception) {
            // The blocker, almost always: upcoming reservations that guests
            // have paid for (§29.1).
            return back()->with('error', $exception->getMessage());
        }

        /*
         * The email goes to everybody on the account, not only whoever
         * pressed the button. One of two partners deleting the business is a
         * thing the other should hear about within the thirty days, not
         * after.
         */
        $recipients = $tenant->users()->pluck('email')->all();

        if ($recipients !== []) {
            Mail::to($recipients)->send(new AccountDeletionRequested(
                $tenant,
                $tenant->purge_at,
            ));
        }

        /*
         * Signed out. Leaving them inside a read-only dashboard that no
         * longer takes bookings, with no obvious way to react, is worse than
         * the plain full stop — and the email they have just been sent is
         * what tells them how to undo it.
         */
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
