<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

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

    public function store(RegisterRequest $request, RegisterVenueAccount $register): RedirectResponse
    {
        $user = $register->handle(
            name: (string) $request->string('name'),
            email: (string) $request->string('email'),
            password: (string) $request->string('password'),
            venueName: (string) $request->string('venue_name'),
        );

        event(new Registered($user));

        Auth::login($user);

        return redirect()->route('verification.notice');
    }
}
