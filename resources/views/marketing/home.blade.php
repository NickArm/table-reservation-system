@extends('layouts.base')

@section('title', config('app.name'))
@section('description', 'Online reservations for beach bars and restaurants: sunbeds, tables and prepayment.')

@section('body')
    <main class="shell" style="max-width:720px;padding-block:80px">
        <h1 style="margin:0 0 12px;font-size:var(--step-3);letter-spacing:-0.025em;text-wrap:balance">
            Reservations for beach bars and restaurants
        </h1>

        <p style="margin:0 0 32px;font-size:var(--step-1);color:var(--ink-2)">
            Sunbeds and tables, prepaid, on a page your guests can open from the beach.
        </p>

        <div style="display:flex;flex-wrap:wrap;gap:12px">
            <a class="btn" href="{{ route('register') }}">Start free trial</a>
            <a class="btn btn--ghost" href="{{ route('login') }}">Sign in</a>
        </div>

        <p class="muted" style="margin-top:20px">
            {{ config('tenancy.trial_days') }} days free. No card needed.
        </p>
    </main>
@endsection
