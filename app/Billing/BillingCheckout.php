<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * Where to send the owner to start paying, and what to call it afterwards.
 *
 * A value object rather than an array so the two fields cannot be mixed up:
 * both are strings, both look like identifiers, and sending the browser to
 * the reference instead of the URL fails in a way nobody would diagnose
 * quickly.
 */
final readonly class BillingCheckout
{
    public function __construct(
        /** Where the browser goes next. */
        public string $url,

        /**
         * The provider's own id for this attempt. Kept so the return can ask
         * the provider what happened rather than trusting the redirect.
         */
        public string $reference,
    ) {}
}
