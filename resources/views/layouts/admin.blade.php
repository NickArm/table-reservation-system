{{--
    The platform's own screens (SPEC §13.2).

    A separate layout from the dashboard, and not because it needs different
    furniture. The dashboard takes its accent colour from the venue, so an
    admin screen wearing it would look like somebody's account — which is
    exactly the confusion to avoid on the pages where one wrong button
    suspends a paying customer. Same shell, different colour rail, and a
    badge that says which side of the fence this is.
--}}
@extends('layouts.base', ['accent' => '#4f46e5'])

@php
    $railIcons = [
        'home' => 'M4 10.5L12 4l8 6.5V20h-5.5v-5h-5v5H4z',
        'building' => 'M4.5 20V5.5h9V20 M13.5 10h6v10 M7.5 9h3 M7.5 13h3 M16 13.5h1 M16 17h1',
        'tag' => 'M4.5 11.5V5h6.5l8 8-6.5 6.5-8-8z M8.2 8.2h.01',
        'card' => 'M3.5 6.5h17v11h-17z M3.5 10h17',
        'pulse' => 'M3.5 12.5h4l2.5-6 4 12 2.5-6h4',
        'doc' => 'M6 3.5h8l4 4v13H6z M14 3.5v4h4',
        'history' => 'M12 7.5v5l3 1.8 M4.8 12a7.2 7.2 0 107.2-7.2A7.2 7.2 0 006 7.4 M6 4.5V8h3.5',
    ];

    $railGroups = [
        'Overview' => [
            ['label' => 'Dashboard', 'icon' => 'home', 'route' => 'admin.overview', 'active' => 'admin.overview'],
        ],
        'Accounts' => [
            ['label' => 'Venues', 'icon' => 'building', 'route' => 'admin.tenants.index', 'active' => 'admin.tenants.*'],
            ['label' => 'Plans', 'icon' => 'tag', 'route' => 'admin.plans.index', 'active' => 'admin.plans.*'],
        ],
        'Money' => [
            ['label' => 'Subscriptions', 'icon' => 'card', 'route' => 'admin.subscriptions', 'active' => 'admin.subscriptions'],
        ],
        'Platform' => [
            ['label' => 'System health', 'icon' => 'pulse', 'route' => 'admin.system', 'active' => 'admin.system'],
            ['label' => 'Legal pages', 'icon' => 'doc', 'route' => 'admin.legal.index', 'active' => 'admin.legal.*'],
            ['label' => 'Activity log', 'icon' => 'history', 'route' => 'admin.audit', 'active' => 'admin.audit'],
        ],
    ];
@endphp

@section('body')
    <div class="app">
        <aside class="rail rail--admin">
            <div class="rail__head">
                <a class="rail__brand" href="{{ route('admin.overview') }}">
                    <svg width="22" height="22" viewBox="0 0 30 30" fill="none" aria-hidden="true">
                        <circle cx="15" cy="12" r="5.5" stroke="currentColor" stroke-width="1.9"></circle>
                        <path d="M3 22c2.4 0 2.4-2 4.8-2s2.4 2 4.8 2 2.4-2 4.8-2 2.4 2 4.8 2 2.4-2 4.8-2"
                              stroke="currentColor" stroke-width="1.9" stroke-linecap="round"></path>
                    </svg>
                    {{ config('app.name') }}
                </a>

                <span class="rail__badge">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12 3.5l7 2.8v5.4c0 4-3 7.2-7 8.8-4-1.6-7-4.8-7-8.8V6.3z"
                              stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"></path>
                    </svg>
                    PLATFORM ADMIN
                </span>
            </div>

            <nav class="rail__nav" aria-label="Platform">
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
                    {{ config('app.name') }}
                    <small>{{ now()->format('l j F') }} · signed in as {{ auth()->user()?->name }}</small>
                </p>
            </div>

            <main class="shell">
                @if (session('status'))
                    <div class="notice" style="margin-bottom:24px">{{ session('status') }}</div>
                @endif

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
