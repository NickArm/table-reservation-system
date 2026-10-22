<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Tenants\AcceptPlatformTerms;
use App\Actions\Tenants\RegisterVenueAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class RegisterController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(
        RegisterRequest $request,
        RegisterVenueAccount $register,
        AcceptPlatformTerms $terms,
    ): RedirectResponse {
        $user = $register->handle(
            name: (string) $request->string('name'),
            email: (string) $request->string('email'),
            password: (string) $request->string('password'),
            venueName: (string) $request->string('venue_name'),
        );

        /*
         * Which version they ticked the box for, when, and from where
         * (SPEC §12.2). Recorded here rather than inside the registration
         * action because it is about the request — the IP is only available
         * at this layer — and because an account created from a console
         * command has agreed to nothing.
         */
        $tenant = $user->currentTenant();

        if ($tenant !== null) {
            $terms->handle($tenant, $request->ip());
        }

        event(new Registered($user));

        Auth::login($user);

        return redirect()->route('verification.notice');
    }
}
