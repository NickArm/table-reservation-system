{{--
    How the season went (SPEC §36).

    Opened in October rather than in July, with one question behind it: was it
    a good one. So the money comes first, in the only form that is true — what
    arrived, less what went back — and everything after it is there to explain
    that number rather than to decorate it.

    No charts beyond meters drawn in CSS. A taverna owner is not going to learn
    a legend, and an SVG chart would be the first thing on this dashboard that
    needs JavaScript to be read.
--}}
@extends('layouts.dashboard')

@section('title', 'Reports — '.$tenant->name)

@section('main')
    <div class="page-head">
        <div>
            <h1>Reports</h1>
            <p class="muted">
                {{ $from->format('j M Y') }} – {{ $to->format('j M Y') }}
            </p>
        </div>

        <nav class="chips" aria-label="Period">
            @foreach ($periods as $key => $period)
                <a class="chip" href="{{ route('dashboard.reports', ['period' => $key]) }}"
                   @if ($selected === $key) aria-current="page" @endif>
                    {{ $period['label'] }}
                </a>
            @endforeach
        </nav>
    </div>

    @if ($totals['bookings'] === 0)
        <div class="empty">
            <p style="margin:0 0 6px"><strong>Nothing booked in this period.</strong></p>
            <p style="margin:0" class="muted">
                Try another one above, or
                <a href="{{ route('dashboard.reports', ['period' => 'last-season']) }}">last season</a>.
            </p>
        </div>
    @else
        {{-- The four figures, in the order this venue cares about them.
             A place that takes nothing up front should not be shown a zero
             in the largest type on the page. --}}
        <div class="stat-grid">
            @if ($takesMoney)
                <div class="stat">
                    <b>{{ \App\Support\Money::format($money['net']) }}</b>
                    <span class="muted">taken online</span>
                    @if ($money['refunded'] > 0)
                        <small class="stat__note">
                            after {{ \App\Support\Money::format($money['refunded']) }} refunded
                        </small>
                    @endif
                </div>
            @endif

            <div class="stat">
                <b>{{ number_format($totals['bookings']) }}</b>
                <span class="muted">{{ \Illuminate\Support\Str::plural('booking', $totals['bookings']) }}</span>
                @if ($totals['cancelled'] > 0)
                    <small class="stat__note">{{ $totals['cancelled'] }} cancelled on top</small>
                @endif
            </div>

            <div class="stat">
                <b>{{ number_format($totals['guests']) }}</b>
                <span class="muted">people through the door</span>
            </div>

            {{-- Counts of real days, not a mean. See SeasonFigures. --}}
            <div class="stat">
                <b>{{ $occupancy['bestBooked'] }}<span class="muted">/{{ $occupancy['spots'] }}</span></b>
                <span class="muted">on your busiest day</span>
                @if ($occupancy['bestDay'] !== null)
                    <small class="stat__note">
                        {{ \Carbon\CarbonImmutable::parse($occupancy['bestDay'])->format('l j F') }}
                    </small>
                @endif
            </div>

            <div class="stat">
                <b>{{ $occupancy['halfFullDays'] }}</b>
                <span class="muted">days more than half full</span>
                <small class="stat__note">
                    of {{ $occupancy['busyDays'] }} with anything booked
                </small>
            </div>
        </div>

        {{-- Six bookings split five ways is not a channel mix. Say so, rather
             than drawing a confident chart through noise. --}}
        @if ($thin)
            <div class="notice" style="margin-bottom:16px">
                <strong>Not much to go on yet.</strong>
                {{ $totals['bookings'] }} {{ \Illuminate\Support\Str::plural('booking', $totals['bookings']) }}
                in this period. The counts below are real; the shares and the
                shapes will not mean much until there are a few dozen.
            </div>
        @endif

        {{-- Money the guest still owes at the table is not revenue, and a
             report that adds the two together is a wish. --}}
        @if ($money['atVenue'] > 0)
            <div class="notice" style="margin-bottom:16px">
                <strong>{{ \App\Support\Money::format($money['atVenue']) }}{{ $takesMoney ? ' more' : '' }} was due at the venue.</strong>
                Those guests pay you at the table, so whether it arrived is
                something only your till knows.
            </div>
        @endif

        <div class="work">
            <div class="stack">
                <section class="card">
                    <div class="card__head">
                        <h2 class="h2">Which days carried it</h2>
                    </div>

                    {{-- The one figure that changes a rota. --}}
                    <div class="meters">
                        @foreach ($weekdays as $day)
                            <div class="meter">
                                <span class="meter__label">{{ $day['day'] }}</span>
                                <span class="meter__track">
                                    <span class="meter__fill" style="width:{{ max(1, $day['percent']) }}%"></span>
                                </span>
                                <span class="meter__value">
                                    {{ $day['bookings'] }}
                                    <small class="muted">{{ $day['guests'] }} ppl</small>
                                </span>
                            </div>
                        @endforeach
                    </div>
                </section>

                @if (count($byType) > 1)
                    <section class="card">
                        <div class="card__head">
                            <h2 class="h2">Tables and sunbeds, separately</h2>
                        </div>

                        <p class="muted" style="margin:0 0 14px;font-size:var(--step--2)">
                            Twenty sunbeds and four tables fill at different speeds,
                            and one figure across both describes neither.
                        </p>

                        @foreach ($byType as $type)
                            <div class="needs">
                                <span class="needs__text">
                                    <strong>{{ $type['name'] }} — {{ $type['bookings'] }}
                                        {{ \Illuminate\Support\Str::plural('booking', $type['bookings']) }}</strong>
                                    <small class="muted">
                                        {{ $type['guests'] }} guests ·
                                        {{ \App\Support\Money::format($type['money']) }} booked ·
                                        {{ $type['spots'] }} {{ \Illuminate\Support\Str::plural('spot', $type['spots']) }}
                                    </small>
                                </span>
                            </div>
                        @endforeach
                    </section>
                @endif
            </div>

            <aside class="work__rail">
                {{-- The number that decides a renewal. --}}
                <section class="card">
                    <div class="card__head">
                        <h2 class="h2">Where they came from</h2>
                    </div>

                    <div class="meters">
                        @foreach ($channels as $channel)
                            <div class="meter">
                                <span class="meter__label meter__label--wide">{{ $channel['label'] }}</span>
                                <span class="meter__track">
                                    <span class="meter__fill @if ($channel['byStaff']) meter__fill--muted @endif"
                                          style="width:{{ max(1, $channel['percent']) }}%"></span>
                                </span>
                                <span class="meter__value">
                                    {{ $channel['count'] }}
                                    <small class="muted">{{ $channel['percent'] }}%</small>
                                </span>
                            </div>
                        @endforeach
                    </div>

                    <p class="muted" style="margin:14px 0 0;font-size:var(--step--2)">
                        The pale meters are bookings your own staff took by phone or at
                        the door. The rest arrived without anybody answering.
                    </p>
                </section>

                <section class="card">
                    <div class="card__head">
                        <h2 class="h2">Nobody came</h2>
                    </div>

                    @if ($totals['noShows'] === 0)
                        <p class="muted" style="margin:0">
                            Not one no-show. Whatever you are doing with reminders,
                            keep doing it.
                        </p>
                    @else
                        <div class="needs">
                            <span class="needs__dot needs__dot--table" aria-hidden="true"></span>
                            <span class="needs__text">
                                <strong>{{ $totals['noShows'] }} of {{ $totals['bookings'] }} ({{ (int) round($totals['noShows'] / $totals['bookings'] * 100) }}%)</strong>
                                <small class="muted">
                                    @if ($noShowCost > 0)
                                        {{ \App\Support\Money::format($noShowCost) }} of booked value
                                        that never sat down.
                                    @else
                                        Spots held, nobody in them.
                                    @endif
                                </small>
                            </span>
                        </div>

                        <p style="margin:12px 0 0;font-size:var(--step--1)">
                            <a href="{{ route('dashboard.notifications.edit') }}">
                                Reminders are the cheapest thing against this
                            </a>
                        </p>
                    @endif
                </section>

            </aside>
        </div>
    @endif

    {{-- Rare enough to live behind a fold, and nobody should have to meet a
         date picker to answer "how was August" (§1.1). --}}
    <details class="advanced" style="margin-top:24px" @if ($selected === 'custom') open @endif>
        <summary>Some other stretch of dates</summary>

        <form method="GET" action="{{ route('dashboard.reports') }}" class="phone" style="margin-top:14px">
            <div>
                <label class="label" for="from">From</label>
                <input class="field" type="date" id="from" name="from" value="{{ $from->toDateString() }}">
            </div>

            <div>
                <label class="label" for="to">To</label>
                <input class="field" type="date" id="to" name="to" value="{{ $to->toDateString() }}">
            </div>

            <div style="display:flex;align-items:flex-end">
                <button class="btn" type="submit">Show it</button>
            </div>
        </form>
    </details>

    <p class="muted" style="margin-top:20px;font-size:var(--step--2)">
        Cancelled bookings are left out of everything except the cancellation
        count.
        @if ($takesMoney)
            Money counts what your gateway actually took, less refunds, and
            leaves out anything taken with test keys.
        @endif
    </p>
@endsection
