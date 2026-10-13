<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Tenant;
use App\Tenancy\Exceptions\TenantNotResolved;
use Closure;

/**
 * Holds the tenant for the current request, command or job.
 *
 * Bound as a singleton. Every tenant-scoped query reads from here, so this is
 * the single place that decides which venue's data a request can see.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    /**
     * When true, tenant scoping is switched off for the current execution.
     * Only the super admin area, console commands and seeders may do this.
     */
    private bool $bypassed = false;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->bypassed = false;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function getOrFail(): Tenant
    {
        return $this->tenant ?? throw new TenantNotResolved(
            'No tenant is bound to this request. A tenant-scoped model was used '
            .'outside a tenant context, and refusing is safer than returning '
            .'every venue\'s rows.'
        );
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function has(): bool
    {
        return $this->tenant instanceof Tenant;
    }

    public function isBypassed(): bool
    {
        return $this->bypassed;
    }

    public function forget(): void
    {
        $this->tenant = null;
        $this->bypassed = false;
    }

    /**
     * Run a callback with tenant scoping switched off, then restore the
     * previous state. Used by the super admin area, seeders and console work.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withoutScoping(Closure $callback): mixed
    {
        $previousTenant = $this->tenant;
        $previousBypass = $this->bypassed;

        $this->tenant = null;
        $this->bypassed = true;

        try {
            return $callback();
        } finally {
            $this->tenant = $previousTenant;
            $this->bypassed = $previousBypass;
        }
    }

    /**
     * Permanently switch scoping off for this execution. Console only.
     */
    public function bypass(): void
    {
        $this->tenant = null;
        $this->bypassed = true;
    }

    /**
     * Run a callback as a given tenant, then restore the previous state.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function asTenant(Tenant $tenant, Closure $callback): mixed
    {
        $previousTenant = $this->tenant;
        $previousBypass = $this->bypassed;

        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $this->tenant = $previousTenant;
            $this->bypassed = $previousBypass;
        }
    }
}
