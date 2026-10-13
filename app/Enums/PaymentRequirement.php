<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a booking has to be paid for up front (SPEC §7.3).
 */
enum PaymentRequirement: string
{
    /** No payment, no booking. The default for sunbeds. */
    case Required = 'required';

    /** The guest chooses: pay now or pay at the venue. */
    case Optional = 'optional';

    /** Free to book. */
    case None = 'none';

    public function needsGateway(): bool
    {
        return $this !== self::None;
    }

    public function label(): string
    {
        return match ($this) {
            self::Required => 'Payment required',
            self::Optional => 'Payment optional',
            self::None => 'No payment',
        };
    }
}
