<?php

declare(strict_types=1);

namespace App\Billing\Exceptions;

use App\Enums\BillingProvider;
use RuntimeException;

/**
 * The subscription could not be set up (SPEC §4.3).
 *
 * Thrown rather than returned, so it cannot be ignored on the way to telling
 * an owner their payment worked. Every message here is written to be shown:
 * somebody trying to give us money and failing deserves to know whether the
 * problem is theirs or ours.
 */
final class BillingUnavailable extends RuntimeException
{
    public static function planNotSetUp(string $plan, BillingProvider $provider): self
    {
        // Ours, not theirs. The owner can do nothing about it, so the message
        // says so rather than suggesting they try again.
        return new self(
            "“{$plan}” is not set up for {$provider->label()} payments yet. "
            .'Get in touch and we will sort it out — this one is on us.'
        );
    }

    public static function noProvider(): self
    {
        return new self(
            'Online payment for subscriptions is not switched on yet. '
            .'Reply to any of our emails and we will arrange your plan directly.'
        );
    }

    public static function providerRefused(BillingProvider $provider, string $message): self
    {
        return new self($provider->label().' could not start the payment: '.$message);
    }

    public static function providerSaidNothingUseful(BillingProvider $provider): self
    {
        return new self(
            $provider->label().' did not answer properly. Nothing has been charged — '
            .'please try again in a moment.'
        );
    }
}
