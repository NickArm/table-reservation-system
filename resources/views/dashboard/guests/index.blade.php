{{--
    The venue's guest list (SPEC §28.3).

    Sorted by last visit, because the question is almost always about somebody
    recent. The two filters that matter are the two an owner acts on: people who
    come back, and people who do not turn up.
--}}
@extends('layouts.dashboard')

@section('title', 'Guests — '.$tenant->name)

@section('main')
    <div class="page-head">
        <div>
            <h1>Guests</h1>
            <p>Everyone who has ever booked with you.</p>
        </div>

        @if ($totals['all'] > 0)
            <a class="btn btn--ghost" href="{{ route('dashboard.guests.export', request()->only('q')) }}">
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
        <form method="GET" action="{{ route('dashboard.guests.index') }}"
              class="card filters" style="margin-bottom:16px">
            <div style="flex:1 1 240px">
                <label class="label" for="q">Search</label>
                <input class="field" type="search" id="q" name="q" value="{{ $search }}"
                       placeholder="Name, email or phone">
            </div>

            <div>
                <label class="label" for="show">Show</label>
                <select class="field" id="show" name="show">
                    <option value="all" @selected($filter === 'all')>
                        Everyone ({{ $totals['all'] }})
                    </option>
                    <option value="repeat" @selected($filter === 'repeat')>
                        Came back ({{ $totals['repeat'] }})
                    </option>
                    <option value="trouble" @selected($filter === 'trouble')>
                        No-shows ({{ $totals['trouble'] }})
                    </option>
                </select>
            </div>

            <div>
                <label class="label" for="sort">Sort</label>
                <select class="field" id="sort" name="sort">
                    <option value="recent" @selected($sort !== 'bookings')>Last visit</option>
                    <option value="bookings" @selected($sort === 'bookings')>Most bookings</option>
                </select>
            </div>

            <div>
                <button class="btn" type="submit">Apply</button>
            </div>
        </form>

        @if ($guests->isEmpty())
            <div class="empty">
                <p style="margin:0">Nobody matches that.</p>
            </div>
        @else
            <section class="card">
                <div class="guests">
                    @foreach ($guests as $guest)
                        <div class="guest">
                            <span class="guest__name">
                                <strong>
                                    <a href="{{ route('dashboard.guests.show', $guest) }}">{{ $guest->name }}</a>
                                </strong>

                                @if ($guest->warning())
                                    <span class="flag flag--warn">{{ $guest->warning() }}</span>
                                @endif

                                @foreach ($guest->tagList() as $tag)
                                    <span class="flag">{{ $tag }}</span>
                                @endforeach

                                <small>
                                    {{ $guest->email ?? 'no email' }}
                                    @if (filled($guest->phone)) · {{ $guest->phone }} @endif
                                </small>
                            </span>

                            <span class="guest__counts">
                                <span><b>{{ $guest->bookings_count }}</b> bookings</span>
                                <span>{{ \App\Support\Money::format((int) $guest->total_spent_cents) }}</span>
                                <span>{{ $guest->last_seen_at?->format('j M Y') ?? '—' }}</span>
                            </span>
                        </div>
                    @endforeach
                </div>
            </section>

            @include('partials.pagination', ['paginator' => $guests])
        @endif
    @endif
@endsection
