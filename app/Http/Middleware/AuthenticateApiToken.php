<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiRequest;
use App\Models\ApiToken;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The whole of the public API's auth (SPEC §15.2.1).
 *
 * Four jobs, in this order, because each depends on the one before:
 *
 *  1. resolve the token and bind its tenant — every id in every later query
 *     is filtered by TenantScope from that binding, so there is no code path
 *     that could reach another account's rows even if a controller asked
 *  2. rate limit per token, not per IP: one customer's busy server must not
 *     spend another's allowance
 *  3. notice a token arriving from a browser, which means it has been put
 *     into someone's JavaScript where anyone can read it
 *  4. record the call, whatever the outcome
 */
final class AuthenticateApiToken
{
    /** Calls a token may make per minute (SPEC §15.2.1). */
    private const PER_MINUTE = 120;

    public function __construct(
        private readonly TenantContext $context,
        private readonly RateLimiter $limiter,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $presented = (string) $request->bearerToken();

        if ($presented === '') {
            return $this->refuse(
                'missing_token',
                'Send your token as `Authorization: Bearer tr_live_…`.',
                401,
            );
        }

        // Hidden columns still load; only serialisation leaves them out.
        $token = ApiToken::query()
            ->acrossTenants()
            ->where('token_hash', ApiToken::hash($presented))
            ->first();

        // Nothing matched, so there is no account to answer for and nothing
        // to record. Same message as a revoked token on purpose: an answer
        // that distinguished the two would confirm which strings once existed.
        if (! $token instanceof ApiToken) {
            return $this->refuse('invalid_token', 'That token is not valid.', 401);
        }

        return $this->context->asTenant(
            $token->tenant()->sole(),
            fn (): Response => $this->authenticated($request, $next, $token),
        );
    }

    private function authenticated(Request $request, Closure $next, ApiToken $token): Response
    {
        $started = microtime(true);

        $response = $this->respondTo($request, $next, $token);

        $this->record($request, $token, $response, $started);

        return $response;
    }

    private function respondTo(Request $request, Closure $next, ApiToken $token): Response
    {
        if (! $token->isUsable()) {
            return $this->refuse(
                'invalid_token',
                'That token is not valid.',
                401,
            );
        }

        $key = 'api-token:'.$token->getKey();

        if ($this->limiter->tooManyAttempts($key, self::PER_MINUTE)) {
            $retryAfter = $this->limiter->availableIn($key);

            return $this->refuse(
                'rate_limited',
                'Too many requests. Try again in '.$retryAfter.' seconds.',
                429,
                ['Retry-After' => (string) $retryAfter],
            );
        }

        $this->limiter->hit($key, 60);

        $this->noteUse($request, $token);

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    /**
     * An Origin header means a browser sent this, which means the token is in
     * client-side code. Recorded rather than refused: refusing would break a
     * working integration without explaining itself, while the dashboard can
     * name the site and say what to do about it.
     */
    private function noteUse(Request $request, ApiToken $token): void
    {
        $origin = $request->headers->get('Origin');

        $changes = [];

        if ($origin !== null && $origin !== $token->last_origin) {
            $changes['last_origin'] = $origin;
        }

        if ($origin !== null && ! $token->isExposed()) {
            $changes['exposed_at'] = now();
        }

        // Written at most once a minute. "Last used" to the minute is all the
        // Developer page shows, and a busy server would otherwise make us
        // update the same row on every single call for no visible gain.
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $changes['last_used_at'] = now();
        }

        if ($changes === []) {
            return;
        }

        $token->forceFill($changes)->save();
    }

    private function record(Request $request, ApiToken $token, Response $response, float $started): void
    {
        ApiRequest::query()->create([
            'api_token_id' => $token->getKey(),
            'method' => $request->getMethod(),

            // The route's own pattern, so the Developer page can group by
            // endpoint. A filled-in path would also drag a party size and a
            // date into a table that holds nothing but counts.
            'endpoint' => $request->route()?->uri() ?? $request->path(),
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'occurred_at' => now(),
        ]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function refuse(string $code, string $message, int $status, array $headers = []): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => $code, 'message' => $message]],
            $status,
            $headers,
        );
    }
}
