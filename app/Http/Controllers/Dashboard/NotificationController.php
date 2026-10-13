<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\UpdateNotificationSettingsRequest;
use App\Models\Venue;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Who hears about bookings, and when (SPEC §10.2).
 *
 * One screen, four questions, all with a working default — a venue that never
 * opens this page still gets told about its bookings, which is the only
 * behaviour that could be called correct.
 */
final class NotificationController extends Controller
{
    public function edit(TenantContext $context): View
    {
        $venue = Venue::query()->firstOrFail();

        return view('dashboard.notifications', [
            'tenant' => $context->getOrFail(),
            'venue' => $venue,
            'recipients' => $venue->notificationRecipients(),
        ]);
    }

    public function update(UpdateNotificationSettingsRequest $request): RedirectResponse
    {
        $venue = Venue::query()->firstOrFail();

        $venue->fill([
            'notify_on_booking' => $request->boolean('notify_on_booking'),
            'notification_emails' => $request->extraEmails(),
            'daily_summary_hour' => $request->summaryHour(),
            'quiet_hours_from' => $request->input('quiet_hours_from') ?: null,
            'quiet_hours_to' => $request->input('quiet_hours_to') ?: null,
            'reminder_hours_before' => $request->reminderHours(),
        ])->save();

        return back()->with('status', 'Saved.');
    }

    /**
     * Clears the bell. Deliberately all at once rather than one at a time: an
     * owner glancing at this is checking whether anything new happened, not
     * managing an inbox.
     */
    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()?->unreadNotifications->markAsRead();

        return back();
    }
}
