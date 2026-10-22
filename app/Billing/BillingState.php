<?php

declare(strict_types=1);

namespace App\Billing;

use Carbon\CarbonImmutable;

/**
 * What a provider says about a subscription right now.
 *
 * The answer that counts. A webhook can be late, lost, replayed or forged;
 * this comes from asking the provider directly, which is the only thing worth
 * acting on when money is involved (SPEC §24.2).
 */
final readonly class BillingState
{
    public function __construct(
        public bool $active,
        public ?string $subscriptionId,
        public ?string $customerId,
        public ?CarbonImmutable $currentPeriodEnd,
        public ?int $amountCents,
        public ?string $currency,

        /** Set when the provider says the last payment did not go through. */
        public bool $paymentFailed = false,

        /** Set when it has been cancelled at the provider's end. */
        public bool $cancelled = false,
    ) {}

    public function isUsable(): bool
    {
        return $this->active && $this->subscriptionId !== null;
    }
}
