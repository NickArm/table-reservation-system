<?php

declare(strict_types=1);

namespace App\Actions\Tenants;

use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Venue;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a sign-up into a working account: a user, a tenant on trial, its venue
 * and the owner membership.
 *
 * One venue is created automatically and never shown as a choice (SPEC §1.3).
 */
final readonly class RegisterVenueAccount
{
    public function __construct(private TenantContext $context) {}

    public function handle(string $name, string $email, string $password, string $venueName): User
    {
        return DB::transaction(function () use ($name, $email, $password, $venueName): User {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);

            $tenant = Tenant::create([
                'name' => $venueName,
                'slug' => $this->uniqueSlug($venueName),
                'status' => TenantStatus::Trialing,
                'trial_ends_at' => now()->addDays(config('tenancy.trial_days')),
            ]);

            $tenant->users()->attach($user, ['role' => TenantRole::Owner->value]);

            $this->context->asTenant($tenant, static function () use ($venueName, $tenant): void {
                Venue::create([
                    'name' => $venueName,
                    'timezone' => $tenant->timezone,
                ]);
            });

            return $user;
        });
    }

    /**
     * Slugs are permanent: a deleted account keeps its slug so that an old link
     * never resolves to a different venue (SPEC §29.3). So uniqueness is
     * checked against trashed rows too.
     */
    private function uniqueSlug(string $venueName): string
    {
        $base = Str::slug($venueName);

        if ($base === '' || in_array($base, config('tenancy.reserved_slugs'), true)) {
            $base = 'venue';
        }

        $slug = $base;
        $suffix = 1;

        while (Tenant::withTrashed()->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
