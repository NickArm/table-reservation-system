<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'locale'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Tenant, $this>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * The account this user works in.
     *
     * One account per user in v1 — the pivot allows more so that staff shared
     * between two venues work later without a migration.
     */
    public function currentTenant(): ?Tenant
    {
        return $this->tenants()->first();
    }

    public function roleIn(Tenant $tenant): ?TenantRole
    {
        $membership = $this->tenants()
            ->where('tenants.id', $tenant->id)
            ->first();

        if ($membership === null) {
            return null;
        }

        $role = $membership->getAttribute('pivot')->getAttribute('role');

        return $role === null ? null : TenantRole::from((string) $role);
    }

    public function belongsToTenant(Tenant $tenant): bool
    {
        return $this->tenants()->whereKey($tenant->id)->exists();
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }
}
