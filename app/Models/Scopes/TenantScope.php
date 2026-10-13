<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query on a tenant-scoped model to the current tenant.
 *
 * There is no "no tenant means no filter" branch on purpose: that branch is how
 * multi-tenant systems leak. With no tenant and no explicit bypass the context
 * throws instead.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return;
        }

        $builder->where(
            $model->qualifyColumn('tenant_id'),
            $context->getOrFail()->id,
        );
    }
}
