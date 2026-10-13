<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Enums\ReservationStatus;
use App\Mail\VenueDailySummary;
use App\Models\BookingType;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Models\Venue;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The morning list, once a day, in each venue's own time (SPEC §10.2).
 *
 * Runs every hour and sends to the venues whose chosen hour it currently is
 * locally. That is what makes one command serve a venue in Corfu and a venue
 * in the Canaries without either of them thinking about timezones — and it
 * keeps working through the clock change, because the comparison is always
 * against local wall time rather than an offset worked out once.
 */
final readonly class SendDailySummaries
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return array{sent: int, failed: int}
     */
    public function handle(?int $forceHour = null): array
    {
        $sent = 0;
        $failed = 0;

        $venues = $this->context->withoutScoping(
            fn () => Venue::query()->whereNotNull('daily_summary_hour')->get()
        );

        foreach ($venues as $venue) {
            $hour = $forceHour ?? $venue->now()->hour;

            if ((int) $venue->daily_summary_hour !== $hour) {
                continue;
            }

            $tenant = Tenant::query()->find($venue->tenant_id);

            if ($tenant === null) {
                continue;
            }

            try {
                $this->context->asTenant($tenant, fn () => $this->summarise($venue));
                $sent++;
            } catch (Throwable $exception) {
                $failed++;

                Log::warning('A daily summary could not be sent.', [
                    'venue' => $venue->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    private function summarise(Venue $venue): void
    {
        $recipients = $venue->notificationRecipients();

        if ($recipients === []) {
            return;
        }

        $today = $venue->now()->startOfDay();

        $reservations = Reservation::query()
            ->where('venue_id', $venue->id)
            ->whereIn('status', [
                ReservationStatus::Confirmed,
                ReservationStatus::CheckedIn,
            ])
            ->where('starts_at', '>=', $today->utc())
            ->where('starts_at', '<', $today->addDay()->utc())
            ->orderBy('starts_at')
            ->get();

        $names = BookingType::query()
            ->whereIn('id', $reservations->pluck('booking_type_id')->unique()->all())
            ->pluck('name', 'id');

        /** @var Collection<string, Collection<int, Reservation>> $groups */
        $groups = $reservations->groupBy(
            fn (Reservation $reservation): string => (string) ($names[$reservation->booking_type_id] ?? 'Bookings')
        );

        $mailable = new VenueDailySummary(
            venue: $venue,
            date: $today,
            groups: $groups,
            total: $reservations->count(),
            covers: (int) $reservations->sum('party_size'),
        );

        foreach ($recipients as $recipient) {
            Mail::to($recipient)->send($mailable);
        }
    }
}
