{{--
    The account, and the one irreversible thing in the product
    (SPEC §13.1, §29).

    The counts are the point. "This permanently deletes everything" means
    nothing; "1,247 bookings and 892 guests" is a sentence somebody stops at.
--}}
@extends('layouts.dashboard')

@section('title', 'Settings — '.$tenant->name)

@section('main')
    @include('partials.crumb', ['trail' => [
        ['Setup', route('dashboard.setup')],
        ['Settings', null],
    ]])

    <div class="page-head">
        <h1>Settings</h1>
        <p>Your account, and how to leave.</p>
    </div>

    <div class="stack" style="max-width:760px">

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">This account</h2>
            </div>

            <div class="facts">
                <div>
                    <span class="label">Venue</span>
                    <strong>{{ $tenant->name }}</strong>
                </div>
                <div>
                    <span class="label">Booking page</span>
                    <strong>
                        <a href="{{ route('public.booking', $tenant) }}">
                            /reservation/{{ $tenant->slug }}
                        </a>
                    </strong>
                </div>
                <div>
                    <span class="label">Time zone</span>
                    <strong>{{ $tenant->timezone }}</strong>
                </div>
                <div>
                    <span class="label">With us since</span>
                    <strong>{{ $tenant->created_at->format('j M Y') }}</strong>
                </div>
            </div>

            <p class="muted">
                The booking page address cannot be changed, and that is on purpose:
                it ends up on signs, flyers and printed QR codes, and a link that
                stops working is a guest who cannot book.
            </p>
        </section>

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">Your data</h2>
            </div>

            <p class="muted">
                Everything in here is yours and downloadable at any time, whatever
                state your subscription is in.
            </p>

            <p>
                <a href="{{ route('dashboard.reservations.export') }}" class="btn btn--ghost">
                    Bookings (CSV)
                </a>
                <a href="{{ route('dashboard.guests.export') }}" class="btn btn--ghost">
                    Guest list (CSV)
                </a>
                <a href="{{ route('dashboard.account.export') }}" class="btn btn--ghost">
                    Everything (JSON)
                </a>
            </p>
        </section>

        @if ($tenant->deletion_requested_at !== null)
            <section class="card stack">
                <div class="card__head">
                    <h2 class="h2">This account is being deleted</h2>
                    <span class="pill pill--warn">
                        {{ $tenant->purge_at?->format('j M Y') }}
                    </span>
                </div>

                <p class="notice notice--warn">
                    Everything is deleted for good on
                    <strong>{{ $tenant->purge_at?->format('j F Y') }}</strong>.
                    Nothing has been removed yet.
                </p>

                <p class="muted">
                    If this was a mistake, reply to the email we sent you — any time
                    before that date is fine.
                </p>
            </section>
        @else
            <section class="card stack">
                <div class="card__head">
                    <h2 class="h2">Delete this account</h2>
                </div>

                @if ($blockers > 0)
                    {{--
                        The blocker (§29.1). We do not let a venue disappear leaving
                        tourists holding paid bookings that exist nowhere — the same
                        reasoning as closures: the system never cancels a paid
                        booking by itself, and does not let one be orphaned either.
                    --}}
                    <p class="notice notice--warn">
                        <strong>You have {{ $blockers }} upcoming
                        {{ \Illuminate\Support\Str::plural('reservation', $blockers) }}.</strong>
                        Cancel them — and refund your guests — or wait until they have
                        passed.
                    </p>

                    <p>
                        <a href="{{ route('dashboard.reservations.index') }}" class="btn">
                            See the {{ $blockers }}
                            {{ \Illuminate\Support\Str::plural('reservation', $blockers) }}
                        </a>
                    </p>
                @else
                    <p>This permanently deletes everything:</p>

                    <ul class="muted" style="margin:0;padding-left:20px;line-height:1.9">
                        {{--
                            Spelled out rather than run through `Str::plural`,
                            which pluralises only the last word and produces
                            "4 table or sunbeds".
                        --}}
                        <li>{{ $counts['resources'] }}
                            {{ $counts['resources'] === 1 ? 'table or sunbed' : 'tables and sunbeds' }},
                            and how they are laid out</li>
                        <li>{{ number_format($counts['reservations']) }}
                            {{ \Illuminate\Support\Str::plural('booking', $counts['reservations']) }}</li>
                        <li>{{ number_format($counts['guests']) }}
                            {{ \Illuminate\Support\Str::plural('person', $counts['guests']) }}
                            in your guest list</li>
                        <li>your branding, opening hours, prices and payment settings</li>
                        <li>{{ $counts['people'] }}
                            {{ \Illuminate\Support\Str::plural('login', $counts['people']) }}</li>
                    </ul>

                    <p class="muted">
                        We wait <strong>{{ config('tenancy.purge_after_days') }} days</strong>
                        before anything is actually removed, so a change of mind is
                        still possible. After that it cannot be undone by us or by
                        anybody else.
                    </p>

                    <p class="notice">
                        <strong>Download your data first.</strong>
                        It is three buttons above this, and it takes a few seconds.
                    </p>

                    <details class="secret">
                        <summary><strong>I want to delete {{ $tenant->name }}</strong></summary>

                        <form method="POST" action="{{ route('dashboard.account.destroy') }}"
                              class="stack">
                            @csrf

                            <div class="field">
                                <label for="confirm">
                                    Type <strong>{{ $tenant->name }}</strong> to confirm
                                </label>
                                <input id="confirm" type="text" name="confirm" required
                                       autocomplete="off" placeholder="{{ $tenant->name }}">
                            </div>

                            <div class="field">
                                <label for="password">Your password</label>
                                <input id="password" type="password" name="password" required
                                       autocomplete="current-password">
                            </div>

                            <button type="submit" class="btn">
                                Delete {{ $tenant->name }}
                            </button>
                        </form>
                    </details>
                @endif
            </section>
        @endif
    </div>
@endsection
