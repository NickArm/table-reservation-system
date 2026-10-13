<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether one table or sunbed is offered online right now (SPEC §5.4).
 *
 * Switched by hand, not by any automatic pacing: the owner keeps half the room
 * for walk-ins, or closes the beach because of wind, and does it in one tap.
 *
 * Closing only stops *new* bookings. Existing ones stay valid.
 */
enum OnlineStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Closed => 'Closed',
        };
    }

    public function toggled(): self
    {
        return $this === self::Open ? self::Closed : self::Open;
    }
}
