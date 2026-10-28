@extends('layouts.dashboard')

@section('title', 'Today')

@section('main')
    @if ($tenant->isOnTrial())
        <div class="notice" style="margin-bottom:20px">
            <strong>{{ $tenant->trialDaysLeft() }} days left in your trial.</strong>
            Everything is switched on — take real bookings while you try it.
        </div>
    @elseif ($tenant->isReadOnly())
        <div class="notice notice--warn" style="margin-bottom:20px">
            <strong>Your subscription has ended.</strong>
            Existing reservations are safe and still visible. Renew to take new ones.
        </div>
    @endif

    <div class="page-head">
        <div>
            <h1>{{ $range === 'today' ? 'Today' : 'Bookings' }}</h1>
            <p class="muted">{{ $heading }}</p>
        </div>

        @if ($hasStructure)
            {{-- The range lives with the list it changes, not in the rail:
                 it is a question about this screen, not about the app. --}}
            <div class="chips" role="group" aria-label="When">
                @foreach (['today' => 'Today', 'tomorrow' => 'Tomorrow', 'week' => 'This week', 'upcoming' => 'All upcoming'] as $key => $label)
                    <a class="chip" href="{{ route('dashboard.today', ['when' => $key]) }}"
                       @if ($range === $key) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </div>
        @endif
    </div>

    @if (! $hasStructure)
        <div class="empty" style="margin-bottom:24px">
            <h2 class="h2" style="margin-bottom:6px">You have nothing to book yet</h2>
            <p class="muted" style="margin:0 0 20px">
                Tell us what you take bookings for — tables, sunbeds, or both — and your
                page goes live.
            </p>
            <a class="btn" href="{{ route('dashboard.booking-types.index') }}">Set this up</a>
        </div>
    @endif

    {{--
        Four numbers, each with the only thing that makes it mean something:
        covers against the size of the room, and what is happening in the
        next half hour rather than a total for the day.
    --}}
    <div class="stat-grid" style="margin-bottom:20px">
        <div class="stat">
            <b>{{ $stats['guests'] }}</b>
            <span class="muted">
                @if ($seats > 0)
                    of {{ $seats }} seats
                @else
                    guests
                @endif
            </span>
            @if ($seats > 0)
                @php $fill = min(100, (int) round($stats['guests'] / $seats * 100)); @endphp
                <div class="progress" style="margin-top:10px" role="img"
                     aria-label="{{ $fill }} percent of seats booked">
                    <span style="width:{{ $fill }}%"></span>
                </div>
            @endif
        </div>

        <div class="stat">
            <b>{{ $stats['bookings'] }}</b>
            <span class="muted">bookings</span>
            <small class="stat__note">{{ $stats['booked_today'] }} came in today</small>
        </div>

        <div class="stat">
            <b class="stat--ok">{{ $stats['seated'] }}</b>
            <span class="muted">on the floor now</span>
            <small class="stat__note">Checked in</small>
        </div>

        <div class="stat">
            <b class="{{ $stats['soon'] > 0 ? 'stat--warn' : '' }}">{{ $stats['soon'] }}</b>
            <span class="muted">arriving next</span>
            <small class="stat__note">Within half an hour</small>
        </div>
    </div>

    <div class="work">
        <div>
            @if ($reservations->isEmpty())
                <div class="card">
                    <h2 class="h2" style="margin-bottom:6px">Nothing booked</h2>
                    <p class="muted" style="margin:0">
                        Reservations will appear here as they come in, earliest first.
                    </p>
                </div>
            @else
                <div class="card" style="padding:0;overflow:hidden">
                    <ul class="bookings">
                        @foreach ($reservations as $reservation)
                            @php $guest = $guests[$reservation->guest_id] ?? null; @endphp

                            <li class="booking">
                                <span class="booking__time">
                                    {{ $reservation->startsAtLocal($timezone)->format('H:i') }}
                                    @if ($range !== 'today')
                                        <small>{{ $reservation->startsAtLocal($timezone)->format('D j M') }}</small>
                                    @else
                                        <small>to {{ $reservation->endsAtLocal($timezone)->format('H:i') }}</small>
                                    @endif
                                </span>

                                {{-- The table, not a word: during service the
                                     eye looks for "T4", and a seated table
                                     carries the accent so a full room reads
                                     as a block of colour. --}}
                                <span class="booking__table @if ($reservation->isCheckedIn()) booking__table--on @endif">
                                    {{ $reservation->resource_snapshot['label'] ?? '—' }}
                                </span>

                                <span class="booking__who">
                                    <strong>
                                        <a href="{{ route('dashboard.reservations.show', $reservation) }}"
                                           style="color:inherit">{{ $reservation->customer_name }}</a>

                                        {{--
                                            The two notes that change how a table is
                                            handled. Anyone who has stood the venue up
                                            twice deserves a thought before the front row
                                            on a full Saturday (SPEC §28.3).
                                        --}}
                                        @if ($guest?->warning())
                                            <span class="flag flag--warn" title="Has not turned up before">
                                                {{ $guest->warning() }}
                                            </span>
                                        @elseif ($guest?->visitLabel())
                                            <span class="flag">{{ $guest->visitLabel() }}</span>
                                        @endif
                                    </strong>

                                    <small class="muted">
                                        {{ $reservation->party_size }}
                                        {{ \Illuminate\Support\Str::plural('guest', $reservation->party_size) }}
                                        @if ($areaNames[$reservation->area_id] ?? null)
                                            · {{ $areaNames[$reservation->area_id] }}
                                        @endif
                                        @if ($reservation->amount_cents > 0)
                                            · {{ \App\Support\Money::format($reservation->amount_cents) }}
                                            @if ($reservation->payment_status->isSettled())
                                                paid
                                            @else
                                                owing
                                            @endif
                                        @endif
                                    </small>

                                    {{--
                                        Spelled out rather than hidden behind a
                                        pill with a title attribute. "Gluten
                                        intolerant" has to reach the kitchen
                                        without anybody hovering a mouse, and
                                        on a phone there is no hover at all.
                                    --}}
                                    @if (filled($reservation->notes))
                                        <span class="booking__note">{{ $reservation->notes }}</span>
                                    @endif
                                </span>

                                <span class="booking__meta">
                                    @if ($reservation->isCheckedIn())
                                        <span class="pill pill--ok">Seated</span>
                                    @elseif ($reservation->status === \App\Enums\ReservationStatus::Confirmed)
                                        {{-- One tap, from the screen they are
                                             already looking at. Walking a guest
                                             to a table and then opening their
                                             booking to say so is two jobs. --}}
                                        <form method="POST"
                                              action="{{ route('dashboard.reservations.check-in', $reservation) }}">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn--ghost btn--small">Seat</button>
                                        </form>
                                    @else
                                        <span class="pill">{{ $reservation->status->label() }}</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <aside class="work__rail">
            @if ($attention !== [])
                <section class="card">
                    <div class="card__head">
                        <h2 class="h2">Needs you</h2>
                        <span class="pill pill--warn">{{ count($attention) }}</span>
                    </div>

                    @foreach ($attention as $item)
                        <div class="needs">
                            <span class="needs__dot needs__dot--{{ $item['kind'] }}" aria-hidden="true"></span>
                            <span class="needs__text">
                                <strong>{{ $item['title'] }}</strong>
                                <small class="muted">{{ $item['body'] }}</small>
                            </span>
                            <a class="btn btn--ghost btn--small" href="{{ $item['href'] }}">{{ $item['action'] }}</a>
                        </div>
                    @endforeach
                </section>
            @endif

            @if ($hasStructure)
                <section class="card">
                    <div class="card__head">
                        <h2 class="h2">Taking bookings</h2>
                    </div>
                    <p class="muted" style="margin:0 0 14px;font-size:var(--step--2)">
                        Stops new ones only — anything already booked stays.
                    </p>

                    @foreach ($bookingTypes as $bookingType)
                        <div class="needs">
                            <span class="needs__text">
                                <strong>{{ $bookingType->name }}</strong>
                                @if ($stoppedToday[$bookingType->id] ?? false)
                                    <small class="muted">Stopped for today</small>
                                @else
                                    <small class="muted">Open</small>
                                @endif
                            </span>

                            @if ($stoppedToday[$bookingType->id] ?? false)
                                <form method="POST"
                                      action="{{ route('dashboard.closures.resume-today', $bookingType) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn--ghost btn--small">Start again</button>
                                </form>
                            @else
                                <form method="POST"
                                      action="{{ route('dashboard.closures.stop-today', $bookingType) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn--ghost btn--small">Stop</button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </section>
            @endif

            @if ($completeness !== null && $completeness['percent'] < 100)
                <section class="card card--soft">
                    <div class="card__head">
                        <h2 class="h2">Finish your page</h2>
                        <span class="muted">{{ $completeness['percent'] }}%</span>
                    </div>

                    <div class="progress" role="img"
                         aria-label="Profile {{ $completeness['percent'] }} percent complete">
                        <span style="width:{{ $completeness['percent'] }}%"></span>
                    </div>

                    @if ($completeness['missing'] !== [])
                        <p class="muted" style="margin:12px 0 0;font-size:var(--step--2)">
                            Still missing: {{ implode(', ', $completeness['missing']) }}.
                            None of it stops a booking — it decides whether anybody finds you.
                        </p>
                    @endif

                    <p style="margin:12px 0 0">
                        <a href="{{ route('dashboard.profile.edit') }}">Fill it in</a>
                    </p>
                </section>
            @endif
        </aside>
    </div>
@endsection
