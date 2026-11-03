<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Writes the moment somebody got in.
 *
 * The one fact that answers the commonest support call — "it will not let me
 * in" — before anything is guessed at: an address that has never signed in is
 * an invitation that never arrived, and one that signed in this morning is a
 * password the caller has forgotten since.
 *
 * Deliberately not queued and deliberately not an audit entry. It is one
 * column on a row that is already loaded, so a job would cost more than the
 * write; and the audit log is for things a person did *to* an account, which
 * a sign-in is not. It also must never fail a login: a timestamp is worth
 * less than the session it would take down with it.
 */
final class RecordSignIn
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();
    }
}
