<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Enums\SubscriptionEventType;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use InvalidArgumentException;

/**
 * Writes one line of billing history (SPEC §4.1, §4.3).
 *
 * Every subscription action goes through here, so the wording an owner reads
 * lives in one file rather than being improvised at each call site, and so
 * the rule about by-hand changes is enforced rather than remembered: anything
 * a person did carries their name and their reason, or it is not recorded at
 * all.
 */
final readonly class RecordSubscriptionEvent
{
    public function handle(
        Subscription $subscription,
        SubscriptionEventType $type,
        string $summary,
        ?int $amountCents = null,
        ?User $actor = null,
        ?string $reason = null,
    ): SubscriptionEvent {
        if ($type->needsAnActor() && ($actor === null || trim((string) $reason) === '')) {
            // Deliberately a thrown error rather than a quiet null. An
            // unexplained free month is indistinguishable from a mistake, and
            // the person who has to answer for it later is whoever is reading
            // this history.
            throw new InvalidArgumentException(
                'A change made by hand needs both a person and a reason.'
            );
        }

        // Loaded explicitly: strict mode refuses a lazy load, and the plan's
        // name is wanted on the line so it still reads correctly after that
        // plan has been retired.
        $subscription->loadMissing('plan');

        return SubscriptionEvent::query()->create([
            'subscription_id' => $subscription->getKey(),
            'plan_name' => $subscription->plan?->name,
            'type' => $type,
            'summary' => $summary,
            'actor_id' => $actor?->getKey(),
            'reason' => $reason,
            'amount_cents' => $amountCents,
            'currency' => $amountCents === null ? null : $subscription->currency,
            'occurred_at' => now(),
        ]);
    }
}
