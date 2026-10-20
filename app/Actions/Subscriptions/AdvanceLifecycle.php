<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Enums\SubscriptionEventType;
use App\Enums\TenantStatus;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;

/**
 * Moves every account to the state it should be in today (SPEC §4.3).
 *
 * Without this nothing ever ends: a trial stays a trial for ever, a failed
 * payment stays past_due, and `/register` is a way to get the whole product
 * free. It is the piece that makes the subscription model real, so it runs
 * daily and reports what it did.
 *
 * It is written to be safe to run twice, and to run on an account that is
 * already correct. The status is always derived from scratch by
 * SyncTenantStatus rather than nudged from whatever it was before — a
 * lifecycle that steps from one state to the next drifts the first time a
 * cron is missed, and the one thing worse than locking an account too early
 * is locking a paying one at all.
 *
 * Nothing here deletes anything. The twelve-month retention sweep is separate
 * and deliberately so: expiring an account and erasing it are decisions of
 * very different weight, and they should not share a command.
 */
final readonly class AdvanceLifecycle
{
    public function __construct(
        private TenantContext $context,
        private SyncTenantStatus $status,
        private RecordSubscriptionEvent $events,
    ) {}

    /**
     * @return array{checked: int, changed: int, trials_ended: int, expired: int}
     */
    public function handle(): array
    {
        $checked = 0;
        $changed = 0;
        $trialsEnded = 0;
        $expired = 0;

        $this->context->withoutScoping(function () use (
            &$checked, &$changed, &$trialsEnded, &$expired
        ): void {
            Tenant::query()
                ->where('status', '!=', TenantStatus::Suspended->value)
                ->chunkById(100, function ($tenants) use (
                    &$checked, &$changed, &$trialsEnded, &$expired
                ): void {
                    $subscriptions = Subscription::query()
                        ->acrossTenants()
                        ->whereIn('tenant_id', $tenants->pluck('id'))
                        ->get()
                        ->keyBy('tenant_id');

                    foreach ($tenants as $tenant) {
                        $checked++;

                        $before = $tenant->status;
                        $after = $this->status->handle($tenant);

                        if ($before === $after) {
                            continue;
                        }

                        $changed++;

                        if ($before === TenantStatus::Trialing) {
                            $trialsEnded++;
                        }

                        if ($after === TenantStatus::Expired) {
                            $expired++;
                        }

                        $this->note($tenant, $before, $after, $subscriptions->get($tenant->id));
                    }
                });
        });

        return [
            'checked' => $checked,
            'changed' => $changed,
            'trials_ended' => $trialsEnded,
            'expired' => $expired,
        ];
    }

    /**
     * Writes the change into the account's own history, where there is a
     * subscription to attach it to.
     *
     * An account that never subscribed has nothing to hang an event on — its
     * trial simply ran out, and the log line is the record of that. Inventing
     * a subscription row to hold the event would mean a billing history that
     * says somebody started paying when they did not.
     */
    private function note(
        Tenant $tenant,
        TenantStatus $before,
        TenantStatus $after,
        ?Subscription $subscription,
    ): void {
        Log::info('Subscription status changed.', [
            'tenant' => $tenant->slug,
            'from' => $before->value,
            'to' => $after->value,
        ]);

        if ($subscription === null) {
            return;
        }

        [$type, $summary] = match ($after) {
            TenantStatus::Grace => [
                SubscriptionEventType::EnteredGrace,
                'The payment is still outstanding. Bookings keep working for a few more days.',
            ],
            TenantStatus::Expired => [
                SubscriptionEventType::Expired,
                $before === TenantStatus::Trialing
                    ? 'The trial ended.'
                    : 'The subscription ran out. Your data is kept and renewing brings it all back.',
            ],
            TenantStatus::PastDue => [
                SubscriptionEventType::PaymentFailed,
                'The payment did not go through. We will try again.',
            ],
            default => [null, null],
        };

        if ($type === null || $summary === null) {
            return;
        }

        $this->context->asTenant(
            $tenant,
            fn () => $this->events->handle($subscription, $type, $summary),
        );
    }
}
