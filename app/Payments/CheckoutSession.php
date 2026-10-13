<?php

declare(strict_types=1);

namespace App\Payments;

/**
 * Where to send the guest, and what to call this attempt afterwards.
 */
final readonly class CheckoutSession
{
    public function __construct(
        public string $id,
        public ?string $url,
        /** @var array<string, mixed> */
        public array $payload = [],
    ) {}

    /**
     * Nowhere to send them — which is how paying at the venue works.
     */
    public function isImmediate(): bool
    {
        return $this->url === null;
    }
}
