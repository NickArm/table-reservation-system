@extends('layouts.dashboard')

@section('title', 'Today')

@section('main')
    @if ($tenant->isOnTrial())
        <div class="notice" style="margin-bottom:24px">
            <strong>{{ $tenant->trialDaysLeft() }} days left in your trial.</strong>
            Everything is switched on — take real bookings while you try it.
        </div>
    @elseif ($tenant->isReadOnly())
        <div class="notice notice--warn" style="margin-bottom:24px">
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
            <div class="row" style="align-items:center">
                <nav class="nav" aria-label="When">
                    @foreach (['today' => 'Today', 'tomorrow' => 'Tomorrow', 'week' => 'This week', 'upcoming' => 'All upcoming'] as $key => $label)
                        <a href="{{ route('dashboard.today', ['when' => $key]) }}"
                           @if ($range === $key) aria-current="page" @endif>{{ $label }}</a>
                    @endforeach
                </nav>

                {{-- The phone rings during service; this is one tap away. --}}
                <a class="btn" href="{{ route('dashboard.bookings.create') }}">New booking</a>
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

    <div class="stat-grid" style="margin-bottom:24px">
        <div class="stat"><b>{{ $stats['bookings'] }}</b><span class="muted">bookings</span></div>
        <div class="stat"><b>{{ $stats['guests'] }}</b><span class="muted">guests</span></div>
        <div class="stat">
            <b>{{ \App\Support\Money::format($stats['prepaid']) }}</b>
            <span class="muted">prepaid</span>
        </div>
        <div class="stat"><b>{{ $stats['booked_today'] }}</b><span class="muted">booked today</span></div>
    </div>

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
                    <li class="booking">
                        <span class="booking__time">
                            {{ $reservation->startsAtLocal($timezone)->format('H:i') }}
                            @if ($range !== 'today')
                                <small>{{ $reservation->startsAtLocal($timezone)->format('D j M') }}</small>
                            @endif
                        </span>

                        @php $guest = $guests[$reservation->guest_id] ?? null; @endphp

                        <span class="booking__who">
                            <strong>
                                <a href="{{ route('dashboard.reservations.show', $reservation) }}"
                                   style="color:inherit">{{ $reservation->customer_name }}</a>
                            </strong>

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
                            <small class="muted">
                                {{ $reservation->party_size }}
                                {{ \Illuminate\Support\Str::plural('guest', $reservation->party_size) }}
                                @if ($reservation->resource_snapshot['label'] ?? null)
                                    · {{ $reservation->resource_snapshot['label'] }}
                                @endif
                                @if ($areaNames[$reservation->area_id] ?? null)
                                    · {{ $areaNames[$reservation->area_id] }}
                                @endif
                            </small>
                        </span>

                        <span class="booking__meta">
                            @if ($reservation->notes)
                                <span class="pill" title="{{ $reservation->notes }}">note</span>
                            @endif

                            @if ($reservation->amount_cents > 0)
                                <span class="muted">{{ \App\Support\Money::format($reservation->amount_cents) }}</span>
                            @endif

                            <span class="pill @if ($reservation->isCheckedIn()) pill--ok @endif">
                                {{ $reservation->status->label() }}
                            </span>
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($hasStructure)
        <section class="card" style="margin-top:24px">
            <div class="card__head">
                <h2 class="h2">Taking bookings</h2>
                <span class="muted">Stops new ones only — anything booked stays.</span>
            </div>

            <div class="grid grid--2">
                @foreach ($bookingTypes as $bookingType)
                    <div class="row" style="justify-content:space-between;align-items:center">
                        <span>
                            <strong>{{ $bookingType->name }}</strong>
                            @if ($stoppedToday[$bookingType->id] ?? false)
                                <span class="pill" style="margin-left:8px">stopped today</span>
                            @endif
                        </span>

                        @if ($stoppedToday[$bookingType->id] ?? false)
                            <form method="POST"
                                  action="{{ route('dashboard.closures.resume-today', $bookingType) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="btn btn--ghost">Start again</button>
                            </form>
                        @else
                            <form method="POST"
                                  action="{{ route('dashboard.closures.stop-today', $bookingType) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="btn btn--ghost">Stop for today</button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if ($completeness !== null && $completeness['percent'] < 100)
        <section class="card" style="margin-top:24px">
            <h2 class="h2" style="margin-bottom:10px">
                Your page is {{ $completeness['percent'] }}% complete
            </h2>

            <div class="progress" role="img"
                 aria-label="Profile {{ $completeness['percent'] }} percent complete">
                <span style="width:{{ $completeness['percent'] }}%"></span>
            </div>

            @if ($completeness['missing'] !== [])
                <p class="muted" style="margin:12px 0 0">
                    Still missing: {{ implode(', ', $completeness['missing']) }}.
                    A fuller page shows up better in search results.
                </p>
            @endif

            @if ($venue !== null)
                <p style="margin:12px 0 0">
                    <a href="{{ route('public.booking', $tenant) }}">View your public page</a>
                </p>
            @endif
        </section>
    @endif
@endsection
