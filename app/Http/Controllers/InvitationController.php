<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Platform\InviteUser;
use App\Http\Requests\AcceptInvitationRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Where an invited owner chooses their password and gets in.
 *
 * The account already exists and is already on a plan; this is the one thing
 * only they can do. Open to guests on purpose — somebody arriving here has no
 * way of being signed in yet.
 *
 * An expired or spent token is answered with the same page every time, and
 * never with "that invitation has already been used by somebody": whoever is
 * holding a dead link is told to ask for a new one, which is true and gives
 * away nothing about whether the address exists.
 */
final class InvitationController extends Controller
{
    public function show(string $token): View
    {
        $user = $this->find($token);

        return view('auth.invitation', [
            'token' => $token,
            'user' => $user,
            'dead' => $user === null,
        ]);
    }

    public function store(AcceptInvitationRequest $request, string $token, InviteUser $invitations): RedirectResponse
    {
        $user = $this->find($token);

        if ($user === null) {
            return redirect()
                ->route('invitation.show', ['token' => $token])
                ->with('error', 'That invitation is no longer good. Ask for another.');
        }

        $invitations->accept($user, $request->string('password')->toString());

        /*
         * Straight in rather than back to the login screen. They have just
         * proved the address and just chosen the password; asking them to
         * type both again is a step that exists only to be a step.
         */
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard.today');
    }

    /**
     * The user this token belongs to, if it is still good.
     *
     * Expiry is checked here rather than in the query, so that there is one
     * rule about what "still good" means and it lives on the model.
     */
    private function find(string $token): ?User
    {
        $user = User::query()->where('invitation_token', $token)->first();

        if ($user === null || $user->invitationHasExpired()) {
            return null;
        }

        return $user;
    }
}
