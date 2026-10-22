{{--
    One account, and what can be done to it (SPEC §13.2, §4.1).

    The three dangerous buttons — extend, suspend, impersonate — each sit
    behind a <details> with their own reason box. Not to be tidy: a form that
    is already open is a form that gets submitted by a stray Enter, and these
    three change what somebody has paid for or put us inside their account.
--}}
@extends('layouts.admin')

@section('title', $tenant->name.' — '.config('app.name'))

@section('main')
    <div class="page-head">
        <h1>{{ $tenant->name }}</h1>
        <p>
            <a href="{{ route('public.booking', $tenant) }}">/reservation/{{ $tenant->slug }}</a>
            · created {{ $tenant->created_at->format('j M Y') }}
        </p>
    </div>

    <div class="stack" style="max-width:860px">

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">Where they stand</h2>
                <span class="pill {{ $tenant->status->acceptsBookings() ? 'pill--ok' : 'pill--warn' }}">
                    {{ $tenant->status->label() }}
                </span>
            </div>

            <div class="facts">
                <div>
                    <span class="label">Plan</span>
                    <strong>{{ $subscription?->plan?->name ?? 'None' }}</strong>
                </div>
                <div>
                    <span class="label">Paying</span>
                    <strong>
                        {{ $subscription?->price() ?? '—' }}
                        <small class="muted">{{ $subscription?->plan?->billing_period->per() }}</small>
                    </strong>
                </div>
                <div>
                    <span class="label">
                        {{ $tenant->isOnTrial() ? 'Trial ends' : 'Period ends' }}
                    </span>
                    <strong>
                        {{ $tenant->isOnTrial()
                            ? $tenant->trial_ends_at?->format('j M Y')
                            : ($subscription?->current_period_end?->format('j M Y') ?? '—') }}
                    </strong>
                </div>
                <div>
                    <span class="label">Through</span>
                    <strong>{{ $subscription?->provider?->label() ?? '—' }}</strong>
                </div>
            </div>

            @if ($subscription?->payment_failed_at !== null)
                <p class="notice notice--warn">
                    Payment failed {{ $subscription->payment_failed_at->diffForHumans() }}.
                    @if ($subscription->dunning_sent_for !== null)
                        Chased on day {{ $subscription->dunning_sent_for }}.
                    @endif
                </p>
            @endif

            @if ($subscription?->cancel_at_period_end)
                <p class="notice notice--warn">
                    Cancelled — it runs until
                    {{ $subscription->current_period_end?->format('j M Y') }} and will not renew.
                </p>
            @endif
        </section>

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">What they are using</h2>
            </div>

            <div class="facts">
                <div>
                    <span class="label">Tables / sunbeds</span>
                    <strong>{{ $usage['resources'] }}</strong>
                </div>
                <div>
                    <span class="label">Bookings this month</span>
                    <strong>{{ $usage['reservations_this_month'] }}</strong>
                </div>
                <div>
                    <span class="label">Bookings all time</span>
                    <strong>{{ $usage['reservations_total'] }}</strong>
                </div>
                <div>
                    <span class="label">People on the account</span>
                    <strong>{{ $tenant->users->count() }}</strong>
                </div>
            </div>

            <p class="muted">
                @foreach ($tenant->users as $person)
                    {{ $person->name }} &lt;{{ $person->email }}&gt;
                    <span class="pill pill--muted">{{ $person->pivot->role }}</span>
                    @if (! $loop->last) · @endif
                @endforeach
            </p>
        </section>

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">Things you can do</h2>
            </div>

            {{-- A free month, a bank transfer, a goodwill fortnight (§4.1). --}}
            <details class="secret">
                <summary><strong>Extend the subscription</strong></summary>

                <p class="muted">
                    Moves the period end forward. Use it for a transfer that arrived
                    outside the system, or for time given as goodwill. It clears any
                    failed payment, so the chasing emails stop.
                </p>

                @if ($subscription === null)
                    <p class="notice notice--warn">
                        This account has no subscription yet, so there is no period to
                        move. Put them on a plan first.
                    </p>
                @else
                    <form method="POST" action="{{ route('admin.tenants.extend', $tenant) }}"
                          class="stack">
                        @csrf

                        <div class="field">
                            <label for="until">New period end</label>
                            <input id="until" type="date" name="until" required
                                   min="{{ now()->addDay()->format('Y-m-d') }}"
                                   value="{{ old('until', $subscription->current_period_end?->addMonth()->format('Y-m-d')) }}">
                        </div>

                        <div class="field">
                            <label for="extend-reason">Why</label>
                            <input id="extend-reason" type="text" name="reason" required
                                   maxlength="500"
                                   placeholder="Paid by transfer, invoice 2026-114"
                                   value="{{ old('reason') }}">
                            <small class="muted">
                                Goes in the log with your name on it, and in their billing
                                history.
                            </small>
                        </div>

                        <button type="submit" class="btn btn--primary">Extend</button>
                    </form>
                @endif
            </details>

            {{-- Signing in as them, for support (§2.1). --}}
            <details class="secret">
                <summary><strong>Sign in as this venue</strong></summary>

                <p class="muted">
                    You will see their dashboard as the owner sees it, with a bar across
                    the top to come back. Everything you do will look like the owner
                    doing it everywhere except the log, which is why the reason matters.
                </p>

                <form method="POST" action="{{ route('admin.tenants.impersonate', $tenant) }}"
                      class="stack">
                    @csrf

                    <div class="field">
                        <label for="impersonate-reason">Why</label>
                        <input id="impersonate-reason" type="text" name="reason" required
                               maxlength="500"
                               placeholder="Can't find a booking they rang about"
                               value="{{ old('reason') }}">
                    </div>

                    <button type="submit" class="btn">Sign in as {{ $tenant->name }}</button>
                </form>
            </details>

            {{-- Off by hand, and only back on by hand (§2.1). --}}
            @if ($tenant->status === \App\Enums\TenantStatus::Suspended)
                <details class="secret">
                    <summary><strong>Restore this account</strong></summary>

                    <p class="muted">
                        It comes back at whatever its subscription justifies today, not at
                        what it was when it was suspended.
                    </p>

                    <form method="POST" action="{{ route('admin.tenants.restore', $tenant) }}"
                          class="stack">
                        @csrf

                        <div class="field">
                            <label for="restore-reason">Why (optional)</label>
                            <input id="restore-reason" type="text" name="reason" maxlength="500"
                                   placeholder="Dispute resolved">
                        </div>

                        <button type="submit" class="btn btn--primary">Restore</button>
                    </form>
                </details>
            @else
                <details class="secret">
                    <summary><strong>Suspend this account</strong></summary>

                    <p class="muted">
                        Stops the booking page and makes the dashboard read-only, and
                        <strong>nothing automatic will undo it</strong> — not a payment,
                        not the nightly sweep. Only a person, here. For a chargeback or an
                        abuse report, not for an unpaid invoice: that is what expiry is
                        for.
                    </p>

                    <form method="POST" action="{{ route('admin.tenants.suspend', $tenant) }}"
                          class="stack">
                        @csrf

                        <div class="field">
                            <label for="suspend-reason">Why</label>
                            <input id="suspend-reason" type="text" name="reason" required
                                   maxlength="500" placeholder="Chargeback dispute"
                                   value="{{ old('reason') }}">
                        </div>

                        <button type="submit" class="btn">Suspend</button>
                    </form>
                </details>
            @endif
        </section>

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">What has been done to this account</h2>
                <a href="{{ route('admin.audit', ['tenant' => $tenant->id]) }}" class="muted">
                    Filter the log
                </a>
            </div>

            @forelse ($history as $entry)
                <div class="history__row">
                    <div>
                        <strong>{{ $entry->action->label() }}</strong>
                        <p class="muted" style="margin:4px 0 0">{{ $entry->summary }}</p>
                        @if (filled($entry->reason))
                            <p class="muted" style="margin:2px 0 0">{{ $entry->reason }}</p>
                        @endif
                    </div>
                    <div class="event__meta">
                        <small class="muted">{{ $entry->who() }}</small>
                        <small class="muted">{{ $entry->occurred_at->format('j M Y, H:i') }}</small>
                    </div>
                </div>
            @empty
                <p class="empty">Nothing has been done by hand to this account.</p>
            @endforelse
        </section>
    </div>
@endsection
