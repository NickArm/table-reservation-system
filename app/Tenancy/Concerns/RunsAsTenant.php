<?php

declare(strict_types=1);

namespace App\Tenancy\Concerns;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;

/**
 * Binds a tenant for work that happens outside a request.
 *
 * A queued job starts in a fresh process with nothing bound, and every
 * tenant-scoped model refuses to be queried in that state — correctly, since
 * the alternative is one venue's confirmation email listing another's tables.
 *
 * So nothing on the queue carries an Eloquent model. Jobs and listeners carry
 * identifiers, bind the tenant here, and load what they need inside. It is a
 * line of ceremony per job, and it is the line that makes the isolation hold
 * everywhere rather than only in the parts of the system that serve requests.
 */
trait RunsAsTenant
{
    /**
     * @template TReturn
     *
     * @param  Closure(Tenant): TReturn  $callback
     * @return TReturn
     */
    protected function asTenant(int $tenantId, Closure $callback): mixed
    {
        $tenant = Tenant::query()->findOrFail($tenantId);

        return app(TenantContext::class)->asTenant($tenant, static fn (): mixed => $callback($tenant));
    }
}
