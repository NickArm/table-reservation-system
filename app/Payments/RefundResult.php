<?php

declare(strict_types=1);

namespace App\Payments;

/**
 * The outcome of asking for money back (SPEC §8.3).
 *
 * `pending` is the normal first answer and the one the interface exists to
 * make unavoidable: a refund is asynchronous, and telling a guest their money
 * is back before the gateway says so is how support tickets are born.
 */
final readonly class RefundResult
{
    public function __construct(
        public bool $accepted,
        public int $amountCents,
        public ?string $gatewayRefundId = null,
        public ?string $reference = null,
        public ?string $failureReason = null,
        public bool $isPending = true,
        /** @var array<string, mixed> */
        public array $payload = [],
    ) {}

    public static function failed(string $reason, int $amountCents = 0): self
    {
        return new self(
            accepted: false,
            amountCents: $amountCents,
            failureReason: $reason,
            isPending: false,
        );
    }
}
