<?php

declare(strict_types=1);

namespace App\Observability;

use App\Tenancy\TenantContext;
use Sentry\Event;
use Sentry\Frame;
use Sentry\Stacktrace;
use Throwable;

/**
 * The last thing that touches an error before it leaves the building
 * (SPEC §30.2).
 *
 * Sentry is genuinely useful for a one-person company taking payments —
 * hearing about a broken checkout from a customer's telephone call is a bad
 * place to be. But an error reporter is a pipe to a third party, and this
 * application handles two things that must never go down that pipe:
 *
 *  - **venues' own gateway keys.** A failure inside the payment flow carries
 *    a stack trace, and a stack trace carries the local variables of every
 *    frame — including the one holding a live Stripe secret.
 *  - **tourists' email addresses and telephone numbers.** Sending those to a
 *    processor we have not named would contradict our own privacy page and
 *    the DPA we offer venues (§12.2, §24.7).
 *
 * So this runs on every event, and it is deliberately blunt: it would rather
 * redact something debuggable than leak something it should not.
 *
 * Declared as a **static method**, not a closure in the config file. A
 * closure there cannot be serialised, so `php artisan config:cache` — which
 * every production deploy runs — fails outright, and the error points at the
 * config file rather than at the closure.
 */
final class SentryScrubber
{
    /**
     * Keys whose value never leaves, whatever it holds.
     *
     * The same instinct as `Payment::scrub()`, which keeps a gateway's words
     * out of our own payments table, with one difference: that one drops the
     * key, this one keeps it with a marker. On a stack trace it matters that
     * a variable existed — a frame that silently lost half its arguments is
     * harder to read than one that says what was taken out.
     */
    private const SECRET_KEYS = [
        'secret', 'secret_key', 'client_secret', 'webhook_secret', 'signing_secret',
        'api_key', 'apikey', 'key', 'token', 'access_token', 'refresh_token',
        'password', 'password_confirmation', 'authorization', 'auth',
        'credentials', 'private_key', 'signature',
        'card', 'number', 'cvc', 'cvv', 'exp_month', 'exp_year', 'pan',
    ];

    /**
     * Keys holding somebody's identity rather than a secret.
     *
     * A tourist's name is not a credential, but it is still theirs, and there
     * is no version of "debugging" that needs it.
     */
    private const PERSONAL_KEYS = [
        'email', 'guest_email', 'customer_email', 'contact_email',
        'phone', 'guest_phone', 'customer_phone', 'telephone',
        'guest_name', 'customer_name', 'full_name',
        'ip', 'ip_address', 'remote_addr', 'cookie', 'cookies',
    ];

    private const REDACTED = '[redacted]';

    /**
     * How deep to walk a nested array.
     *
     * A guard rather than a judgement: an event is built from whatever was in
     * scope when something broke, and a circular or absurdly deep structure
     * must not turn error reporting into the next outage.
     */
    private const MAX_DEPTH = 8;

    public static function handle(Event $event): ?Event
    {
        /*
         * Which venue is affected, answered before anybody has to ask. An id
         * is not personal information, and it is the first question about any
         * error in a multi-tenant system.
         *
         * Read here rather than tagged by a middleware because this runs at
         * send time, inside the request, job or command that failed — so the
         * same single line covers the web, the API, the queue and the
         * console, and there is no second place to forget.
         */
        try {
            $tenant = app(TenantContext::class)->get();

            if ($tenant !== null) {
                $event->setTags([
                    ...$event->getTags(),
                    'tenant' => (string) $tenant->slug,
                ]);
            }
        } catch (Throwable) {
            // Never let the scrubber be the thing that breaks. An event
            // without a tag is a small loss; an exception in `before_send`
            // during an outage is a second outage.
        }

        /*
         * The user context goes entirely. With `send_default_pii` off Sentry
         * does not fill it in by itself, but `Integration::configureScope()`
         * elsewhere, or a future version of the package, could — and
         * dropping it costs nothing.
         */
        $event->setUser(null);

        $event->setRequest(self::requestWithoutPeople($event->getRequest()));
        $event->setExtra(self::clean($event->getExtra()));

        foreach ($event->getContexts() as $name => $context) {
            $event->setContext($name, self::clean($context));
        }

        self::cleanExceptions($event);

        return $event;
    }

