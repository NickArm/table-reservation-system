<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the tenant for the dashboard, from the signed-in user.
 *
 * The slug never appears in a dashboard URL (SPEC §3.2), so there is no way to
 * reach another account's data by editing the address bar — the only source is
 * the user's own membership.
 */
final class IdentifyTenantFromUser
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $tenant = $user->currentTenant();

        abort_if($tenant === null, 403, 'This account is not linked to a venue.');

        $this->context->set($tenant);

        return $next($request);
    }
}
