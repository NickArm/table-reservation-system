<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Enums\ReservationStatus;
use App\Mail\BookingReminder;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Models\Venue;
use App\Tenancy\TenantContext;
use App\Vouchers\Voucher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The nudge before a table is due (SPEC §10.1).
 *
 * How far ahead is the venue's choice, because the right answer differs by
 * trade: a sunbed booked for tomorrow morning wants a reminder the evening
 * before, a dinner table wants one the same afternoon.
 *
 * Two guards keep this from becoming a nuisance. Nobody is reminded about a
 * booking they made in the last couple of hours — they have not forgotten yet.
 * And `reminder_sent_at` is written before the next booking is considered, so
 * a crash halfway through does not mean a second round of emails.
 */
final readonly class SendDueReminders
{
    /** A booking made this recently needs no reminder. */
    private const SETTLE_HOURS = 2;

    public function __construct(private TenantContext $context) {}

    /**
     * @return array{sent: int, failed: int}
     */
    public function handle(): array
    {
        $sent = 0;
        $failed = 0;

        $venues = $this->context->withoutScoping(
            fn () => Venue::query()->whereNotNull('reminder_hours_before')->get()
        );

        foreach ($venues as $venue) {
            $tenant = Tenant::query()->find($venue->tenant_id);

            if ($tenant === null) {
                continue;
            }

            $this->context->asTenant($tenant, function () use ($venue, &$sent, &$failed): void {
                foreach ($this->due($venue) as $reservation) {
                    try {
                        $this->remind($reservation);
                        $sent++;
                    } catch (Throwable $exception) {
                        $failed++;

                        Log::warning('A booking reminder could not be sent.', [
                            'reservation' => $reservation->uuid,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            });
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * @return Collection<int, Reservation>
     */
    private function due(Venue $venue): Collection
    {
        $hours = (int) $venue->reminder_hours_before;

        return Reservation::query()
            ->where('venue_id', $venue->id)
            ->where('status', ReservationStatus::Confirmed)
            ->whereNull('reminder_sent_at')
            ->whereNotNull('customer_email')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addHours($hours))
            ->where('created_at', '<=', now()->subHours(self::SETTLE_HOURS))
            ->orderBy('starts_at')
            ->limit(200)
            ->get();
    }

    private function remind(Reservation $reservation): void
    {
        // Claimed first. A mail server that accepts the message and then times
        // out would otherwise hand the guest a second copy on the next run.
        $reservation->forceFill(['reminder_sent_at' => now()])->save();

        Mail::to((string) $reservation->customer_email, (string) $reservation->customer_name)
            ->send(new BookingReminder(Voucher::for($reservation)));
    }
}
