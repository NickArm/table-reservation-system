<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Enums\ReservationStatus;
use App\Enums\TenantStatus;
use App\Mail\TrialEnding;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells an account its trial is nearly over, and what it got out of it
 * (SPEC §4.2).
 *
 * Trialling without a card means more signups and more people who never
 * convert, so this is the counter-measure — but there is a line between a
 * useful reminder and nagging, and the line is how much is sent. Two emails:
 * one a few days out, one the day before. Not five.
 *
 * Each one carries the account's own numbers. A fortnight of real bookings is
 * the only argument that works on somebody in the middle of a season, and it
 * is an honest one: if the figures are zero the email says so plainly and
 * offers help setting up, because an account with nothing to show is not
 * going to be persuaded by a deadline.
 */
final readonly class SendTrialReminders
{
    /**
     * Days remaining at which a reminder goes out.
     *
     * Three and one, so a fifteen-day trial is reminded on days twelve and
     * fourteen without either number being written down anywhere. Extending a
     * trial by hand then simply moves both.
     */
    private const AT_DAYS_LEFT = [3, 1];

    public function __construct(private TenantContext $context) {}

    /**
     * @return array{sent: int, failed: int}
     */
    public function handle(): array
    {
        $sent = 0;
        $failed = 0;

        $this->context->withoutScoping(function () use (&$sent, &$failed): void {
            Tenant::query()
                ->where('status', TenantStatus::Trialing->value)
                ->whereNotNull('trial_ends_at')
                ->chunkById(100, function ($tenants) use (&$sent, &$failed): void {
                    foreach ($tenants as $tenant) {
                        $days = $tenant->trialDaysLeft();

                        if (! in_array($days, self::AT_DAYS_LEFT, true)) {
                            continue;
                        }

                        // Already told at this mark. Checked against the mark
                        // rather than a date, so a trial extended by hand
                        // gets its reminders again rather than being skipped.
                        if ((int) $tenant->trial_reminder_sent_for === $days) {
                            continue;
                        }

                        try {
                            $this->remind($tenant, $days);
                            $sent++;
                        } catch (Throwable $exception) {
                            $failed++;

                            Log::warning('Could not send a trial reminder.', [
                                'tenant' => $tenant->slug,
                                'days_left' => $days,
                                'error' => $exception->getMessage(),
                            ]);
                        }
                    }
                });
        });

        return ['sent' => $sent, 'failed' => $failed];
    }

    private function remind(Tenant $tenant, int $days): void
    {
        $owners = User::query()
            ->whereHas('tenants', fn ($query) => $query->whereKey($tenant->getKey()))
            ->pluck('email')
            ->all();

        if ($owners === []) {
            return;
        }

        $sofar = $this->context->asTenant($tenant, fn (): array => $this->whatTheyGot());

        Mail::to($owners)->send(new TrialEnding($tenant, $days, $sofar));

        // Written after the send, so a failure means it is tried again
        // tomorrow rather than silently skipped.
        $tenant->forceFill(['trial_reminder_sent_for' => $days])->save();
    }

    /**
     * What the trial produced, in the three numbers an owner cares about.
     *
     * @return array{bookings: int, revenue_cents: int, guests: int}
     */
    private function whatTheyGot(): array
    {
        /** @var object{bookings: int|null, revenue: int|null}|null $totals */
        $totals = Reservation::query()
            ->whereIn('status', [
                ReservationStatus::Confirmed->value,
                ReservationStatus::CheckedIn->value,
                ReservationStatus::Completed->value,
            ])
            ->reorder()
            ->selectRaw('count(*) as bookings')
            ->selectRaw('coalesce(sum(amount_cents), 0) as revenue')
            ->toBase()
            ->first();

        return [
            'bookings' => (int) ($totals->bookings ?? 0),
            'revenue_cents' => (int) ($totals->revenue ?? 0),
            'guests' => (int) Reservation::query()
                ->whereNotNull('guest_id')
                ->distinct()
                ->count('guest_id'),
        ];
    }
}
