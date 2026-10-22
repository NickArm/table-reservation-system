<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only when the subscription has run out (SPEC §4.4).
 *
 * Until now "read-only" was a banner, which is to say it was nothing: an
 * expired account could still create tables, take telephone bookings and
 * change prices. This is the part that makes it true.
 *
 * It is careful about three things.
 *
 * **It blocks writes, not reading.** Every GET still works. The owner can see
 * their bookings, open a reservation, run an export — all of it. The spec is
 * explicit that this is not a hard 403 on the dashboard, because the person
 * we are locking is the person we want to take money from, and an account
 * they cannot see is an account they will not pay for.
 *
 * **Billing is exempt.** Locking somebody out of the page where they would
 * renew is the one mistake that guarantees they never do.
 *
 * **So is logging out**, which is not a change to anything and is a
 * reasonable thing to want to do on a page telling you your account has
 * lapsed.
 */
final class EnsureAccountCanWrite
{
    /**
     * Writes that are allowed anyway, by route name.
     *
     * Kept as a short, explicit list rather than a pattern. A pattern would
     * quietly let future routes through, and the cost of getting this wrong
     * in that direction is an account using what it has not paid for.
     */
    private const ALWAYS_ALLOWED = [
        'dashboard.billing',
        'dashboard.billing.checkout',
        'dashboard.billing.cancel',
        'dashboard.notifications.read',

        // Agreeing to new terms (SPEC §12.2). An expired account still sees
        // the banner, and refusing the one button on it would be absurd.
        'dashboard.terms.accept',
        'logout',
    ];

    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $tenant = $this->context->getOrFail();

        if (! $tenant->isReadOnly()) {
            return $next($request);
        }

        $route = $request->route()?->getName();

        if ($route !== null && in_array($route, self::ALWAYS_ALLOWED, true)) {
            return $next($request);
        }

        $message = 'Your subscription has run out, so the dashboard is read-only. '
            .'Nothing has been deleted — renewing brings it all back.';

        if ($request->expectsJson()) {
            return new JsonResponse(
                ['error' => ['code' => 'subscription_lapsed', 'message' => $message]],
                403,
            );
        }

        // Back where they were, with the sentence, rather than an error page.
        // They have not done anything wrong and there is nothing to fix on
        // the screen they are looking at.
        return back()->with('error', $message);
    }
}
