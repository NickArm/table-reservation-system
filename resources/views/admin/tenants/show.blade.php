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
    @include('partials.crumb', ['trail' => [
        ['Accounts', route('admin.tenants.index')],
        [$tenant->name, null],
    ]])

    <div class="page-head">
        <div>
            <h1>{{ $tenant->name }}</h1>
            <p class="muted">
                Account opened {{ $tenant->created_at->format('j M Y') }}
                · {{ $tenant->venues->count() }}
                {{ \Illuminate\Support\Str::plural('venue', $tenant->venues->count()) }}
                · {{ $tenant->users->count() }}
                {{ \Illuminate\Support\Str::plural('person', $tenant->users->count()) }}
            </p>
        </div>

        <span class="pill {{ $tenant->status->acceptsBookings() ? 'pill--ok' : 'pill--warn' }}">
            {{ $tenant->status->label() }}
        </span>
    </div>

    <div class="stack" style="max-width:860px">

        {{--
            The invitation link, right after it was minted.

            Shown rather than only emailed because the common case is an
            account opened during a telephone call: the operator wants to
            send it over whatever the customer actually reads, and an email
            sitting in a spam folder has not invited anybody.
        --}}
        @if (session('invitation'))
            <section class="card stack">
                <div class="card__head">
                    <h2 class="h2">The way in</h2>
                </div>

                <p class="muted">
                    Good for {{ (int) config('tenancy.invitation_days') }} days, and it
                    stops working once it has been used. Send it however they will read
                    it.
                </p>

                <p style="word-break:break-all"><code>{{ session('invitation') }}</code></p>
            </section>
        @endif

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">Where they stand</h2>
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

            {{--
                Billed by hand. Worth saying out loud on the screen: nothing
                in the system will renew this one, and the only reason it
                does not lapse quietly is the notice that goes out
                beforehand (§4.1).
            --}}
            @if ($subscription?->provider === \App\Enums\BillingProvider::Manual)
                <p class="notice">
                    <strong>This account pays you directly.</strong> Nothing will charge
                    it and nothing will chase it. You are told
                    {{ (int) config('tenancy.manual_notice_days') }} days before the
                    period ends; when the money is in, use <em>Extend the
                    subscription</em> below and put the invoice number in the reason.
                </p>
            @endif

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
            </div>
        </section>

        {{--
            The venues.

            A section of its own even though every account has exactly one
            today, because `Tenant hasMany Venue` has been true since M0 and
            the day a chain has two, the account screen must not be titled
            with whichever one happens to be oldest.
        --}}
        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">
                    {{ $tenant->venues->count() === 1 ? 'The venue' : 'The venues' }}
                </h2>
            </div>

            @forelse ($tenant->venues as $venue)
                <div class="history__row">
                    <div>
                        <strong>{{ $venue->name }}</strong>
                        <p class="muted" style="margin:4px 0 0">
                            {{ $venue->city ? $venue->city.' · ' : '' }}{{ $venue->timezone }}
                        </p>
                    </div>
                    <div class="event__meta">
                        <a href="{{ route('public.booking', $tenant) }}" class="muted">
                            /reservation/{{ $tenant->slug }}
                        </a>
                    </div>
                </div>
            @empty
                <p class="empty">No venue on this account, which should not happen.</p>
            @endforelse
        </section>

        {{--
            The people.

            Three actions, and none of them is "set their password": support
            that can type a customer's password is support that knows it, and
            from then on every action from that account is arguable (§2.3).
        --}}
        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">The people on it</h2>
            </div>

            @foreach ($tenant->users as $person)
                @php
                    // Off the pivot that is already loaded, not through
                    // roleIn(): that issues a query, and a query inside a
                    // loop is how an admin screen with four people on an
                    // account becomes twelve round trips.
                    $role = \App\Enums\TenantRole::tryFrom((string) $person->pivot->role);
                @endphp
                <div class="stack" @class(['history__row' => ! $loop->last])>
                    <div>
                        <strong>{{ $person->name }}</strong>
                        <span class="pill pill--muted">
                            {{ $role?->label() ?? 'Unknown' }}
                        </span>

                        <p class="muted" style="margin:4px 0 0">
                            {{ $person->email }}
                        </p>

                        <p class="muted" style="margin:2px 0 0;font-size:13px">
                            @if ($person->hasOpenInvitation())
                                Invited {{ $person->invitation_sent_at?->diffForHumans() }}, not arrived yet
                            @elseif ($person->last_login_at !== null)
                                Last signed in {{ $person->last_login_at->diffForHumans() }}
                            @else
                                <strong>Has never signed in</strong>
                            @endif

                            ·

                            @if ($person->hasVerifiedEmail())
                                address verified
                            @else
                                <strong>address not verified</strong>
                            @endif
                        </p>
                    </div>

                    <details class="secret">
                        <summary><strong>What you can do for them</strong></summary>

                        <div class="stack">
                            <form method="POST"
                                  action="{{ route('admin.tenants.people.reset', [$tenant, $person]) }}">
                                @csrf
                                <button type="submit" class="btn btn--ghost btn--small">
                                    Send a password reset
                                </button>
                                <small class="muted">
                                    The same email the sign-in screen sends. They choose the
                                    password, not you.
                                </small>
                            </form>

                            @unless ($person->hasVerifiedEmail())
                                <form method="POST"
                                      action="{{ route('admin.tenants.people.verify', [$tenant, $person]) }}">
                                    @csrf
                                    <button type="submit" class="btn btn--ghost btn--small">
                                        Send the verification again
                                    </button>
                                </form>
                            @endunless

                            <form method="POST"
                                  action="{{ route('admin.tenants.people.invite', [$tenant, $person]) }}">
                                @csrf
                                <button type="submit" class="btn btn--ghost btn--small">
                                    {{ $person->hasOpenInvitation() ? 'Send a fresh invitation' : 'Invite them again' }}
                                </button>
                                <small class="muted">
                                    Makes a new link and kills the old one. The new one is shown
                                    here as well as emailed.
                                </small>
                            </form>

                            <form method="POST"
                                  action="{{ route('admin.tenants.people.role', [$tenant, $person]) }}"
                                  class="stack">
                                @csrf
                                @method('PATCH')

                                <div class="field">
                                    <label for="role-{{ $person->id }}">Role on this account</label>
                                    <select id="role-{{ $person->id }}" name="role">
                                        @foreach (\App\Enums\TenantRole::cases() as $option)
                                            <option value="{{ $option->value }}"
                                                @selected($role === $option)>
                                                {{ $option->label() }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <small class="muted">
                                        Staff run the floor. They never see the payment settings
                                        or the subscription.
                                    </small>
                                </div>

                                <button type="submit" class="btn btn--ghost btn--small">Save the role</button>
                            </form>
                        </div>
                    </details>
                </div>
            @endforeach
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

                        <button type="submit" class="btn">Extend</button>
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

                        <button type="submit" class="btn">Restore</button>
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
