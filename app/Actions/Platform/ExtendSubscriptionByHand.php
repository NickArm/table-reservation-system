<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Actions\Subscriptions\RecordSubscriptionEvent;
use App\Actions\Subscriptions\SyncTenantStatus;
use App\Enums\AuditAction;
use App\Enums\SubscriptionEventType;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A free month, a bank transfer, a goodwill extension (SPEC §4.1).
 *
 * The thing that makes a subscription system usable by a human being. Venues
 * pay by transfer, a season starts late, something goes wrong and the fair
 * answer is a fortnight on us — and none of that can require a card.
 *
 * Three decisions worth stating.
 *
 * **It always moves forward.** The new end date has to be later than the
 * current one, because an extension that shortened a period would take away
 * something already paid for, and that is not a thing anybody should be able
 * to do by typing a date into a box. Ending an account early is suspension,
 * which is a different screen and a different word.
 *
 * **It clears a failed payment.** An account extended by hand is square with
 * us by definition, so leaving `payment_failed_at` set would keep the dunning
 * emails going out about a debt that has been settled or forgiven.
 *
 * **It is written down twice, on purpose.** The owner's billing history gets
 * a line they can read, and the platform's audit log gets the reason. The two
 * audiences are not the same: "Extended to 30 September" is what the venue
 * needs, "paid by transfer, invoice 2026-114" is what we need.
 */
final readonly class ExtendSubscriptionByHand
{
    public function __construct(
        private TenantContext $context,
        private SyncTenantStatus $status,
        private RecordSubscriptionEvent $events,
        private RecordAuditEntry $audit,
    ) {}

    public function handle(
        Tenant $tenant,
        Subscription $subscription,
        CarbonImmutable $until,
        User $actor,
        string $reason,
    ): Subscription {
        $previousEnd = $subscription->current_period_end;

        if ($previousEnd !== null && ! $until->greaterThan($previousEnd)) {
            throw new InvalidArgumentException(
                'An extension has to end later than the period it extends. '
                .'To end an account early, suspend it.'
            );
        }

        return DB::transaction(function () use (
            $tenant,
            $subscription,
            $until,
            $actor,
            $reason,
            $previousEnd,
        ): Subscription {
            /*
             * Bound explicitly, because this runs from the admin area with
             * scoping switched off and `subscription_events` is tenant-owned.
             * Without a tenant in context the trait refuses to stamp the row
             * — correctly, since the alternative is a history line belonging
             * to nobody.
             */
            $this->context->asTenant($tenant, function () use (
                $subscription,
                $until,
                $actor,
                $reason,
            ): void {
                $subscription->forceFill([
                    'current_period_end' => $until,

                    /*
                     * Square with us by definition. Left set, the nightly
                     * sweep would keep chasing a payment that has been
                     * settled or forgiven, and the account would still lock
                     * on the grace date despite the extension.
                     */
                    'payment_failed_at' => null,
                    'dunning_sent_for' => null,
                ])->save();

                $this->events->handle(
                    $subscription,
                    SubscriptionEventType::AdjustedByHand,
                    'Extended until '.$until->format('j M Y').'.',
                    actor: $actor,
                    reason: $reason,
                );
            });

            $this->status->handle($tenant);

            $this->audit->handle(
                AuditAction::SubscriptionExtended,
                $tenant->name.' extended until '.$until->format('j M Y').'.',
                actor: $actor,
                tenant: $tenant,
                reason: $reason,
                context: [
                    'from' => $previousEnd?->toIso8601String(),
                    'to' => $until->toIso8601String(),
                ],
            );

            return $subscription;
        });
    }
}
