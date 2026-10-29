{{--
    One booking (SPEC §13.1, §24.5).

    Opened with a telephone in somebody's hand, nearly always. So the number
    is a button rather than a line of text, the note is where the eye lands,
    and the two things that get done here — seat them, or cancel after a call
    — are at the top where a thumb reaches.

    Cancelling is behind a fold. It is a form with a refund in it on a page
    people open to read, and a destructive control that is permanently open
    is one that eventually gets used by accident.
--}}
@extends('layouts.dashboard')

@section('title', $reservation->customer_name)

@section('main')
    <p class="muted" style="margin:0 0 10px">
        <a href="{{ route('dashboard.today') }}">Today</a>
    </p>

    <div class="person">
        <span class="person__avatar @if ($guest?->warning()) person__avatar--warn @endif" aria-hidden="true">
            {{ \App\Support\Initials::of($reservation->customer_name) }}
        </span>

        <div class="person__who">
            <h1>
                @if ($guest !== null)
                    <a href="{{ route('dashboard.guests.show', $guest) }}" style="color:inherit">
                        {{ $reservation->customer_name }}
                    </a>
                @else
                    {{ $reservation->customer_name }}
                @endif
            </h1>

            <p class="person__contact">
                <strong>{{ $reservation->startsAtLocal($timezone)->format('l j F · H:i') }}</strong>
                <span class="muted">to {{ $reservation->endsAtLocal($timezone)->format('H:i') }}</span>
                <span class="muted">{{ $reservation->code }}</span>
            </p>

            <p class="person__flags">
                <span class="pill {{ $statusSkins[$reservation->status->value] ?? '' }}">
                    {{ $reservation->status->label() }}
                </span>

                @if ($guest?->warning())
                    <span class="flag flag--warn">{{ $guest->warning() }}</span>
                @elseif ($guest?->visitLabel())
                    <span class="flag">{{ $guest->visitLabel() }}</span>
                @endif

                @if ($guest !== null)
                    <a class="muted" href="{{ route('dashboard.guests.show', $guest) }}"
                       style="font-size:var(--step--2)">see their history</a>
                @endif
            </p>
        </div>

        {{-- The two things that get done here, where a thumb reaches. --}}
        @unless ($reservation->status->isCancelled())
            <div class="row">
                @unless ($reservation->isCheckedIn())
                    <form method="POST" action="{{ route('dashboard.reservations.check-in', $reservation) }}">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="btn">Check in</button>
                    </form>
                @endunless

                {{--
                    A no-show feeds the number an owner will act on later, so it
                    is reversible: staff mark it from across a busy room and are
                    sometimes wrong (SPEC §28.3).
                --}}
                @if ($reservation->status === \App\Enums\ReservationStatus::NoShow)
                    <form method="POST" action="{{ route('dashboard.reservations.came-anyway', $reservation) }}">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="btn btn--ghost">They came after all</button>
                    </form>
                @elseif ($reservation->starts_at->isPast())
                    <form method="POST" action="{{ route('dashboard.reservations.no-show', $reservation) }}">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="btn btn--ghost">Nobody came</button>
                    </form>
                @endif
            </div>
        @endunless
    </div>

    {{--
        The one case where a booking needs a phone call rather than a click: the
        guest mistyped their address, so they have no confirmation and no idea
        anything is wrong (SPEC §10.3).
    --}}
    @if (in_array($reservation->voucher_delivery, ['bounced', 'complained'], true))
        <div class="notice notice--warn" style="margin-bottom:16px">
            <strong>Their confirmation never arrived.</strong>
            The email to {{ $reservation->customer_email }} came back
            {{ $reservation->voucher_delivery === 'bounced' ? 'undelivered' : 'marked as spam' }}.
            The booking still stands — worth ringing them to confirm.
        </div>
    @endif

    @if ($payment?->refund_failure_reason)
        <div class="notice notice--warn" style="margin-bottom:16px">
            <strong>The last refund failed.</strong>
            {{ $payment->refund_failure_reason }} The money is back with your gateway —
            you will need to arrange a transfer with the guest.
        </div>
    @endif

    @if (filled($reservation->notes))
        {{-- Above everything else it is not: this is the line the kitchen
             and the floor have to see before the guest arrives. --}}
        <div class="notice notice--warn" style="margin-bottom:16px">
            <strong>They asked for this.</strong> {{ $reservation->notes }}
        </div>
    @endif

    <div class="stat-grid" style="margin-bottom:16px">
        <div class="stat">
            <b>{{ $reservation->resource_snapshot['label'] ?? ($bookingType?->name ?? '—') }}</b>
            {{-- The snapshot first: it says where they sat when they booked,
                 and stays true after the owner renames or rebuilds an area
                 (SPEC §14). --}}
            <span class="muted">
                {{ $reservation->resource_snapshot['area'] ?? ($area?->name ?? ($bookingType?->name ?? 'Where')) }}
            </span>
            @if ($reservation->snapshotContents() !== '')
                <small class="stat__note">{{ $reservation->snapshotContents() }}</small>
            @endif
        </div>

        <div class="stat">
            <b>{{ $reservation->party_size }}</b>
            <span class="muted">{{ \Illuminate\Support\Str::plural('guest', $reservation->party_size) }}</span>
        </div>

        <div class="stat">
            <b>
                @if ($reservation->amount_cents > 0)
                    {{ \App\Support\Money::format($reservation->amount_cents) }}
                @else
                    —
                @endif
            </b>
            <span class="muted">
                {{ $reservation->amount_cents > 0 ? $reservation->payment_status->label() : 'No payment' }}
            </span>
            @if ($payment?->refunded_amount_cents > 0)
                <small class="stat__note">
                    {{ \App\Support\Money::format((int) $payment->refunded_amount_cents) }} refunded
                    @if (filled($payment->refund_reference))
                        · ref {{ $payment->refund_reference }}
                    @endif
                </small>
            @endif
        </div>

        <div class="stat">
            <b>{{ $reservation->channel->label() }}</b>
            <span class="muted">how it came in</span>
            <small class="stat__note">
                {{ $reservation->created_at?->setTimezone($timezone)->format('j M, H:i') }}
            </small>
        </div>
    </div>

    <div class="work">
        <div>
            <section class="card">
                <div class="card__head">
                    <h2 class="h2">Reaching them</h2>
                </div>

                {{-- A number you can press, not a number you can read. --}}
                <div class="row" style="gap:8px;margin-bottom:14px">
                    @if (filled($reservation->customer_phone))
                        <a class="btn" href="tel:{{ $reservation->customer_phone }}">
                            Call {{ $reservation->customer_phone }}
                        </a>
                    @endif

                    @if (filled($reservation->customer_email))
                        <a class="btn btn--ghost" href="mailto:{{ $reservation->customer_email }}">
                            {{ $reservation->customer_email }}
                        </a>
                    @endif
                </div>

                @if (blank($reservation->customer_phone) && blank($reservation->customer_email))
                    <p class="muted" style="margin:0">
                        No way to reach this guest — it was taken without either.
                    </p>
                @endif
            </section>
        </div>

        <aside class="work__rail">
            <section class="card">
                <div class="card__head">
                    <h2 class="h2">Their voucher</h2>
                </div>

                <div class="needs">
                    <span class="needs__dot {{ $reservation->voucher_sent_at === null ? 'needs__dot--table' : '' }}"
                          aria-hidden="true"></span>
                    <span class="needs__text">
                        @if ($reservation->voucher_sent_at === null)
                            <strong>Not sent</strong>
                            <small class="muted">No email address, or it has not gone yet.</small>
                        @else
                            <strong>
                                Sent {{ $reservation->voucher_sent_at->setTimezone($timezone)->format('j M, H:i') }}
                            </strong>
                            <small class="muted">
                                @if ($reservation->voucher_delivery === 'delivered')
                                    Delivered to their inbox.
                                @else
                                    No delivery confirmation yet.
                                @endif
                            </small>
                        @endif
                    </span>
                </div>

                <p style="margin:12px 0 0">
                    <a href="{{ \App\Vouchers\VoucherToken::url($reservation) }}" target="_blank" rel="noopener">
                        Open what the guest sees
                    </a>
                </p>
            </section>

            @unless ($reservation->status->isCancelled())
                {{--
                    Behind a fold. A form with a refund in it, permanently
                    open on a page people come to read, is a control that
                    eventually gets used by accident.
                --}}
                <details class="advanced card">
                    <summary>Cancel this booking</summary>

                    <p class="muted" style="margin:10px 0 0;font-size:var(--step--2)">
                        After a phone call — guests cannot cancel online.
                    </p>

                    @if ($canRefund && $payment?->qualifiesAsReversal())
                        <div class="notice" style="margin:12px 0 0">
                            Refunding now costs nothing: this soon after the charge your gateway
                            treats it as a reversal, and the fee comes back too.
                        </div>
                    @elseif ($canRefund)
                        <div class="notice" style="margin:12px 0 0">
                            The payment fee is not returned by the gateway, so a full refund
                            leaves you a little out of pocket.
                        </div>
                    @endif

                    <form method="POST" action="{{ route('dashboard.reservations.cancel', $reservation) }}"
                          class="stack" style="margin-top:14px">
                        @csrf

                        <div>
                            <label class="label" for="reason">Why</label>
                            <input class="field" type="text" id="reason" name="reason" required maxlength="160"
                                   placeholder="Guest called to cancel" value="{{ old('reason') }}">
                        </div>

                        @if ($canRefund)
                            <div>
                                <span class="label">Refund</span>

                                <div class="stack" style="gap:8px">
                                    <label class="consent">
                                        <input type="radio" name="refund" value="none" checked>
                                        <span>No refund</span>
                                    </label>

                                    <label class="consent">
                                        <input type="radio" name="refund" value="full">
                                        <span>
                                            Full — {{ \App\Support\Money::format($refundableCents) }}
                                        </span>
                                    </label>

                                    <label class="consent">
                                        <input type="radio" name="refund" value="partial">
                                        <span style="flex:1">
                                            Part of it
                                            <input class="field" type="number" name="amount" step="0.50"
                                                   min="0.50" max="{{ $refundableCents / 100 }}"
                                                   style="margin-top:8px;max-width:160px"
                                                   placeholder="{{ number_format($refundableCents / 200, 2) }}">
                                        </span>
                                    </label>
                                </div>
                            </div>
                        @else
                            <input type="hidden" name="refund" value="none">

                            @if ($reservation->amount_cents > 0)
                                <p class="muted" style="margin:0">
                                    Nothing to refund here — the guest pays you at the venue.
                                </p>
                            @endif
                        @endif

                        <div>
                            <button type="submit" class="btn">Cancel the booking</button>
                        </div>
                    </form>
                </details>
            @endunless
        </aside>
    </div>
@endsection
