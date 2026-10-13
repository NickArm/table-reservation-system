<?php

declare(strict_types=1);

namespace App\Payments;

/**
 * What a gateway needs in order to ask a guest for money.
 *
 * Deliberately flat and gateway-agnostic: Stripe wants line items, PayPal
 * wants purchase units, and neither word belongs anywhere outside its own
 * implementation (SPEC §31.4).
 */
final readonly class CheckoutRequest
{
    public function __construct(
        public string $reference,
        public string $description,
        public int $amountCents,
        public string $currency,
        public string $successUrl,
        public string $cancelUrl,
        public ?string $customerEmail = null,
        /** @var array<string, string> */
        public array $metadata = [],
        public ?string $statementDescriptor = null,
    ) {}
}
