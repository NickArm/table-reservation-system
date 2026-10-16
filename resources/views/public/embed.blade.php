{{--
    The search bar as a page, for the bare-iframe embed (SPEC §9.2).

    No site chrome, no navigation, nothing but the bar: it is going to sit
    inside somebody else's layout, and anything else we draw would fight it.

    Works with JavaScript switched off. The form posts to the booking page
    with `target="_top"`, so pressing Search moves the page the iframe sits in
    — which is what the visitor expects, and what a postMessage handshake
    would only reimplement less reliably.
--}}
@extends('layouts.base', ['accent' => $venue->accent_color])

@section('title', 'Reservations — '.$venue->name)

@push('head')
    <style>
        /*
         * Inline and self-contained. This page is one component, and loading
         * the whole site stylesheet to draw a single bar inside somebody
         * else's page would be both slower and more likely to clash.
         */
        body {
            margin: 0;
            padding: 12px;
            background: transparent;
            font-size: 16px;
        }

        .bar {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 10px;
            padding: 12px;
            border: 1px solid var(--line-strong);
            border-radius: 12px;
            background: var(--bg);
        }

        .bar > div {
            flex: 1 1 130px;
            min-width: 0;
        }

        .bar label {
            display: block;
            margin-bottom: 4px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }

        .bar select,
        .bar input {
            width: 100%;
            min-height: 44px;
            padding: 10px 12px;
            border: 1px solid var(--line-strong);
            border-radius: 8px;
            background: var(--surface);
            color: var(--ink);
            font: inherit;
            font-size: 15px;
        }

        .bar button {
            flex: 0 0 auto;
            min-height: 44px;
            padding: 10px 20px;
            border: 0;
            border-radius: 8px;
            background: var(--accent);
            color: var(--accent-ink);
            font: inherit;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
        }

        .bar p {
            flex: 1 1 100%;
            margin: 0;
            color: var(--muted);
            font-size: 13px;
        }

        /* Most of these are opened on a phone, on a beach. */
        @media (max-width: 520px) {
            .bar {
                flex-direction: column;
                align-items: stretch;
            }

            .bar > div,
            .bar button {
                flex: 1 1 auto;
                width: 100%;
            }
        }
    </style>
@endpush

@section('body')
    <form class="bar" method="GET" action="{{ route('public.search', $tenant) }}" target="_top">
        {{-- Attribution, so an owner can see the widget earning its keep. --}}
        <input type="hidden" name="channel" value="widget">

        @if ($bookingTypes->count() > 1)
            <div>
                <label for="type">What</label>
                <select id="type" name="type">
                    @foreach ($bookingTypes as $option)
                        <option value="{{ $option->slug }}" @selected($bookingType?->slug === $option->slug)>
                            {{ $option->name }}
                        </option>
                    @endforeach
                </select>
            </div>
        @elseif ($bookingType !== null)
            <input type="hidden" name="type" value="{{ $bookingType->slug }}">
        @endif

        <div>
            <label for="people">People</label>
            <select id="people" name="people">
                @for ($n = 1; $n <= 12; $n++)
                    <option value="{{ $n }}" @selected($partySize === $n)>
                        {{ $n === 1 ? '1 person' : $n.' people' }}
                    </option>
                @endfor
            </select>
        </div>

        <div>
            <label for="date">Date</label>
            <input id="date" name="date" type="date"
                   value="{{ $date }}" min="{{ $today }}" max="{{ $maxDate }}">
        </div>

        @if ($times !== [])
            <div>
                <label for="time">Time</label>
                <select id="time" name="time">
                    @foreach ($times as $offer)
                        <option value="{{ $offer->label() }}">
                            {{ $offer->label() }}@if ($offer->fromCents !== null)
                                — from {{ \App\Support\Money::format($offer->fromCents) }}
                            @endif
                        </option>
                    @endforeach
                </select>
            </div>
        @endif

        <button type="submit">Search</button>

        @if ($times === [])
            <p>
                @if ($bookingType === null)
                    This venue is not taking bookings online yet.
                @else
                    Nothing free on {{ \Carbon\CarbonImmutable::parse($date)->format('j M') }}.
                    Pick another date, or search to see what else is open.
                @endif
            </p>
        @endif
    </form>
@endsection
