{{--
    Every booking, searchable (SPEC §13.1).

    Leads with search rather than with the clock, because this screen is opened
    when somebody telephones about a booking — not during service. The totals
    are for the whole filtered set, not the page on screen: the question behind
    a date filter is almost always "how much was that week".

    The common filters are one tap each. Seven dropdowns and an Apply button is
    a form, and a form is something an owner fills in while a guest waits on
    the telephone; chips answer the two questions actually asked — which ones
    are cancelled, and what is coming up — without a round of typing.
--}}
@extends('layouts.dashboard')

@section('title', 'Bookings — '.$tenant->name)

@section('main')
    <div class="page-head">
        <div>
            <h1>Bookings</h1>
            <p class="muted">Everything you have ever taken.</p>
        </div>

        <a class="btn btn--ghost" href="{{ route('dashboard.reservations.export', request()->query()) }}">
            Export CSV
        </a>
    </div>

    {{-- Search on its own line and wide: it is the reason this screen exists. --}}
    <form method="GET" action="{{ route('dashboard.reservations.index') }}" class="searchbar">
        @foreach (request()->except(['q', 'page']) as $key => $value)
            @if (! is_array($value))
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endif
        @endforeach

        <label class="label" for="q" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">
            Search bookings
        </label>
        <input class="field" type="search" id="q" name="q" value="{{ $search->term }}"
               placeholder="Name, email, phone or booking code">
        <button class="btn" type="submit">Search</button>
    </form>

    <div class="chips" style="margin-bottom:10px">
        @foreach ($whenFilters as $filter)
            <a class="chip" href="{{ $filter['href'] }}"
               @if ($filter['on']) aria-current="page" @endif>{{ $filter['label'] }}</a>
        @endforeach
    </div>

    <div class="chips" style="margin-bottom:16px">
        @foreach ($statusFilters as $filter)
            <a class="chip" href="{{ $filter['href'] }}"
               @if ($filter['on']) aria-current="page" @endif>{{ $filter['label'] }}</a>
        @endforeach
    </div>

    {{-- Everything an owner reaches for once a season, out of the way but
         never behind a different screen. --}}
    <details class="advanced" style="margin-bottom:16px" @if ($search->isAdvanced()) open @endif>
        <summary>Exact dates, booking type, where it came from</summary>

        <form method="GET" action="{{ route('dashboard.reservations.index') }}"
              class="filters" style="margin-top:14px">
            @if (filled($search->term))
                <input type="hidden" name="q" value="{{ $search->term }}">
            @endif

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
    </details>

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
        <section class="card" style="padding:0;overflow:hidden">
            <div class="ledger">
                @php $lastDay = null; @endphp

                @foreach ($reservations as $reservation)
                    @php
                        $local = $reservation->startsAtLocal($timezone);
                        $day = $local->format('Y-m-d');
                    @endphp

                    {{-- A date the eye can rest on. Without it the list is 50
                         rows of numbers and the reader counts to find Friday. --}}
                    @if ($day !== $lastDay)
                        <p class="ledger__day">{{ $local->format('l j F Y') }}</p>
                        @php $lastDay = $day; @endphp
                    @endif

                    <a class="ledger__row" href="{{ route('dashboard.reservations.show', $reservation) }}">
                        <time class="ledger__when">
                            {{ $local->format('H:i') }}
                            <small>{{ $reservation->code }}</small>
                        </time>

                        <span class="ledger__who">
                            <strong>{{ $reservation->customer_name }}</strong>
                            <small>
                                {{ $reservation->party_size }}
                                {{ \Illuminate\Support\Str::plural('guest', $reservation->party_size) }}
                                @if ($reservation->resource_snapshot['label'] ?? null)
                                    · {{ $reservation->resource_snapshot['label'] }}
                                @endif
                                @if ($areaNames[$reservation->area_id] ?? null)
                                    · {{ $areaNames[$reservation->area_id] }}
                                @endif
                                · {{ $reservation->channel->label() }}
                            </small>

                            @if (filled($reservation->notes))
                                <span class="ledger__note">{{ $reservation->notes }}</span>
                            @endif
                        </span>

                        <span class="ledger__money">
                            @if ((int) $reservation->amount_cents > 0)
                                {{ \App\Support\Money::format((int) $reservation->amount_cents) }}
                                <small class="muted">{{ $reservation->payment_status->label() }}</small>
                            @endif
                        </span>

                        <span class="ledger__status">
                            <span class="pill {{ $statusSkins[$reservation->status->value] ?? '' }}">
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
