@extends('layouts.base', ['accent' => $venue?->accent_color ?? '#0e6e6e'])

@section('body')
    <header class="topbar">
        <div class="topbar__inner">
            <strong style="flex:none">{{ $tenant->name }}</strong>

            <nav class="nav" aria-label="Main">
                <a href="{{ route('dashboard.today') }}"
                   @if (request()->routeIs('dashboard.today')) aria-current="page" @endif>Today</a>
                <a href="{{ route('dashboard.booking-types.index') }}"
                   @if (request()->routeIs('dashboard.booking-types.*')) aria-current="page" @endif>Setup</a>
                <a href="{{ route('dashboard.payments.edit') }}"
                   @if (request()->routeIs('dashboard.payments.*')) aria-current="page" @endif>Payments</a>
                <a href="{{ route('dashboard.notifications.edit') }}"
                   @if (request()->routeIs('dashboard.notifications.*')) aria-current="page" @endif>Alerts</a>
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
