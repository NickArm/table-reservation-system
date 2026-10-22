<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The platform's own screens (SPEC §2.1, §13.2).
 *
 * Two jobs, and the second is the one that is easy to forget.
 *
 * **404, not 403.** A venue owner who stumbles onto /admin is told the page
 * does not exist, because confirming that it does is an invitation. There is
 * nothing here an owner needs, so there is nothing to explain.
 *
 * **Scoping off.** These screens exist precisely to look across accounts, and
 * `TenantScope` refuses any tenant-scoped query with nothing bound — which is
 * correct everywhere else and useless here. The bypass is granted for the
 * request rather than inside each controller, so a new admin screen cannot
 * forget it and get a `TenantNotResolved` on a page that should plainly work.
 *
 * It is granted *only* behind this middleware. A bypass that could be reached
 * without being a super admin is the one bug in this file worth being afraid
 * of: it turns every tenant-scoped query in the application into a query over
 * everybody's rows.
 */
final class EnsureSuperAdmin
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isSuperAdmin()) {
            abort(404);
        }

        /*
         * An admin who is currently impersonating a venue is, for the length
         * of that session, acting as that venue — so the platform screens are
         * closed until they stop. Letting both be true at once means a screen
         * that reads across accounts while a tenant is bound, which is the
         * most confusing possible state to debug.
         */
        if ($request->session()->has('impersonator_id')) {
            abort(404);
        }

        $this->context->bypass();

        return $next($request);
    }
}
