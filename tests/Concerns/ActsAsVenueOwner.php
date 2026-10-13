<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Actions\Tenants\RegisterVenueAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Venue;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Str;

/**
 * Signs in as the owner of a working account, the way a dashboard test needs
 * it: verified, bound to one tenant, with its venue already created.
 */
trait ActsAsVenueOwner
{
    protected Tenant $tenant;

    protected User $owner;

    protected Venue $venue;

    protected function signInAsOwner(string $venueName = 'Demo Beach Bar'): User
    {
        $this->owner = app(RegisterVenueAccount::class)->handle(
            name: 'Owner',
            email: Str::slug($venueName).'@example.test',
            password: 'correct-horse-battery',
            venueName: $venueName,
        );

        $this->owner->forceFill(['email_verified_at' => now()])->save();

        $tenant = $this->owner->currentTenant();
        $this->assertNotNull($tenant);

        $this->tenant = $tenant;

        // Deliberately *not* bound outside a request. Leaving a tenant in
        // context between requests hides ordering bugs in the middleware: a
        // dashboard request has to resolve its own tenant, early enough for
        // route model binding to see it.
        app(TenantContext::class)->forget();

        $this->venue = $this->asTenant(static fn (): Venue => Venue::query()->firstOrFail());

        $this->actingAs($this->owner);

        return $this->owner;
    }

    /**
     * Runs a closure with the test's tenant bound, for arranging data directly
     * rather than through the dashboard.
     */
    protected function asTenant(Closure $callback): mixed
    {
        return app(TenantContext::class)->asTenant($this->tenant, $callback);
    }
}
