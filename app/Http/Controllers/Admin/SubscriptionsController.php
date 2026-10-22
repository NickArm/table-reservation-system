<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Platform\PlatformFigures;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Every subscription, as money rather than as accounts (SPEC §13.2).
 *
 * The Accounts screen answers "who is this venue"; this one answers "where is
 * the money". Same rows, different question — which is why the default order
 * is not alphabetical but by what is wrong: a bounced payment first, then a
 * period about to end, then everything that is simply fine.
 */
final class SubscriptionsController extends Controller
{
    public function __invoke(Request $request, PlatformFigures $figures): View
    {
        $status = TenantStatus::tryFrom((string) $request->string('status'));

        $subscriptions = Subscription::query()
            ->acrossTenants()
            ->with(['plan', 'tenant'])
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))

            // A failed payment is the only thing on this page that needs
            // doing today, so it goes to the top whatever else is true.
            ->orderByRaw('case when payment_failed_at is null then 1 else 0 end')
            ->orderBy('current_period_end')
            ->paginate(50)
            ->withQueryString();

        return view('admin.subscriptions', [
            'subscriptions' => $subscriptions,
            'status' => $status,
            'figures' => $figures,
        ]);
    }
}
