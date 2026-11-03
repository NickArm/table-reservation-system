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
            'last_login_at' => 'immutable_datetime',
            'invitation_sent_at' => 'immutable_datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * Has this person been invited and not yet arrived?
     *
     * An invitation that has run out is not open: the token is still on the
     * row — nothing sweeps it — but it will be refused at the door, and a
     * screen that called it open would be telling the operator to wait for
     * somebody who cannot get in.
     */
    public function hasOpenInvitation(): bool
    {
        return $this->invitation_token !== null && ! $this->invitationHasExpired();
    }

    public function invitationHasExpired(): bool
    {
        if ($this->invitation_sent_at === null) {
            return true;
        }

        return $this->invitation_sent_at
            ->addDays((int) config('tenancy.invitation_days'))
            ->isPast();
    }

    /**
     * Where the invitation points.
     *
     * Built from the token rather than signed, because it is handed to a
     * person: it gets pasted into a message, read off a screen and typed
     * wrong, and a signature would make it longer without making it safer —
     * the token is already 64 random characters and single-use.
     */
    public function invitationUrl(): ?string
    {
        return $this->invitation_token === null
            ? null
            : route('invitation.show', ['token' => $this->invitation_token]);
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
