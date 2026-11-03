<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Platform\InviteUser;
use App\Actions\Platform\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeMemberRoleRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

/**
 * The people on one account, and the three things support actually does to
 * them (SPEC §13.2).
 *
 * Everything here is scoped to a tenant in the route, not just for tidiness:
 * a user can belong to several accounts, so "change this person's role" is
 * meaningless without saying which account's role — and a controller that
 * took a bare user id would eventually change the wrong one.
 *
 * What is deliberately **not** here: setting somebody's password. Support
 * that can type a customer's password is support that knows it, and from
 * then on every action from that account is arguable. The two doors are a
 * password reset and an invitation, and both end with the owner choosing it.
 */
final class AccountPeopleController extends Controller
{
    /**
     * "I never got the email."
     *
     * Sends the standard reset, the same one the login screen sends, so there
     * is one flow to go wrong rather than two.
     */
    public function resetPassword(Tenant $tenant, User $user): RedirectResponse
    {
        if (! $user->belongsToTenant($tenant)) {
            abort(404);
        }

        $status = Password::sendResetLink(['email' => $user->email]);

        return back()->with(
            $status === Password::RESET_LINK_SENT ? 'status' : 'error',
            $status === Password::RESET_LINK_SENT
                ? 'A reset link is on its way to '.$user->email.'.'
                : 'That reset could not be sent. Try again in a minute.',
        );
    }

    /**
     * "It says I have to verify my email and nothing arrived."
     */
    public function resendVerification(Tenant $tenant, User $user): RedirectResponse
    {
        if (! $user->belongsToTenant($tenant)) {
            abort(404);
        }

        if ($user->hasVerifiedEmail()) {
            return back()->with('status', 'That address is already verified.');
        }

        $user->sendEmailVerificationNotification();

        return back()->with('status', 'Verification sent again to '.$user->email.'.');
    }

    /**
     * A fresh invitation, which kills the previous link.
     *
     * The new link comes back on the screen as well as in the email, because
     * the reason somebody is being re-invited is usually that the email is
     * not arriving.
     */
    public function invite(
        Request $request,
        Tenant $tenant,
        User $user,
        InviteUser $invitations,
        RecordAuditEntry $audit,
    ): RedirectResponse {
        if (! $user->belongsToTenant($tenant)) {
            abort(404);
        }

        $url = $invitations->handle($user, $tenant);

        $audit->handle(
            AuditAction::InvitationSent,
            'Invitation sent again to '.$user->email.'.',
            $request->user(),
            $tenant,
        );

        return back()
            ->with('status', 'Invitation sent to '.$user->email.'.')
            ->with('invitation', $url);
    }

    /**
     * Owner or staff, on this account.
     *
     * The last owner cannot be demoted. An account whose only owner is staff
     * is an account where nobody can reach the payment settings or the
     * subscription — and the only way out of it is this screen, which is not
     * a trap worth building.
     */
    public function changeRole(
        ChangeMemberRoleRequest $request,
        Tenant $tenant,
        User $user,
        RecordAuditEntry $audit,
    ): RedirectResponse {
        if (! $user->belongsToTenant($tenant)) {
            abort(404);
        }

        $role = TenantRole::from($request->string('role')->toString());
        $was = $user->roleIn($tenant);

        if ($was === $role) {
            return back();
        }

        if ($was === TenantRole::Owner && $role !== TenantRole::Owner && $this->ownerCount($tenant) <= 1) {
            return back()->with(
                'error',
                'That is the only owner on the account. Make somebody else an owner first.'
            );
        }

        $tenant->users()->updateExistingPivot($user->getKey(), ['role' => $role->value]);

        $audit->handle(
            AuditAction::MemberRoleChanged,
            $user->email.' is now '.$role->label().' on '.$tenant->name.'.',
            $request->user(),
            $tenant,
        );

        return back()->with('status', $user->name.' is now '.strtolower($role->label()).'.');
    }

    private function ownerCount(Tenant $tenant): int
    {
        return $tenant->users()
            ->wherePivot('role', TenantRole::Owner->value)
            ->count();
    }
}
