<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Models\Tenant;
use App\Platform\PlatformFigures;
use App\Platform\PlatformHealth;
use Illuminate\Support\Str;
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
    public function __invoke(PlatformFigures $figures, PlatformHealth $health): View
    {
        return view('admin.overview', [
            'figures' => $figures,
            // The quiet parts, on the page somebody actually opens rather
            // than on one they have to remember to visit.
            'health' => $health,
            'accountReason' => $this->reason(...),

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

    /**
     * Why this account is on the list, said in a line.
     *
     * A row that only shows a status makes the reader work out what to do
     * about it; the whole value of this list is that somebody can read it
     * and pick up the telephone without opening anything.
     *
     * @return array{kind: string, body: string}
     */
    private function reason(Tenant $account): array
    {
        if ($account->isOnTrial()) {
            $days = $account->trialDaysLeft();

            return [
                'kind' => $days <= 2 ? 'late' : 'table',
                'body' => $days === 0
                    ? 'Trial ends today'
                    : 'Trial ends in '.$days.' '.Str::plural('day', $days),
            ];
        }

        $failedAt = $account->subscription?->payment_failed_at;

        if ($failedAt !== null) {
            return [
                'kind' => 'late',
                'body' => 'Payment failed '.$failedAt->diffForHumans(),
            ];
        }

        return [
            'kind' => 'plain',
            'body' => $account->status->label(),
        ];
    }
}
