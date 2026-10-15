{{--
    Every booking, searchable (SPEC §13.1).

    Leads with search rather than with the clock, because this screen is opened
    when somebody telephones about a booking — not during service. The totals
    are for the whole filtered set, not the page on screen: the question behind
    a date filter is almost always "how much was that week".
--}}
@extends('layouts.dashboard')

@section('title', 'Reservations — '.$tenant->name)

@section('main')
    <div class="page-head">
        <div>
            <h1>Reservations</h1>
            <p>Everything you have ever taken.</p>
        </div>

        <div class="row">
            <a class="btn btn--ghost" href="{{ route('dashboard.reservations.export', request()->query()) }}">
                Export CSV
            </a>
            <a class="btn" href="{{ route('dashboard.bookings.create') }}">New booking</a>
        </div>
    </div>

    <form method="GET" action="{{ route('dashboard.reservations.index') }}"
          class="card filters" style="margin-bottom:16px">
        <div style="flex:1 1 220px">
            <label class="label" for="q">Search</label>
            <input class="field" type="search" id="q" name="q" value="{{ $search->term }}"
                   placeholder="Name, email, phone or code">
        </div>

        <div>
            <label class="label" for="from">From</label>
            <input class="field" type="date" id="from" name="from" value="{{ $search->from }}">
        </div>

        <div>
            <label class="label" for="to">To</label>
            <input class="field" type="date" id="to" name="to" value="{{ $search->to }}">
        </div>

        <div>
            <label class="label" for="type">What</label>
            <select class="field" id="type" name="type">
                <option value="">Everything</option>
                @foreach ($bookingTypes as $bookingType)
                    <option value="{{ $bookingType->id }}" @selected($search->bookingTypeId === $bookingType->id)>
                        {{ $bookingType->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="label" for="status">Status</label>
            <select class="field" id="status" name="status">
                <option value="">Any</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected($search->status === $status->value)>
                        {{ $status->label() }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="label" for="channel">Came from</label>
            <select class="field" id="channel" name="channel">
                <option value="">Anywhere</option>
                @foreach ($channels as $channel)
                    <option value="{{ $channel->value }}" @selected($search->channel === $channel->value)>
                        {{ $channel->label() }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="label" for="sort">Sort</label>
            <select class="field" id="sort" name="sort">
                <option value="soonest" @selected($search->sort === 'soonest')>By date</option>
                <option value="newest" @selected($search->sort === 'newest')>Most recently booked</option>
            </select>
        </div>

        <div class="row">
            <button class="btn" type="submit">Apply</button>
            @if ($search->isFiltered())
                <a class="btn btn--ghost" href="{{ route('dashboard.reservations.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="stat-grid" style="margin-bottom:16px">
        <div class="stat"><b>{{ $totals['bookings'] }}</b><span class="muted">bookings</span></div>
        <div class="stat"><b>{{ $totals['guests'] }}</b><span class="muted">guests</span></div>
        <div class="stat"><b>{{ $totals['money'] }}</b><span class="muted">booked value</span></div>
    </div>

    @if ($reservations->isEmpty())
        <div class="empty">
            @if ($search->isFiltered())
                <p style="margin:0 0 6px"><strong>Nothing matches that.</strong></p>
                <p style="margin:0">
                    <a href="{{ route('dashboard.reservations.index') }}">Clear the filters</a>
                    and start again.
                </p>
            @else
                <p style="margin:0 0 6px"><strong>No bookings yet.</strong></p>
                <p style="margin:0">They will appear here the moment the first one lands.</p>
            @endif
        </div>
    @else
        <section class="card">
            <div class="ledger">
                @foreach ($reservations as $reservation)
                    @php $local = $reservation->startsAtLocal($timezone); @endphp

                    <a class="ledger__row" href="{{ route('dashboard.reservations.show', $reservation) }}">
                        <time class="ledger__when">
                            {{ $local->format('j M') }}
                            <small>{{ $local->format('H:i') }}</small>
                        </time>

                        <span class="ledger__who">
                            <strong>{{ $reservation->customer_name }}</strong>
                            <small>
                                {{ $reservation->party_size }}p
                                @if ($reservation->resource_snapshot['label'] ?? null)
                                    · {{ $reservation->resource_snapshot['label'] }}
                                @endif
                                @if ($areaNames[$reservation->area_id] ?? null)
                                    · {{ $areaNames[$reservation->area_id] }}
                                @endif
                                · {{ $reservation->code }}
                            </small>
                        </span>

                        <span class="ledger__money">
                            @if ((int) $reservation->amount_cents > 0)
                                {{ \App\Support\Money::format((int) $reservation->amount_cents) }}
                                <small class="muted">{{ $reservation->payment_status->label() }}</small>
                            @endif
                        </span>

                        <span class="ledger__status">
                            <span class="pill @if ($reservation->status === \App\Enums\ReservationStatus::NoShow) pill--muted @endif">
                                {{ $reservation->status->label() }}
                            </span>
                        </span>
                    </a>
                @endforeach
            </div>
        </section>

        @include('partials.pagination', ['paginator' => $reservations])
    @endif
@endsection
