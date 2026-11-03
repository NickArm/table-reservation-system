<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Enums\BillingProvider;
use App\Enums\TenantStatus;
use App\Mail\ManualRenewalsDue;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells the platform which hand-billed accounts are about to run out.
 *
 * This is the piece that makes "pays outside the system" safe. Everything
 * else in the lifecycle announces itself: a card renews on its own, and a
 * card that fails produces a `payment_failed_at`, which the dunning emails
 * read. An invoice sent by a person produces nothing at all — so without
 * this, the first anybody hears of a lapse is the customer ringing to say
 * the booking page has stopped, on a date that was known a month earlier.
 *
 * It writes to **us**, never to the customer. They agreed to pay an invoice,
 * not to be chased by the software; being emailed by a system about money
 * they have already arranged with a person reads as a mistake, and usually
 * is one.
 *
 * One message for all of them rather than one each. A digest is read; four
 * separate emails on a Tuesday morning are filed.
 */
final readonly class WarnBeforeManualLapse
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return array{due: int, sent: int, failed: int}
     */
    public function handle(): array
    {
        $due = $this->context->withoutScoping(fn (): array => $this->findDue());

        if ($due === []) {
            return ['due' => 0, 'sent' => 0, 'failed' => 0];
        }

        $watchers = User::query()
            ->where('is_super_admin', true)
            ->pluck('email')
            ->all();

        if ($watchers === []) {
            /*
             * Nobody to tell. Not marked: a platform with no administrator
             * is a temporary state, and swallowing the notice now would mean
             * the first admin created never hears about the account that was
             * due while the seat was empty.
             */
            Log::warning('Hand-billed accounts are running out and there is no platform admin to tell.', [
                'accounts' => count($due),
            ]);

            return ['due' => count($due), 'sent' => 0, 'failed' => count($due)];
        }

        try {
            Mail::to($watchers)->send(new ManualRenewalsDue($due));
        } catch (Throwable $exception) {
            Log::warning('Could not send the hand-billed renewal notice.', [
                'error' => $exception->getMessage(),
            ]);

            return ['due' => count($due), 'sent' => 0, 'failed' => count($due)];
        }

        foreach ($due as $row) {
            $this->mark($row['subscription']);
        }

        return ['due' => count($due), 'sent' => count($due), 'failed' => 0];
    }

    /**
     * Accounts billed by hand whose paid period ends inside the window.
     *
     * Suspended ones are left out: somebody switched those off deliberately,
     * and a reminder to invoice an account we have cut off is noise.
     *
     * @return list<array{subscription: Subscription, tenant: Tenant}>
     */
    private function findDue(): array
    {
        $horizon = now()->addDays((int) config('tenancy.manual_notice_days'));

        $subscriptions = Subscription::query()
            ->acrossTenants()
            ->where('provider', BillingProvider::Manual->value)
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', $horizon)
            ->get()
            ->filter(fn (Subscription $subscription): bool => $this->isOwed($subscription));

        if ($subscriptions->isEmpty()) {
            return [];
        }

        $tenants = Tenant::query()
            ->whereKey($subscriptions->pluck('tenant_id'))
            ->where('status', '!=', TenantStatus::Suspended->value)
            ->get()
            ->keyBy('id');

        $due = [];

        foreach ($subscriptions as $subscription) {
            $tenant = $tenants->get($subscription->tenant_id);

            if (! $tenant instanceof Tenant) {
                continue;
            }

            $due[] = ['subscription' => $subscription, 'tenant' => $tenant];
        }

        /*
         * Soonest first, because the list is read top down and the one that
         * matters is the one that lapses on Friday.
         */
        usort(
            $due,
            static fn (array $a, array $b): int => $a['subscription']->current_period_end
                <=> $b['subscription']->current_period_end,
        );

        return $due;
    }

    /**
     * Has this period been reported yet?
     *
     * Compared against the period start rather than a flag that gets reset:
     * a renewal moves the period, so the stale notice falls behind the new
     * start on its own and the next period warns without anybody clearing
     * anything.
     */
    private function isOwed(Subscription $subscription): bool
    {
        $sent = $subscription->manual_notice_sent_at;

        if ($sent === null) {
            return true;
        }

        $start = $subscription->current_period_start;

        return $start !== null && $sent->lessThan($start);
    }

    private function mark(Subscription $subscription): void
    {
        $this->context->withoutScoping(static function () use ($subscription): void {
            $subscription->forceFill(['manual_notice_sent_at' => now()])->save();
        });
    }
}
