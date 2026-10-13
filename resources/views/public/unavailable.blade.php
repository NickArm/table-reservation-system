@extends('layouts.base', ['accent' => $venue?->accent_color ?? '#0e6e6e'])

@section('title', ($venue?->name ?? $tenant->name).' — Reservations')

@section('body')
    <main class="shell shell--narrow" style="padding-block:80px">
        <h1 style="margin:0 0 8px;font-size:var(--step-2);letter-spacing:-0.02em">
            {{ $venue?->name ?? $tenant->name }}
        </h1>

        <p style="margin:0 0 20px">Online reservations are temporarily unavailable.</p>

        @if (filled($venue?->phone))
            <p class="muted" style="margin:0">
                Please call <a href="tel:{{ $venue->phone }}">{{ $venue->phone }}</a> to book.
            </p>
        @endif
    </main>
@endsection
