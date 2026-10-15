{{--
    The shape of the week (SPEC §13.1).

    Seven columns and a fill level, not a grid of hours. A beach where
    everything is booked for the whole day has nothing to say in an hour grid,
    and the question this screen answers is the Monday one: which days are
    heavy, and which are empty enough to leave the back row shut.
--}}
@extends('layouts.dashboard')

@section('title', 'Calendar — '.$tenant->name)

@section('main')
    <div class="page-head">
        <div>
            <h1>Calendar</h1>
            <p class="muted">
                {{ $start->format('j M') }} – {{ $start->addDays(6)->format('j M Y') }}
            </p>
        </div>

        <nav class="row" aria-label="Week">
            <a class="btn btn--ghost" href="{{ route('dashboard.calendar', ['week' => $previous]) }}">
                ← Previous
            </a>
            @if ($start->toDateString() !== $thisWeek)
                <a class="btn btn--ghost" href="{{ route('dashboard.calendar') }}">This week</a>
            @endif
            <a class="btn btn--ghost" href="{{ route('dashboard.calendar', ['week' => $next]) }}">
                Next →
            </a>
        </nav>
    </div>

    @if ($bookingTypes->isEmpty())
        <div class="empty">
            <p style="margin:0 0 6px"><strong>Nothing to show yet.</strong></p>
            <p style="margin:0">
                <a href="{{ route('dashboard.booking-types.index') }}">Set up your tables or sunbeds</a>
                first.
            </p>
        </div>
    @else
        <div class="week">
            @foreach ($days as $day)
                <section class="week__day @if ($day['isToday']) week__day--today @endif @if ($day['isPast']) week__day--past @endif">
                    <header class="week__head">
                        <strong>{{ $day['date']->format('D') }}</strong>
                        <span class="muted">{{ $day['date']->format('j M') }}</span>
                    </header>

                    @php
                        $count = $day['bookings']->count();
                        $fill = $capacity > 0 ? min(100, (int) round($count / $capacity * 100)) : 0;
                    @endphp

                    <div class="week__meter" title="{{ $count }} of {{ $capacity }} spots booked">
                        <span style="width:{{ $fill }}%"></span>
                    </div>

                    <p class="week__totals">
                        @if ($count === 0)
                            <span class="muted">Nothing booked</span>
                        @else
                            <b>{{ $count }}</b>
                            {{ \Illuminate\Support\Str::plural('booking', $count) }}
                            · {{ $day['guests'] }}p
                            @if ($day['money'] > 0)
                                <br><span class="muted">{{ \App\Support\Money::format($day['money']) }}</span>
                            @endif
                        @endif
                    </p>

                    <div class="week__list">
                        @foreach ($day['bookings'] as $reservation)
                            <a class="week__item"
                               href="{{ route('dashboard.reservations.show', $reservation) }}">
                                <time>{{ $reservation->startsAtLocal($timezone)->format('H:i') }}</time>
                                <span>
                                    {{ $reservation->resource_snapshot['label'] ?? '—' }}
                                    <small>{{ $reservation->customer_name }} · {{ $reservation->party_size }}p</small>
                                </span>
                            </a>
                        @endforeach
                    </div>

                    @unless ($day['isPast'])
                        <a class="week__add" href="{{ route('dashboard.bookings.create', ['date' => $day['key']]) }}">
                            Add a booking
                        </a>
                    @endunless
                </section>
            @endforeach
        </div>

        <p class="muted" style="margin-top:20px;font-size:var(--step--1)">
            The bar is how many of your {{ $capacity }} open
            {{ \Illuminate\Support\Str::plural('spot', $capacity) }} are booked that day.
            Cancelled bookings are not counted.
        </p>
    @endif
@endsection
