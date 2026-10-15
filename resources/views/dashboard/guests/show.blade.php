{{--
    One guest's card (SPEC §28.3).

    The history is the reason this page exists: somebody rings, the owner types
    their name, and the answer to "do I know them?" is on one screen.
--}}
@extends('layouts.dashboard')

@section('title', $guest->name.' — '.$tenant->name)

@section('main')
    <div class="page-head">
        <div>
            <p class="muted" style="margin:0 0 4px">
                <a href="{{ route('dashboard.guests.index') }}">Guests</a>
            </p>
            <h1>{{ $guest->name }}</h1>
            <p class="muted">
                {{ $guest->email ?? 'No email address' }}
                @if (filled($guest->phone))
                    · <a href="tel:{{ $guest->phone }}">{{ $guest->phone }}</a>
                @endif
            </p>
        </div>

        @if ($guest->warning())
            <span class="pill" style="border-color:var(--warn-line);background:var(--warn-soft);color:var(--warn)">
                {{ $guest->warning() }}
            </span>
        @elseif ($guest->isRepeat())
            <span class="pill pill--ok">{{ $guest->visitLabel() }}</span>
        @endif
    </div>

    @if ($guest->isAnonymized())
        <div class="notice notice--warn" style="margin-bottom:16px">
            This guest asked to be forgotten on
            {{ $guest->anonymized_at?->setTimezone($timezone)->format('j F Y') }}.
            Their bookings are still here; their details are not.
        </div>
    @endif

    <div class="stat-grid" style="margin-bottom:16px">
        <div class="stat"><b>{{ $guest->bookings_count }}</b><span class="muted">bookings</span></div>
        <div class="stat"><b>{{ $guest->completed_count }}</b><span class="muted">completed</span></div>
        <div class="stat"><b>{{ $guest->cancelled_count }}</b><span class="muted">cancelled</span></div>
        <div class="stat"><b>{{ $guest->no_show_count }}</b><span class="muted">no-shows</span></div>
        <div class="stat">
            <b>{{ \App\Support\Money::format((int) $guest->total_spent_cents) }}</b>
            <span class="muted">booked in total</span>
        </div>
    </div>

    <div class="grid grid--2" style="align-items:start">
        <section class="card">
            <div class="card__head">
                <h2 class="h2">History</h2>
                <span class="muted">
                    Since {{ $guest->first_seen_at?->setTimezone($timezone)->format('j M Y') ?? '—' }}
                </span>
            </div>

            @if ($history->isEmpty())
                <p class="muted" style="margin:0">No bookings on record.</p>
            @else
                <div class="history">
                    @foreach ($history as $reservation)
                        <div class="history__row">
                            <time>{{ $reservation->startsAtLocal($timezone)->format('j M Y') }}</time>

                            <span>
                                <a href="{{ route('dashboard.reservations.show', $reservation) }}">
                                    {{ $reservation->resource_snapshot['label'] ?? 'Booking' }}
                                </a>
                                <span class="muted">
                                    · {{ $reservation->startsAtLocal($timezone)->format('H:i') }}
                                    · {{ $reservation->party_size }}p
                                    · {{ $reservation->status->label() }}
                                </span>
                            </span>

                            <span class="muted" style="text-align:right">
                                @if ((int) $reservation->amount_cents > 0)
                                    {{ \App\Support\Money::format((int) $reservation->amount_cents) }}
                                @endif
                            </span>

                            @if (filled($reservation->notes))
                                <p>“{{ $reservation->notes }}”</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <div class="stack">
            <section class="card">
                <div class="card__head">
                    <h2 class="h2">Your notes</h2>
                    <span class="muted">Private</span>
                </div>

                <form method="POST" action="{{ route('dashboard.guests.update', $guest) }}" class="stack">
                    @csrf
                    @method('PATCH')

                    <div>
                        <label class="label" for="internal_notes">Notes</label>
                        <textarea class="field" id="internal_notes" name="internal_notes" rows="5"
                                  placeholder="Prefers the front row. Always brings the dog.">{{ old('internal_notes', $guest->internal_notes) }}</textarea>
                        <p class="muted" style="margin:6px 0 0;font-size:var(--step--2)">
                            Only you and your staff see this. It never appears in
                            an email or on the guest's voucher.
                        </p>
                    </div>

                    <div>
                        <label class="label" for="tags">Tags</label>
                        <input class="field" type="text" id="tags" name="tags"
                               value="{{ old('tags', implode(', ', $guest->tagList())) }}"
                               placeholder="regular, VIP, allergy">
                        <p class="muted" style="margin:6px 0 0;font-size:var(--step--2)">
                            Separated by commas.
                        </p>
                    </div>

                    <div>
                        <button class="btn" type="submit">Save</button>
                    </div>
                </form>
            </section>

            @if ($guest->marketing_consent)
                <section class="card">
                    <h2 class="h2" style="margin-bottom:6px">Marketing</h2>
                    <p class="muted" style="margin:0">
                        Agreed to hear from you on
                        {{ $guest->marketing_consent_at?->setTimezone($timezone)->format('j F Y') }}.
                        Confirmations and reminders go out regardless — those are
                        part of the booking, not marketing.
                    </p>
                </section>
            @endif

            @if ($canErase && ! $guest->isAnonymized())
                <section class="card">
                    <h2 class="h2" style="margin-bottom:6px">Erase this guest</h2>
                    <p class="muted" style="margin:0 0 12px">
                        For when somebody asks to be forgotten. Their name,
                        email, phone and notes go; the bookings stay, so your
                        figures for the season do not change. It cannot be
                        undone.
                    </p>

                    <form method="POST" action="{{ route('dashboard.guests.erase', $guest) }}"
                          onsubmit="return confirm('Erase this guest\'s details? This cannot be undone.')">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn--ghost" type="submit">Erase their details</button>
                    </form>
                </section>
            @endif
        </div>
    </div>
@endsection
