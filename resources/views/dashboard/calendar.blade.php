{{--
    The shape of the week (SPEC §13.1).

    Seven columns and a fill level, not a grid of hours. A beach where
    everything is booked for the whole day has nothing to say in an hour grid,
    and the question this screen answers is the Monday one: which days are
    heavy, and which are empty enough to leave the back row shut.

    Underneath sits the day somebody is actually looking at, because the
    question after "is Saturday busy" is always "busy when" — and a day that
    is full at nine and empty at seven is a staffing decision that a total
    hides.
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

        <nav class="chips" aria-label="Week">
            <a class="chip" href="{{ route('dashboard.calendar', ['week' => $previous]) }}">← Previous</a>
            <a class="chip" href="{{ route('dashboard.calendar') }}"
               @if ($start->toDateString() === $thisWeek) aria-current="page" @endif>This week</a>
            <a class="chip" href="{{ route('dashboard.calendar', ['week' => $next]) }}">Next →</a>
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
        <div class="week" style="margin-bottom:20px">
            @foreach ($days as $day)
                <a class="week__day @if ($day['isToday']) week__day--today @endif @if ($day['isPast']) week__day--past @endif @if ($day['key'] === $selected['key']) week__day--on @endif"
                   href="{{ route('dashboard.calendar', ['week' => $start->toDateString(), 'day' => $day['key']]) }}">
                    <span class="week__head">
                        <strong>{{ $day['date']->format('D') }}</strong>
                        <span class="muted">{{ $day['date']->format('j M') }}</span>
                    </span>

                    {{-- One bar per booking type. A beach bar fills twenty
                         sunbeds and four tables at different speeds, and one
                         bar across both says nothing useful about either. --}}
                    @foreach ($day['types'] as $type)
                        <span class="week__type">
                            <span class="week__typehead">
                                <span class="muted">{{ $type['name'] }}</span>
                                <b>{{ $type['count'] }}<span class="muted">/{{ $type['capacity'] }}</span></b>
                            </span>
                            <span class="week__meter">
                                <span style="width:{{ $type['percent'] }}%"></span>
                            </span>
                        </span>
                    @endforeach

                    <span class="week__totals">
                        @if ($day['bookings']->isEmpty())
                            <span class="muted">Nothing booked</span>
                        @else
                            <b>{{ $day['guests'] }}</b> <span class="muted">guests</span>
                            @if ($day['money'] > 0)
                                · <span class="muted">{{ \App\Support\Money::format($day['money']) }}</span>
                            @endif
                        @endif
                    </span>
                </a>
            @endforeach
        </div>

        <div class="work">
            <div>
                <section class="card" style="padding:0;overflow:hidden">
                    <div class="card__head" style="padding:14px 20px;margin:0;border-bottom:1px solid var(--line)">
                        <h2 class="h2">{{ $selected['date']->format('l j F') }}</h2>

                        @unless ($selected['isPast'])
                            <a class="btn btn--ghost btn--small"
                               href="{{ route('dashboard.bookings.create', ['date' => $selected['key']]) }}">
                                Add a booking
                            </a>
                        @endunless
                    </div>

                    @if ($arrivals !== [])
                        <div class="arrivals">
                            <p class="muted" style="margin:0 0 10px;font-size:var(--step--2)">
                                When they arrive
                            </p>

                            <div class="arrivals__chart">
                                @foreach ($arrivals as $slot)
                                    <span class="arrivals__slot" title="{{ $slot['count'] }} at {{ $slot['label'] }}">
                                        {{-- The number, because the heights are
                                             relative to the busiest half hour:
                                             on a flat day every bar is full. --}}
                                        <span class="arrivals__count">{{ $slot['count'] }}</span>
                                        <span class="arrivals__bar" style="height:{{ max(8, $slot['percent']) }}%"></span>
                                        <small>{{ $slot['label'] }}</small>
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($selected['bookings']->isEmpty())
                        <p class="muted" style="margin:0;padding:18px 20px">
                            Nothing booked that day.
                        </p>
                    @else
                        <ul class="bookings">
                            @foreach ($selected['bookings'] as $reservation)
                                <li class="booking">
                                    <span class="booking__time">
                                        {{ $reservation->startsAtLocal($timezone)->format('H:i') }}
                                        <small>to {{ $reservation->endsAtLocal($timezone)->format('H:i') }}</small>
                                    </span>

                                    <span class="booking__table">
                                        {{ $reservation->resource_snapshot['label'] ?? '—' }}
                                    </span>

                                    <span class="booking__who">
                                        <strong>
                                            <a href="{{ route('dashboard.reservations.show', $reservation) }}"
                                               style="color:inherit">{{ $reservation->customer_name }}</a>
                                        </strong>
                                        <small class="muted">
                                            {{ $reservation->party_size }}
                                            {{ \Illuminate\Support\Str::plural('guest', $reservation->party_size) }}
                                            @if ($areaNames[$reservation->area_id] ?? null)
                                                · {{ $areaNames[$reservation->area_id] }}
                                            @endif
                                        </small>
                                    </span>

                                    <span class="booking__meta">
                                        <span class="pill {{ $statusSkins[$reservation->status->value] ?? '' }}">
                                            {{ $reservation->status->label() }}
                                        </span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>

            <aside class="work__rail">
                <section class="card">
                    <div class="card__head">
                        <h2 class="h2">The week</h2>
                    </div>

                    <div class="needs">
                        <span class="needs__text">
                            <strong>{{ $weekTotals['guests'] }} guests</strong>
                            <small class="muted">
                                Across {{ $weekTotals['bookings'] }}
                                {{ \Illuminate\Support\Str::plural('booking', $weekTotals['bookings']) }}
                            </small>
                        </span>
                    </div>

                    @if ($weekTotals['money'] > 0)
                        <div class="needs">
                            <span class="needs__text">
                                <strong>{{ \App\Support\Money::format($weekTotals['money']) }}</strong>
                                <small class="muted">Booked value, cancellations aside</small>
                            </span>
                        </div>
                    @endif

                    @if ($weekTotals['busiest'] !== null)
                        <div class="needs">
                            <span class="needs__text">
                                <strong>{{ $weekTotals['busiest'] }} is the heavy one</strong>
                                <small class="muted">Worth a second pair of hands</small>
                            </span>
                        </div>
                    @endif
                </section>

                {{-- A quiet day should read as closed rather than as nobody
                     booking: the two look identical on a bar chart. --}}
                <section class="card">
                    <div class="card__head">
                        <h2 class="h2">Closed this week</h2>
                    </div>

                    @forelse ($closures as $closure)
                        <div class="needs">
                            <span class="needs__dot needs__dot--table" aria-hidden="true"></span>
                            <span class="needs__text">
                                <strong>{{ $closure->date_from->format('j M') }}@if ($closure->date_to->ne($closure->date_from)) – {{ $closure->date_to->format('j M') }}@endif</strong>
                                <small class="muted">
                                    {{ $closure->reason ?: 'No reason given' }}
                                    @unless ($closure->isAllDay())
                                        · part of the day
                                    @endunless
                                </small>
                            </span>
                        </div>
                    @empty
                        <p class="muted" style="margin:0;font-size:var(--step--2)">
                            Open every day this week.
                        </p>
                    @endforelse
                </section>
            </aside>
        </div>

        <p class="muted" style="margin-top:20px;font-size:var(--step--2)">
            The bars are how many of your open spots are booked that day, per
            booking type. Cancelled bookings are not counted.
        </p>
    @endif
@endsection
