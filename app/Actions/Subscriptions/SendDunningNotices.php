<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Mail\PaymentFailedNotice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Chases a payment that did not go through (SPEC §4.3).
 *
 * Three emails, on days one, three and seven after the failure, and then
 * nothing. A fourth would be a nuisance rather than a reminder, and an
 * account that has ignored three is not going to be moved by a fifth — at
 * that point the booking page has stopped, which is a louder message than
 * anything we could write.
 *
 * Two deliberate decisions about the timing.
 *
 * **The days count from the failure, not from the lock.** The provider is
 * still retrying the card during that week; the point of writing on day one
 * is that a bank reissue is usually fixed in a minute by somebody who knows
 * about it, and nobody knows about it until we say so.
 *
 * **The lock date is asked for, not worked out here.** `SyncTenantStatus`
 * owns that rule — the grace days run from the later of the failure and the
 * end of the paid period — and a second copy of it is how an owner gets told
 * one date and locked on another.
 */
final readonly class SendDunningNotices
{
    /**
     * Days after the failure at which an email goes out.
     *
     * A mark rather than a date, like the trial reminders: a nightly sweep
     * that matches on exact dates sends nothing at all on the day it is
     * missed, while a mark catches up on the next run.
     */
    private const AT_DAYS = [1, 3, 7];

    public function __construct(
        private TenantContext $context,
        private SyncTenantStatus $status,
    ) {}

    /**
     * @return array{sent: int, failed: int}
     */
    public function handle(): array
    {
        $sent = 0;
        $failed = 0;

        $this->context->withoutScoping(function () use (&$sent, &$failed): void {
            Subscription::query()
                ->acrossTenants()
                ->whereNotNull('payment_failed_at')
                ->chunkById(100, function ($subscriptions) use (&$sent, &$failed): void {
                    $tenants = Tenant::query()
                        ->whereKey($subscriptions->pluck('tenant_id'))
                        ->get()
                        ->keyBy('id');

                    foreach ($subscriptions as $subscription) {
                        $tenant = $tenants->get($subscription->tenant_id);

                        if (! $tenant instanceof Tenant) {
                            continue;
                        }

                        $day = $this->dueDay($subscription);

                        if ($day === null) {
                            continue;
                        }

                        try {
                            $this->chase($tenant, $subscription, $day);
                            $sent++;
                        } catch (Throwable $exception) {
                            $failed++;

                            Log::warning('Could not send a payment failure notice.', [
                                'tenant' => $tenant->slug,
                                'day' => $day,
                                'error' => $exception->getMessage(),
                            ]);
                        }
                    }
                });
        });

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Which email is owed today, if any.
     *
     * The latest mark reached that has not been sent yet, so a sweep that was
     * missed for four days sends the day-three email once rather than the
     * day-one and day-three emails together. Two emails in the same minute
     * read as a system that has lost track of itself.
     */
    private function dueDay(Subscription $subscription): ?int
    {
        $failedAt = $subscription->payment_failed_at;

        if ($failedAt === null) {
            return null;
        }

        $elapsed = (int) $failedAt->startOfDay()->diffInDays(now()->startOfDay());
        $already = $subscription->dunning_sent_for;

        $due = null;

        foreach (self::AT_DAYS as $mark) {
            if ($elapsed >= $mark && ($already === null || $mark > (int) $already)) {
                $due = $mark;
            }
        }

        return $due;
    }

    private function chase(Tenant $tenant, Subscription $subscription, int $day): void
    {
        $owners = User::query()
            ->whereHas('tenants', fn ($query) => $query->whereKey($tenant->getKey()))
            ->pluck('email')
            ->all();

        if ($owners === []) {
            // Nobody to tell. Marked anyway, or every sweep for ever retries
            // an account that cannot be written to.
            $this->mark($tenant, $subscription, $day);

            return;
        }

        Mail::to($owners)->send(new PaymentFailedNotice(
            $tenant,
            $day,
            $this->status->locksOn($subscription),
            $this->reasonFor($subscription),
        ));

        // After the send, so a failure is tried again tomorrow rather than
        // silently skipped.
        $this->mark($tenant, $subscription, $day);
    }

    private function mark(Tenant $tenant, Subscription $subscription, int $day): void
    {
        $this->context->asTenant($tenant, static function () use ($subscription, $day): void {
            $subscription->forceFill(['dunning_sent_for' => $day])->save();
        });
    }

    /**
     * What the provider said, if it said anything an owner can act on.
     *
     * Read back from the account's own history rather than stored a second
     * time on the subscription: the event is written the moment the failure
     * arrives, and one copy of a sentence is enough.
     */
    private function reasonFor(Subscription $subscription): ?string
    {
        $summary = $this->context->withoutScoping(
            static fn (): ?string => $subscription->events()
                ->whereNotNull('summary')
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->value('summary')
        );

        if (! is_string($summary) || ! str_starts_with($summary, 'Payment failed: ')) {
            return null;
        }

        return substr($summary, strlen('Payment failed: '));
    }
}