    /**
     * What is left of the request: where it went, and nothing about who sent
     * it.
     *
     * The address and the method are the useful half and carry nobody's
     * details. The body, the headers and the cookies are where a booking
     * form's email address and a session cookie live.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private static function requestWithoutPeople(array $request): array
    {
        $kept = [];

        foreach (['url', 'method'] as $field) {
            if (isset($request[$field])) {
                // The query string can hold an email just as easily as a
                // form body can.
                $kept[$field] = self::scrubText((string) $request[$field]);
            }
        }

        return $kept;
    }

    /**
     * Walks an exception's frames and takes the secrets out of the locals.
     *
     * This is the part that matters most. `frame_vars` is on by default, and
     * it is what makes a Sentry stack trace worth reading — the arguments
     * that were actually passed. In the payment flow those arguments are a
     * venue's live gateway credentials.
     */
    private static function cleanExceptions(Event $event): void
    {
        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::scrubText($exception->getValue()));

            $stacktrace = $exception->getStacktrace();

            if (! $stacktrace instanceof Stacktrace) {
                continue;
            }

            foreach ($stacktrace->getFrames() as $frame) {
                if (! $frame instanceof Frame) {
                    continue;
                }

                $vars = $frame->getVars();

                if ($vars !== []) {
                    $frame->setVars(self::clean($vars));
                }
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function clean(array $data, int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [self::REDACTED];
        }

        $clean = [];

        foreach ($data as $key => $value) {
            if (self::isSensitive((string) $key)) {
                $clean[$key] = self::REDACTED;

                continue;
            }

            $clean[$key] = match (true) {
                is_array($value) => self::clean($value, $depth + 1),
                is_string($value) => self::scrubText($value),

                // An object in a frame variable is the venue's own model as
                // often as not, and there is no way to know what a __toString
                // would reveal. The class name is the useful part anyway.
                is_object($value) => $value::class,
                default => $value,
            };
        }

        return $clean;
    }

    private static function isSensitive(string $key): bool
    {
        $key = mb_strtolower($key);

        if (in_array($key, self::SECRET_KEYS, true) || in_array($key, self::PERSONAL_KEYS, true)) {
            return true;
        }

        /*
         * Matched loosely as well as exactly, because the names in this
         * codebase are descriptive: `stripe_secret_key`, `paypal_client_secret`,
         * `token_hash`, `guest_email_address`. An exact list would miss every
         * one of them.
         */
        foreach (['secret', 'password', 'token', 'api_key', 'apikey', 'credential', 'email', 'phone'] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Takes the recognisable shapes out of free text.
     *
     * Exception messages are written by people and quote whatever broke:
     * "No guest found for nick@example.com", "Invalid API key sk_live_…".
     * Key names cannot help here, so the values are matched by their shape.
     */
    private static function scrubText(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $patterns = [
            // Our own tokens and both gateways' keys, live and test alike —
            // a test key in a report is still a credential somebody has to
            // rotate.
            '/\b(?:sk|rk|whsec|pi|seti|cs)_[A-Za-z0-9_]{6,}/',
            '/\btr_(?:live|test)_[A-Za-z0-9]{8,}/',
            '/\bBearer\s+[A-Za-z0-9._\-]{8,}/i',

            // Email addresses and telephone numbers in any shape that would
            // identify somebody.
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
            '/\+\d[\d\s().\-]{7,}\d/',

            // Long digit runs, which is what a card number looks like once
            // the spaces are gone.
            '/\b\d{13,19}\b/',
        ];

        return (string) preg_replace($patterns, self::REDACTED, $text);
    }
}
