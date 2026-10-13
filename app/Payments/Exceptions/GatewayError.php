<?php

declare(strict_types=1);

namespace App\Payments\Exceptions;

use App\Enums\Gateway;
use RuntimeException;

/**
 * The gateway said no, or said nothing we understand.
 *
 * Carries a message fit to show an owner: "Stripe refused those keys" is
 * useful, a stack trace is not.
 */
final class GatewayError extends RuntimeException
{
    public static function missingCredentials(Gateway $gateway): self
    {
        return new self("{$gateway->label()} has no keys set up yet.");
    }

    public static function unexpected(Gateway $gateway, string $message): self
    {
        return new self("{$gateway->label()}: {$message}");
    }

    public static function notConfigured(Gateway $gateway): self
    {
        return new self("{$gateway->label()} is not switched on for this venue.");
    }
}
