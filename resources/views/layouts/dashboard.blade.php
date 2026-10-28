@extends('layouts.base', ['accent' => $venue?->accent_color ?? '#0e6e6e'])

@php
    /*
     * The rail (SPEC §1.1, §27).
     *
     * Grouped rather than listed. Thirteen equal items in one column is a
     * list an owner reads every time instead of remembering, so the two
     * things touched during service sit alone at the top and everything
     * that is set once and forgotten is below a heading that says so.
     *
     * Only routes that exist appear here. A link to a screen we have not
     * built yet is worse than no link: it teaches the owner that tapping
     * things in this app sometimes does nothing.
     */
    $railIcons = [
        'today' => 'M4.5 6.5h15v13h-15z M8 4v4.4 M16 4v4.4 M4.5 11h15',
        'calendar' => 'M12 7.2v5l3.2 2 M12 3.8a8.2 8.2 0 100 16.4 8.2 8.2 0 000-16.4z',
        'list' => 'M8.5 7h11 M8.5 12h11 M8.5 17h11 M4.9 7h.01 M4.9 12h.01 M4.9 17h.01',
        'guests' => 'M10 11.4a3.4 3.4 0 100-6.8 3.4 3.4 0 000 6.8z M4 19.6c0-3.3 2.7-6 6-6s6 2.7 6 6 M16.6 5.7a3.4 3.4 0 010 5.5 M18.2 14.2a6 6 0 012.8 5',
        'grid' => 'M4 5h6.2v6.2H4z M13.8 5H20v6.2h-6.2z M4 13.8h6.2V20H4z M13.8 13.8H20V20h-6.2z',
        'pin' => 'M12 21s6.5-5.6 6.5-10.3A6.5 6.5 0 005.5 10.7C5.5 15.4 12 21 12 21z M12 12.4a2.1 2.1 0 100-4.2 2.1 2.1 0 000 4.2z',
        'paint' => 'M5 19.5h14 M7.5 15.5l8-8 3 3-8 8h-3z M14 6l1.5-1.5 3 3L17 9',
        'card' => 'M3.5 6.5h17v11h-17z M3.5 10h17',
        'bell' => 'M6.5 10a5.5 5.5 0 0111 0c0 4 1.5 5.5 1.5 5.5H5s1.5-1.5 1.5-5.5z M10 18.5a2.2 2.2 0 004 0',
        'code' => 'M9 7.5L4.5 12 9 16.5 M15 7.5l4.5 4.5L15 16.5',
        'gear' => 'M12 15.2a3.2 3.2 0 100-6.4 3.2 3.2 0 000 6.4z M12 3.6v2.2 M12 18.2v2.2 M20.4 12h-2.2 M5.8 12H3.6 M17.9 6.1l-1.6 1.6 M7.7 16.3L6.1 17.9 M17.9 17.9l-1.6-1.6 M7.7 7.7L6.1 6.1',
        'shield' => 'M12 3.5l7 2.8v5.4c0 4-3 7.2-7 8.8-4-1.6-7-4.8-7-8.8V6.3z M9 12l2.2 2.2L15.5 10',
        'receipt' => 'M6 3.5h12v17l-2-1.5-2 1.5-2-1.5-2 1.5-2-1.5-2 1.5z M9 8h6 M9 12h6',
        'terminal' => 'M4.5 5.5h15v13h-15z M8 10l2.5 2.5L8 15 M13 15h3.5',
    ];

    $railGroups = [
        'Service' => [
            ['label' => 'Today', 'icon' => 'today', 'route' => 'dashboard.today', 'active' => 'dashboard.today'],
            ['label' => 'Calendar', 'icon' => 'calendar', 'route' => 'dashboard.calendar', 'active' => 'dashboard.calendar'],
            ['label' => 'Bookings', 'icon' => 'list', 'route' => 'dashboard.reservations.index', 'active' => 'dashboard.reservations.*'],
        ],
        'Guests' => [
            ['label' => 'Guests', 'icon' => 'guests', 'route' => 'dashboard.guests.index', 'active' => 'dashboard.guests.*'],
        ],
        'Set up' => [
            ['label' => 'Tables & areas', 'icon' => 'grid', 'route' => 'dashboard.booking-types.index', 'active' => 'dashboard.booking-types.*'],
            ['label' => 'Venue', 'icon' => 'pin', 'route' => 'dashboard.profile.edit', 'active' => 'dashboard.profile.*'],
            ['label' => 'Appearance', 'icon' => 'paint', 'route' => 'dashboard.branding.edit', 'active' => 'dashboard.branding.*'],
            ['label' => 'Payments', 'icon' => 'card', 'route' => 'dashboard.payments.edit', 'active' => 'dashboard.payments.*'],
            ['label' => 'Alerts', 'icon' => 'bell', 'route' => 'dashboard.notifications.edit', 'active' => 'dashboard.notifications.edit'],
            ['label' => 'Widget', 'icon' => 'code', 'route' => 'dashboard.widget', 'active' => 'dashboard.widget'],
        ],
        'Account' => [
            ['label' => 'Settings', 'icon' => 'gear', 'route' => 'dashboard.settings', 'active' => 'dashboard.settings'],
            ['label' => 'Legal', 'icon' => 'shield', 'route' => 'dashboard.legal.edit', 'active' => 'dashboard.legal.*'],
            ['label' => 'Billing', 'icon' => 'receipt', 'route' => 'dashboard.billing', 'active' => 'dashboard.billing'],
            ['label' => 'Developer', 'icon' => 'terminal', 'route' => 'dashboard.developer', 'active' => 'dashboard.developer*'],
        ],
    ];

    $initials = collect(preg_split('/\s+/', trim((string) $tenant->name)))
        ->filter()
        ->take(2)
        ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
        ->implode('');

    $unread = ($bell ?? collect())->count();
