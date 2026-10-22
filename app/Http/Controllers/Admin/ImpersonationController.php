<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Platform\Impersonate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImpersonateRequest;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Throwable;

/**
 * Being the venue for a while (SPEC §2.1).
 *
 * Both halves are POSTs. Becoming somebody else is not something a link
 * should do: a GET ends up in browser history, gets prefetched, and can be
 * triggered by an image tag on a page the admin happens to be reading.
 */
final class ImpersonationController extends Controller
{
    public function store(
        ImpersonateRequest $request,
        Tenant $tenant,
        Impersonate $impersonation,
    ): RedirectResponse {
        try {
            $impersonation->start(
                $request->user(),
                $tenant,
                $request->string('reason')->toString(),
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('dashboard.today');
    }

    /**
     * Back to being ourselves.
     *
     * Outside the admin middleware on purpose: while impersonating, the
     * platform screens are closed — so the route that ends it cannot be
     * behind them, or there is no way out except clearing cookies.
     */
    public function destroy(Impersonate $impersonation): RedirectResponse
    {
        $admin = $impersonation->stop();

        if ($admin === null) {
            /*
             * Nothing was being impersonated, so there is nothing to undo.
             * Home rather than the login page or the dashboard: whoever
             * posted here may be a venue owner who found the address, or an
             * admin with no account of their own, and neither of those two
             * destinations works for both.
             */
            return redirect()->route('home');
        }

        return redirect()->route('admin.overview')
            ->with('status', 'You are yourself again.');
    }
}
