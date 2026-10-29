{{--
    Everything an owner sets once (SPEC §1.1).

    The rail holds what is touched during service. This holds the rest, in
    groups, with each card saying what it is set to now — because the question
    somebody arrives with is almost never "where is that screen" but "what did
    I put there". Nothing here is a menu entry pointing at a blank page: every
    line is read from what is actually configured.
--}}
@extends('layouts.dashboard')

@section('title', 'Setup — '.$tenant->name)

@section('main')
    <div class="page-head">
        <div>
            <h1>Setup</h1>
            <p class="muted">Everything about how this place takes bookings.</p>
        </div>

        <a class="btn btn--ghost" href="{{ route('public.booking', $tenant) }}" target="_blank" rel="noopener">
            See the booking page
        </a>
    </div>

    @foreach ($groups as $group)
        <section aria-label="{{ $group['title'] }}" style="margin-bottom:18px">
            <div class="card__head" style="margin-bottom:8px">
                <h2 class="h2">{{ $group['title'] }}</h2>
                <span class="muted" style="font-size:var(--step--2)">{{ $group['hint'] }}</span>
            </div>

            <div class="setup">
                @foreach ($group['cards'] as $card)
                    <a class="setup__card" href="{{ $card['href'] }}">
                        <span class="setup__head">
                            <strong>{{ $card['title'] }}</strong>
                            @if ($card['chip'] !== null)
                                <span class="pill {{ $chipSkin[$card['state']] ?? '' }}">{{ $card['chip'] }}</span>
                            @endif
                        </span>
                        <span class="setup__body">{{ $card['body'] }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endforeach

    <p class="muted" style="margin:0;font-size:var(--step--2)">
        Changing any of this takes effect the moment you save it. Nothing here
        touches a booking somebody has already made.
    </p>
@endsection
