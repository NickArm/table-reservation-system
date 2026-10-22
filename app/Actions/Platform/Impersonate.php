<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Enums\AuditAction;
use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Signing in as a venue, for support (SPEC §2.1).
 *
 * The most dangerous feature in the application, so it is the most explicit.
 * Somebody rings up about a booking they cannot find; being able to see the
 * dashboard they are describing is the difference between helping in two
 * minutes and a week of screenshots.
 *
 * Four rules, each guarding something that has gone wrong in other systems.
 *
 * **It is logged going in and coming out, with a reason.** Every action taken
 * while impersonating looks, in every other table, exactly like the owner
 * doing it. The audit log is the only thing that can later say otherwise, so
 * the window has to have both ends written down.
 *
 * **Only an owner is impersonated, never a staff member or another admin.**
 * Support questions are owner questions, and an admin who could become
 * another admin could quietly grant themselves something.
 *
 * **The way back is kept in the session, not inferred.** `impersonator_id` is
 * what the banner reads and what `EnsureSuperAdmin` checks, so an impersonated
 * session cannot reach the platform screens while it is pretending to be
 * somebody — one state at a time.
 *
 * **The session id is regenerated at both ends**, so neither identity can be
 * replayed with a cookie captured during the other.
 */
final readonly class Impersonate
{
    public function __construct(
        private Session $session,
        private RecordAuditEntry $audit,
    ) {}

    public function start(User $admin, Tenant $tenant, string $reason): User
    {
        if (! $admin->isSuperAdmin()) {
            throw new RuntimeException('Only a platform admin may do this.');
        }

        $owner = $this->ownerOf($tenant);

        if ($owner === null) {
            throw new RuntimeException(
                'That account has no owner to sign in as.'
            );
        }

        if ($owner->isSuperAdmin()) {
            throw new RuntimeException(
                'A platform admin cannot be impersonated.'
            );
        }

        $this->audit->handle(
            AuditAction::ImpersonationStarted,
            $admin->name.' signed in as '.$tenant->name.'.',
            actor: $admin,
            tenant: $tenant,
            reason: $reason,
            context: ['as_user_id' => $owner->getKey(), 'as_email' => $owner->email],
        );

        Auth::login($owner);
        $this->session->regenerate();

        // Written after the login, which regenerating would otherwise wipe.
        $this->session->put('impersonator_id', $admin->getKey());

        return $owner;
    }

    /**
     * Back to being ourselves.
     */
    public function stop(): ?User
    {
        $adminId = $this->session->pull('impersonator_id');

        if ($adminId === null) {
            return null;
        }

        $admin = User::query()->find($adminId);
        $was = Auth::user();

        if (! $admin instanceof User || ! $admin->isSuperAdmin()) {
            /*
             * The session says we were impersonating but the admin is gone or
             * is no longer an admin. Signing out is the only safe answer:
             * leaving them logged in as the venue would be an admin's access
             * silently becoming a venue's, with no way back and no record.
             */
            Auth::logout();
            $this->session->invalidate();

            return null;
        }

        $this->audit->handle(
            AuditAction::ImpersonationEnded,
            $admin->name.' stopped being '.($was?->name ?? 'a venue').'.',
            actor: $admin,
            tenant: $was?->currentTenant(),
        );

        Auth::login($admin);
        $this->session->regenerate();

        return $admin;
    }

    private function ownerOf(Tenant $tenant): ?User
    {
        return $tenant->users()
            ->wherePivot('role', TenantRole::Owner->value)
            ->orderBy('users.id')
            ->first();
    }
}
