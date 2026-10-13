<?php

declare(strict_types=1);

namespace App\Payments;

use App\Enums\PaymentStatus;

/**
 * What the gateway says about one attempt, once the dust settles.
 */
final readonly class PaymentResult
{
    public function __construct(
        public PaymentStatus $status,
        public ?string $gatewayPaymentId = null,
        public int $amountCents = 0,
        public ?string $failureReason = null,
        /** @var array<string, mixed> */
        public array $payload = [],
    ) {}

    public function isPaid(): bool
    {
        return $this->status === PaymentStatus::Paid;
    }

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::Pending;
    }
}
