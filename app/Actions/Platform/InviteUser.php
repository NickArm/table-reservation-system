<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Mail\AccountInvitation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Hands somebody a way into an account they have never signed into.
 *
 * **It never sends a password.** A password in an email is a password that
 * lives for ever in two mailboxes and in whatever backed them up, and the
 * person it belongs to did not choose it. This sends a link that lets them
 * choose one, and the link stops working the moment it is used.
 *
 * The link is also handed back to the caller, which is the point rather than
 * a convenience: half the time the account is being opened during a telephone
 * call, and the operator wants to send it over whatever the customer actually
 * reads. An email that is waiting in a spam folder has not invited anybody.
 */
final readonly class InviteUser
{
    /**
     * @return string the link, so it can be shown and sent by hand
     */
    public function handle(User $user, Tenant $tenant, bool $send = true): string
    {
        /*
         * A fresh token every time, so a re-invitation makes the previous
         * link dead. Somebody who is being invited twice has usually lost
         * the first one, and a loose link into an account is worth more to
         * whoever finds it than it is to them.
         */
        $user->forceFill([
            'invitation_token' => Str::random(64),
            'invitation_sent_at' => now(),
        ])->save();

        $url = (string) $user->invitationUrl();

        if ($send) {
            Mail::to($user->email)->send(new AccountInvitation($user, $tenant, $url));
        }

        return $url;
    }

    /**
     * Spends the invitation.
     *
     * The token is cleared and the address is marked verified in the same
     * write: arriving through a link that was emailed to that address *is*
     * proof of the address, and asking the person to verify an email by
     * clicking a second link sent to the same inbox proves nothing and loses
     * a third of them.
     */
    public function accept(User $user, string $password): void
    {
        $user->forceFill([
            'password' => $password,
            'invitation_token' => null,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();
    }
}
