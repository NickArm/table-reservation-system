<?php

declare(strict_types=1);

namespace App\Billing;

use App\Enums\BillingEventKind;

/**
 * One verified webhook, translated.
 */
final readonly class BillingNotice
{
    public function __construct(
        public BillingEventKind $kind,
        public ?string $subscriptionId,
        public ?int $amountCents = null,
        public ?string $reason = null,
    ) {}

    public static function ignored(): self
    {
        return new self(BillingEventKind::Ignored, null);
    }
}
