{{--
    Taking a booking over the telephone (SPEC §13.1).

    The same three steps a guest goes through, in the same order, because the
    owner has watched guests do it and the shape is already familiar. Each step
    is a plain form submission: the page works with JavaScript switched off,
    and the only script on it fills the guest's details in for you.
--}}
@extends('layouts.dashboard')

@section('title', 'New booking — '.$tenant->name)

@section('main')
    <div class="page-head">
        <div>
            <p class="muted" style="margin:0 0 4px">
                <a href="{{ route('dashboard.today') }}">Today</a>
            </p>
            <h1>New booking</h1>
            <p>For when somebody rings, or walks up to the bar.</p>
        </div>
    </div>

    @if ($bookingTypes->isEmpty())
        <div class="empty">
            <p style="margin:0 0 6px"><strong>Nothing to book yet.</strong></p>
            <p style="margin:0">
                <a href="{{ route('dashboard.booking-types.index') }}">Set up your tables or sunbeds</a>
                first.
            </p>
        </div>
    @else
        @if ($prefill !== null)
            {{-- Said out loud, because a form that fills itself in without
                 explaining why reads as a bug. --}}
            <div class="notice" style="margin-bottom:16px">
                <strong>Booking for {{ $prefill['name'] }}.</strong>
                Their details are already filled in below — change any of them
                if this one is for somebody else.
            </div>
        @endif

        {{-- Step one: what, when, how many. --}}
        <form method="GET" action="{{ route('dashboard.bookings.create') }}"
              class="card filters" style="margin-bottom:16px">
            @if ($prefill !== null)
                <input type="hidden" name="guest" value="{{ request('guest') }}">
            @endif

            <div>
                <label class="label" for="type">What</label>
                <select class="field" id="type" name="type">
                    @foreach ($bookingTypes as $type)
                        <option value="{{ $type->slug }}" @selected($bookingType?->id === $type->id)>
                            {{ $type->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="label" for="date">Date</label>
                <input class="field" type="date" id="date" name="date" value="{{ $date }}">
            </div>

            <div>
                <label class="label" for="people">People</label>
                <input class="field" type="number" id="people" name="people" min="1" max="60"
                       value="{{ $partySize }}" style="max-width:110px">
            </div>

            <div>
                <button class="btn" type="submit">Find times</button>
            </div>
        </form>

        @if ($times === [])
            <div class="empty">
                <p style="margin:0 0 6px"><strong>Nothing free that day.</strong></p>
                <p style="margin:0">
                    Either it is closed, fully booked, or outside how far ahead
                    this booking type takes reservations.
                </p>
            </div>
        @else
            {{-- Step two: the time. --}}
            <section class="card" style="margin-bottom:16px">
                <div class="card__head">
                    <h2 class="h2">Pick a time</h2>
                    <span class="muted">{{ \Carbon\CarbonImmutable::parse($date)->format('l j F') }}</span>
                </div>

                <div class="times">
                    @foreach ($times as $offer)
                        @php
                            $isChosen = $chosen !== null && $offer->slot->startsAt->equalTo($chosen);
                        @endphp
                        <a class="time @if ($isChosen) time--on @endif"
                           href="{{ route('dashboard.bookings.create', array_filter([
                               'type' => $bookingType->slug,
                               'date' => $date,
                               'people' => $partySize,
                               'at' => $offer->slot->startsAt->toIso8601String(),
                               'guest' => request('guest'),
                           ])) }}">
                            {{ $offer->slot->startsAt->setTimezone($timezone)->format('H:i') }}
                            <small>{{ $offer->available }} free</small>
                        </a>
                    @endforeach
                </div>
            </section>

            @if ($chosen !== null)
                <form method="POST" action="{{ route('dashboard.bookings.store') }}" class="stack">
                    @csrf

                    <input type="hidden" name="starts_at" value="{{ $chosen->toIso8601String() }}">
                    <input type="hidden" name="people" value="{{ $partySize }}">

                    {{-- Step three: which spot, and who. --}}
                    <section class="card">
                        <div class="card__head">
                            <h2 class="h2">Which one</h2>
                            <span class="muted">
                                {{ $chosen->setTimezone($timezone)->format('H:i') }},
                                {{ $partySize }} {{ \Illuminate\Support\Str::plural('person', $partySize) }}
                            </span>
                        </div>

                        @if ($offers === [])
                            <p class="muted" style="margin:0">
                                Nothing free at that time any more. Pick another.
                            </p>
                        @else
                            @foreach ($offers as $areaOffer)
                                <p class="label" style="margin-top:4px">{{ $areaOffer->area->name }}</p>

                                <div class="spots">
                                    @foreach ($areaOffer->offers as $spot)
                                        <label class="spot">
                                            <input type="radio" name="resource" value="{{ $spot->resource->id }}"
                                                   @checked(old('resource') == $spot->resource->id)
                                                   style="margin-right:8px">
                                            <span class="spot__label">{{ $spot->label() }}</span>
                                            <span class="spot__price">
                                                {{ \App\Support\Money::format($spot->priceCents()) }}
                                            </span>
                                            @if ($spot->contents() !== '')
                                                <span class="spot__what muted">{{ $spot->contents() }}</span>
                                            @endif
                                        </label>
                                    @endforeach
                                </div>
                            @endforeach
                        @endif
                    </section>

                    <section class="card stack">
                        <div class="card__head">
                            <h2 class="h2">Who</h2>
                        </div>

                        <div class="suggest">
                            <label class="label" for="name">Name</label>
                            <input class="field" type="text" id="name" name="name" required
                                   maxlength="80" autocomplete="off"
                                   value="{{ old('name', $prefill['name'] ?? '') }}"
                                   data-suggest="{{ route('dashboard.guests.suggest') }}">
                            <div class="suggest__list" id="guest-suggestions" role="listbox"></div>
                            <p class="muted" style="margin:6px 0 0;font-size:var(--step--2)">
                                Start typing — if they have booked before, their
                                details fill themselves in.
                            </p>
                        </div>

                        <div class="phone">
                            <div>
                                <label class="label" for="calling_code">Code</label>
                                <select class="field" id="calling_code" name="calling_code">
                                    @foreach ($callingCodes as $code => $label)
                                        <option value="{{ $code }}" @selected(old('calling_code', $prefill['code'] ?? '+30') === $code)>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="label" for="phone">Phone</label>
                                <input class="field" type="tel" id="phone" name="phone" required
                                       maxlength="30" value="{{ old('phone', $prefill['phone'] ?? '') }}">
                            </div>
                        </div>

                        <div>
                            <label class="label" for="email">Email <span class="muted">— optional</span></label>
                            <input class="field" type="email" id="email" name="email"
                                   maxlength="160" value="{{ old('email', $prefill['email'] ?? '') }}">
                            <p class="muted" style="margin:6px 0 0;font-size:var(--step--2)">
                                With an address they get the same confirmation and
                                reminder as an online booking, and they join your
                                guest list. Without one, neither happens.
                            </p>
                        </div>

                        <div>
                            <label class="label" for="notes">Notes</label>
                            <textarea class="field" id="notes" name="notes" rows="2" maxlength="500"
                                      placeholder="Birthday. Wants the shade.">{{ old('notes') }}</textarea>
                        </div>
                    </section>

                    <section class="card stack">
                        <div class="card__head">
                            <h2 class="h2">Money</h2>
                            <span class="muted">Leave blank for your usual price</span>
                        </div>

                        <div class="row">
                            <div>
                                <label class="label" for="amount">Amount</label>
                                <input class="field" type="number" id="amount" name="amount"
                                       step="0.50" min="0" max="100000" style="max-width:160px"
                                       value="{{ old('amount') }}" placeholder="price list">
                            </div>

                            <div>
                                <label class="label" for="channel">Came from</label>
                                <select class="field" id="channel" name="channel">
                                    @foreach ($channels as $channel)
                                        <option value="{{ $channel->value }}"
                                            @selected(old('channel') === $channel->value)>
                                            {{ $channel->label() }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <label class="consent">
                            <input type="checkbox" name="paid" value="1" @checked(old('paid'))>
                            <span>
                                <strong>Already paid</strong>
                                <small class="muted">
                                    Cash in hand, or a transfer you have seen. Leave
                                    it unticked and it shows as due on arrival.
                                </small>
                            </span>
                        </label>

                        <label class="consent">
                            <input type="checkbox" name="force" value="1" @checked(old('force'))>
                            <span>
                                <strong>Book it anyway</strong>
                                <small class="muted">
                                    Overrides the availability check. You are
                                    standing in the room and we are not — but the
                                    override is written on the booking.
                                </small>
                            </span>
                        </label>
                    </section>

                    <div>
                        <button class="btn" type="submit">Book it</button>
                    </div>
                </form>
            @endif
        @endif
    @endif
@endsection
