@extends('layouts.base', ['accent' => $venue?->accent_color ?? '#0e6e6e'])

@section('body')
    {{--
        A platform admin inside somebody's account (SPEC §2.1).

        Loud and at the very top on purpose. Every action taken from here
        looks exactly like the owner doing it in every table except the audit
        log, so the one thing that must never happen is forgetting whose
        dashboard this is — and the way out has to be one button, always in
        the same place.
    --}}
    @if (session()->has('impersonator_id'))
        <div class="impersonating">
            <span>
                You are signed in as <strong>{{ $tenant->name }}</strong>.
                Anything you do here is theirs.
            </span>

            <form method="POST" action="{{ route('impersonate.stop') }}">
                @csrf
                <button type="submit">Back to the platform</button>
            </form>
        </div>
    @endif

    {{--
        The terms changed and this account has not agreed to the new version
        (SPEC §12.2). Informational rather than alarming — nothing is blocked,
        the account works exactly as before — but it stays until they agree,
        because a notice that can be dismissed is a notice that was.
    --}}
    @if (($termsChange ?? null) !== null)
        <div class="terms-change">
            <span>
                Our <a href="{{ route('legal', 'terms') }}" target="_blank" rel="noopener">terms</a>
                have changed.
                @if (filled($termsChange->change_summary))
                    {{ rtrim($termsChange->change_summary, '.') }}.
                @endif
            </span>

            <form method="POST" action="{{ route('dashboard.terms.accept') }}">
                @csrf
                <button type="submit">I agree</button>
            </form>
        </div>
    @endif

    <header class="topbar">
        <div class="topbar__inner">
            <strong style="flex:none">{{ $tenant->name }}</strong>

            <nav class="nav" aria-label="Main">
                <a href="{{ route('dashboard.today') }}"
                   @if (request()->routeIs('dashboard.today')) aria-current="page" @endif>Today</a>
                <a href="{{ route('dashboard.calendar') }}"
                   @if (request()->routeIs('dashboard.calendar')) aria-current="page" @endif>Calendar</a>
                <a href="{{ route('dashboard.reservations.index') }}"
                   @if (request()->routeIs('dashboard.reservations.index')) aria-current="page" @endif>Bookings</a>
                <a href="{{ route('dashboard.guests.index') }}"
                   @if (request()->routeIs('dashboard.guests.*')) aria-current="page" @endif>Guests</a>
                {{--
                    Everything to the left is daily work; everything to the
                    right is touched once and forgotten (§1.1). The divider is
                    there so the eye does not have to read seven words to find
                    the two it needs.
                --}}
                <span class="nav__sep" aria-hidden="true"></span>

                <a href="{{ route('dashboard.booking-types.index') }}"
                   @if (request()->routeIs('dashboard.booking-types.*')) aria-current="page" @endif>Setup</a>
                <a href="{{ route('dashboard.payments.edit') }}"
                   @if (request()->routeIs('dashboard.payments.*')) aria-current="page" @endif>Payments</a>
                <a href="{{ route('dashboard.notifications.edit') }}"
                   @if (request()->routeIs('dashboard.notifications.*')) aria-current="page" @endif>Alerts</a>

                <a href="{{ route('dashboard.settings') }}"
                   @if (request()->routeIs('dashboard.settings')) aria-current="page" @endif>Settings</a>

                <a href="{{ route('dashboard.legal.edit') }}"
                   @if (request()->routeIs('dashboard.legal.*')) aria-current="page" @endif>Legal</a>

                <a href="{{ route('dashboard.billing') }}"
                   @if (request()->routeIs('dashboard.billing')) aria-current="page" @endif>Billing</a>

                {{-- The snippet for their own site (SPEC §9.4). Earns its
                     place in the bar: it is where most bookings come from. --}}
                <a href="{{ route('dashboard.widget') }}"
                   @if (request()->routeIs('dashboard.widget')) aria-current="page" @endif>Widget</a>

                {{-- Last, and the only item here an owner never needs: this
                     one is for whoever builds their website (SPEC §15.2). --}}
                <a href="{{ route('dashboard.developer') }}"
                   @if (request()->routeIs('dashboard.developer*')) aria-current="page" @endif>Developer</a>
            </nav>

            {{--
                The bell (SPEC §10.2). A <details> element, so it opens and
                closes without a line of JavaScript — the dashboard gets its
                interactivity in M7 and this should not wait for it.
            --}}
            @php $unread = ($bell ?? collect())->count(); @endphp

            <details class="bell" style="flex:none">
                <summary class="bell__trigger" aria-label="Notifications">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                        <path d="M13.7 21a2 2 0 0 1-3.4 0"/>
                    </svg>
                    @if ($unread > 0)
                        <span class="bell__count">{{ $unread > 9 ? '9+' : $unread }}</span>
                    @endif
                </summary>

                <div class="bell__panel">
                    @forelse ($bell ?? collect() as $notification)
                        <a class="bell__item"
                           href="{{ route('dashboard.reservations.show', $notification->data['reservation_uuid'] ?? '') }}">
                            <strong>{{ $notification->data['guest'] ?? 'Booking' }}</strong>
                            <small>{{ $notification->data['headline'] ?? '' }}</small>
                        </a>
                    @empty
                        <p class="bell__empty">Nothing new.</p>
                    @endforelse

                    @if ($unread > 0)
                        <form method="POST" action="{{ route('dashboard.notifications.read') }}">
                            @csrf
                            <button class="bell__clear" type="submit">Mark all as read</button>
                        </form>
                    @endif
                </div>
            </details>

            <form method="POST" action="{{ route('logout') }}" style="flex:none">
                @csrf
                <button type="submit"
                        style="border:0;background:none;color:var(--muted);font:inherit;font-size:var(--step--1);cursor:pointer;padding:8px 12px">
                    Sign out
                </button>
            </form>
        </div>
    </header>

    <main class="shell">
        @if (session('status'))
            <div class="notice" style="margin-bottom:24px">{{ session('status') }}</div>
        @endif

        {{-- A refusal that is nobody's mistake: the account lapsed, or the
             plan does not stretch that far. Not in `errors`, because that
             list is for a form the owner can correct. --}}
        @if (session('error'))
            <div class="notice notice--warn" style="margin-bottom:24px">{{ session('error') }}</div>
        @endif

        @if ($errors->any())
            <ul class="errors" style="margin-bottom:24px">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        @yield('main')
    </main>
@endsection
