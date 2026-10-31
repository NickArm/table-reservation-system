{{--
    The venue's guest list (SPEC §28.3).

    Sorted by last visit, because the question is almost always about somebody
    recent. The two filters that matter are the two an owner acts on: people who
    come back, and people who do not turn up — so both are one tap, with their
    counts on them. A dropdown hides the counts, and the counts are the reason
    an owner opens this screen at all.
--}}
@extends('layouts.dashboard')

@section('title', 'Guests — '.$tenant->name)

@section('main')
    <div class="page-head">
        <div>
            <h1>Guests</h1>
            <p class="muted">
                Everyone who has ever booked with you.
                @if ($totals['repeat'] > 0)
                    <strong>{{ $totals['repeat'] }}</strong> have come back.
                @endif
            </p>
        </div>

        @if ($totals['all'] > 0)
            <a class="btn btn--ghost btn--small" href="{{ route('dashboard.guests.export', request()->only('q')) }}">
                Export CSV
            </a>
        @endif
    </div>

    @if ($totals['all'] === 0)
        <div class="empty">
            <p style="margin:0 0 6px"><strong>Nobody yet.</strong></p>
            <p style="margin:0">
                The list fills itself from every booking — online, through your
                widget, and the ones you take by phone.
            </p>
        </div>
    @else
        <form method="GET" action="{{ route('dashboard.guests.index') }}" class="searchbar">
            @foreach (request()->only(['show', 'sort']) as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endforeach

            <label class="label" for="q"
                   style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">
                Search guests
            </label>
            <input class="field" type="search" id="q" name="q" value="{{ $search }}"
                   placeholder="Name, email or phone">
            <button class="btn" type="submit">Search</button>
        </form>

        {{-- Who, then in what order: one line, with a rule saying they are
             two questions. --}}
        <div class="filterbar">
            <div class="chips">
                @foreach ($segments as $segment)
                    <a class="chip" href="{{ $segment['href'] }}"
                       @if ($segment['on']) aria-current="page" @endif>
                        {{ $segment['label'] }}
                        <span class="chip__count">{{ $segment['count'] }}</span>
                    </a>
                @endforeach
            </div>

            <span class="filterbar__rule" aria-hidden="true"></span>

            <div class="chips">
                @foreach ($sorts as $option)
                    <a class="chip" href="{{ $option['href'] }}"
                       @if ($option['on']) aria-current="page" @endif>{{ $option['label'] }}</a>
                @endforeach
            </div>
        </div>

        @if ($guests->isEmpty())
            <div class="empty">
                <p style="margin:0">Nobody matches that.</p>
            </div>
        @else
            <section class="card" style="padding:0;overflow:hidden">
                <div class="guests">
                    @foreach ($guests as $guest)
                        <a class="guest" href="{{ route('dashboard.guests.show', $guest) }}">
                            <span class="guest__avatar @if ($guest->warning()) guest__avatar--warn @endif"
                                  aria-hidden="true">{{ $guest->initials() }}</span>

                            <span class="guest__name">
                                <strong>
                                    {{ $guest->name }}

                                    {{-- The one thing worth interrupting
                                         somebody for, before the tags. --}}
                                    @if ($guest->warning())
                                        <span class="flag flag--warn">{{ $guest->warning() }}</span>
                                    @elseif ($guest->visitLabel())
                                        <span class="flag">{{ $guest->visitLabel() }}</span>
                                    @endif

                                    @foreach ($guest->tagList() as $tag)
                                        <span class="flag">{{ $tag }}</span>
                                    @endforeach
                                </strong>

                                <small>
                                    {{ $guest->email ?? 'no email' }}
                                    @if (filled($guest->phone)) · {{ $guest->phone }} @endif
                                </small>
                            </span>

                            {{-- Labelled, because three bare numbers in a row
                                 are three numbers somebody has to decode. --}}
                            <span class="guest__counts">
                                <span class="guest__stat">
                                    <b>{{ $guest->bookings_count }}</b>
                                    <small>bookings</small>
                                </span>
                                <span class="guest__stat">
                                    <b>{{ \App\Support\Money::format((int) $guest->total_spent_cents) }}</b>
                                    <small>spent</small>
                                </span>
                                <span class="guest__stat">
                                    <b>{{ $guest->last_seen_at?->format('j M Y') ?? '—' }}</b>
                                    <small>last here</small>
                                </span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>

            @include('partials.pagination', ['paginator' => $guests])
        @endif
    @endif
@endsection