@endphp

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

    <div class="app">
        <aside class="rail">
            <div class="rail__head">
                <a class="rail__brand" href="{{ route('dashboard.today') }}">
                    <svg width="22" height="22" viewBox="0 0 30 30" fill="none" aria-hidden="true">
                        <circle cx="15" cy="12" r="5.5" stroke="currentColor" stroke-width="1.9"></circle>
                        <path d="M3 22c2.4 0 2.4-2 4.8-2s2.4 2 4.8 2 2.4-2 4.8-2 2.4 2 4.8 2 2.4-2 4.8-2"
                              stroke="currentColor" stroke-width="1.9" stroke-linecap="round"></path>
                    </svg>
                    {{ config('app.name') }}
                </a>

                <a class="rail__venue" href="{{ route('public.booking', $tenant) }}" target="_blank" rel="noopener">
                    <span class="rail__initials" aria-hidden="true">{{ $initials }}</span>
                    <span style="flex:1 1 auto">
                        {{ $tenant->name }}
                        <small>See the booking page</small>
                    </span>
                </a>
            </div>

            <nav class="rail__nav" aria-label="Main">
                @foreach ($railGroups as $groupLabel => $items)
                    <p class="rail__group">{{ $groupLabel }}</p>

                    @foreach ($items as $item)
                        <a class="rail__link"
                           href="{{ route($item['route']) }}"
                           @if (request()->routeIs($item['active'])) aria-current="page" @endif>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="{{ $railIcons[$item['icon']] }}" stroke="currentColor" stroke-width="1.7"
                                      stroke-linecap="round" stroke-linejoin="round"></path>
                            </svg>
                            <span style="flex:1 1 auto">{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                @endforeach
            </nav>

            <div class="rail__foot">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="rail__link" type="submit" style="width:100%;border:0;background:none;cursor:pointer">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M14.5 7.5V5.5h-9v13h9v-2 M10 12h9.5 M16.5 8.5l3.5 3.5-3.5 3.5"
                                  stroke="currentColor" stroke-width="1.7" stroke-linecap="round"
                                  stroke-linejoin="round"></path>
                        </svg>
                        <span style="flex:1 1 auto;text-align:left">Sign out</span>
                    </button>
                </form>
            </div>
        </aside>

        <div class="app__main">
            <div class="cmdbar">
                <p class="cmdbar__title">
                    {{ $tenant->name }}
                    <small>{{ now($venue?->timezone ?? config('app.timezone'))->format('l j F') }}</small>
                </p>

                <a class="btn" href="{{ route('dashboard.bookings.create') }}"
                   style="min-height:38px;padding:0 16px;font-size:var(--step--1)">New booking</a>

                {{--
                    The bell (SPEC §10.2). A <details> element, so it opens and
                    closes without a line of JavaScript — the dashboard gets its
                    interactivity in M7 and this should not wait for it.
                --}}
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
            </div>

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
        </div>
    </div>
@endsection
