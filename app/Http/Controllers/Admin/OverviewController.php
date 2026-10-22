<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Models\Tenant;
use App\Platform\PlatformFigures;
use Illuminate\View\View;

/**
 * The platform at a glance (SPEC §13.2).
 *
 * Built around the two questions that actually get asked when this page is
 * opened: is the money where it should be, and is there anybody I need to
 * ring today. So the figures sit at the top and the rest of the page is a
 * list of accounts that need something — a trial about to end, a payment that
 * bounced — rather than a table of everything.
 */
final class OverviewController extends Controller
{
    public function __invoke(PlatformFigures $figures): View
    {
        return view('admin.overview', [
            'figures' => $figures,

            // Worth a call this week: the trial is nearly up, or their card
            // failed. Both are reversible with one conversation, which is
            // what makes them worth putting on the front page.
            'needAttention' => Tenant::query()
                ->with('subscription.plan')
                ->where(function ($query): void {
                    $query
                        ->where(function ($trials): void {
                            $trials
                                ->where('status', TenantStatus::Trialing->value)
                                ->whereNotNull('trial_ends_at')
                                ->where('trial_ends_at', '<=', now()->addWeek());
                        })
                        ->orWhereIn('status', [
                            TenantStatus::PastDue->value,
                            TenantStatus::Grace->value,
                        ]);
                })
                ->orderBy('trial_ends_at')
                ->limit(20)
                ->get(),

            'recent' => AuditEntry::query()->newestFirst()->limit(10)->get(),
        ]);
    }
}
