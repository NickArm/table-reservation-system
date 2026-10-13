<?php

declare(strict_types=1);

namespace App\Availability\Exceptions;

use RuntimeException;

/**
 * Somebody got there first.
 *
 * Thrown rather than returned so that it cannot be ignored on the way to a
 * database write — the one mistake that produces two families at one table.
 */
final class SpotNotAvailable extends RuntimeException
{
    public static function taken(string $label): self
    {
        return new self("{$label} is no longer available at that time.");
    }

    public static function notOffered(): self
    {
        return new self('That time is not available for booking.');
    }
}
