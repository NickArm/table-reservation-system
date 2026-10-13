<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the tenant for the public pages, from the slug in the path
 * (SPEC §3.2).
 *
 * It resolves the tenant itself rather than waiting for route model binding,
 * because it has to run *before* SubstituteBindings: the booking flow also
 * carries holds and reservations in its URLs, and those are tenant-scoped —
 * resolving them with no tenant in context makes TenantScope refuse, which is
 * correct but useless. See the priority list in bootstrap/app.php.
 */
final class IdentifyTenantFromPath
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $value = $request->route('tenant');

        $tenant = $value instanceof Tenant
            ? $value
            : Tenant::query()->where('slug', (string) $value)->first();

        abort_if(! $tenant instanceof Tenant, 404);

        $this->context->set($tenant);

        // Hand the model to the router so the binding that follows does not
        // go and fetch the same row again.
        $request->route()?->setParameter('tenant', $tenant);

        return $next($request);
    }
}
