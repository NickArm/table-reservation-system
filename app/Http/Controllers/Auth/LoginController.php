<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        /*
         * A platform admin belongs to no venue, so the dashboard would refuse
         * them at the door — `IdentifyTenantFromUser` has nothing to bind and
         * answers 403. One line, and it is the difference between the admin
         * area existing and being unreachable (SPEC §13.2).
         */
        $home = $request->user()?->isSuperAdmin()
            ? route('admin.overview')
            : route('dashboard.today');

        return redirect()->intended($home);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
