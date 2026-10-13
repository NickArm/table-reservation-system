<?php

declare(strict_types=1);

namespace Database\Factories\Concerns;

use App\Models\Tenant;
use App\Tenancy\TenantContext;

/**
 * Factories follow the tenant in context.
 *
 * Without this, a nested factory quietly spawns a second tenant and the data a
 * test builds no longer hangs together.
 */
trait UsesCurrentTenant
{
    protected function currentTenantId(): mixed
    {
        return app(TenantContext::class)->get()?->id ?? Tenant::factory();
    }
}
