{{--
    The platform's own screens (SPEC §13.2).

    A separate layout from the dashboard, and not because it needs different
    furniture. The dashboard takes its accent colour from the venue, so an
    admin screen wearing it would look like somebody's account — which is
    exactly the confusion to avoid on the pages where one wrong button
    suspends a paying customer. This one is deliberately plain and a different
    colour, so it is never mistaken for a venue's dashboard or the other way
    round.
--}}
@extends('layouts.base', ['accent' => '#1f2937'])

@section('body')
    <header class="topbar">
        <div class="topbar__inner">
            <strong style="flex:none">{{ config('app.name') }}
                <span class="pill pill--muted">platform</span>
            </strong>

            <nav class="nav" aria-label="Platform">
                <a href="{{ route('admin.overview') }}"
                   @if (request()->routeIs('admin.overview')) aria-current="page" @endif>Overview</a>
                <a href="{{ route('admin.tenants.index') }}"
                   @if (request()->routeIs('admin.tenants.*')) aria-current="page" @endif>Accounts</a>
                <a href="{{ route('admin.plans.index') }}"
                   @if (request()->routeIs('admin.plans.*')) aria-current="page" @endif>Plans</a>
                <a href="{{ route('admin.subscriptions') }}"
                   @if (request()->routeIs('admin.subscriptions')) aria-current="page" @endif>Money</a>
                <a href="{{ route('admin.system') }}"
                   @if (request()->routeIs('admin.system')) aria-current="page" @endif>System</a>
                <a href="{{ route('admin.legal.index') }}"
                   @if (request()->routeIs('admin.legal.*')) aria-current="page" @endif>Legal</a>
                <a href="{{ route('admin.audit') }}"
                   @if (request()->routeIs('admin.audit')) aria-current="page" @endif>Log</a>
            </nav>

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
