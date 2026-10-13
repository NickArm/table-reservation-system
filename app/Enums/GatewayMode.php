<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Test keys or real ones (SPEC §24.3).
 *
 * Worth an enum of its own because the cost of confusing the two is a venue
 * that thinks it is taking bookings and is not — which is why test mode puts
 * a banner across the whole public page.
 */
enum GatewayMode: string
{
    case Test = 'test';
    case Live = 'live';

    public function isLive(): bool
    {
        return $this === self::Live;
    }

    public function label(): string
    {
        return match ($this) {
            self::Test => 'Test mode',
            self::Live => 'Live',
        };
    }
}
