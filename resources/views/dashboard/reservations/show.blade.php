@extends('layouts.dashboard')

@section('title', $reservation->customer_name)

@section('main')
    <div class="page-head">
        <div>
            <p class="muted" style="margin:0 0 4px">
                <a href="{{ route('dashboard.today') }}">Today</a>
            </p>
            <h1>{{ $reservation->customer_name }}</h1>
            <p class="muted">
                {{ $reservation->startsAtLocal($timezone)->format('l j F · H:i') }}
                · {{ $reservation->code }}
            </p>
        </div>

        <span class="pill @if ($reservation->isCheckedIn()) pill--ok @endif">
            {{ $reservation->status->label() }}
        </span>
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

    <section class="card" style="margin-bottom:16px">
        <dl class="facts">
            <dt>What</dt>
            <dd>
                {{ $reservation->resource_snapshot['label'] ?? ($bookingType?->name ?? '—') }}
                @if ($area !== null) · {{ $area->name }} @endif
            </dd>

            @if ($reservation->snapshotContents() !== '')
                <dt>Includes</dt>
                <dd>{{ $reservation->snapshotContents() }}</dd>
            @endif

            <dt>Guests</dt>
            <dd>{{ $reservation->party_size }}</dd>

            <dt>Phone</dt>
            <dd>
                @if (filled($reservation->customer_phone))
                    <a href="tel:{{ $reservation->customer_phone }}">{{ $reservation->customer_phone }}</a>
                @else
                    —
                @endif
            </dd>

            <dt>Email</dt>
            <dd>{{ $reservation->customer_email }}</dd>

            @if (filled($reservation->notes))
                <dt>Note</dt>
                <dd>{{ $reservation->notes }}</dd>
            @endif

            <dt>Money</dt>
            <dd>
                @if ($reservation->amount_cents > 0)
                    {{ \App\Support\Money::format($reservation->amount_cents) }}
                    · {{ $reservation->payment_status->label() }}
                    @if ($payment?->refunded_amount_cents > 0)
                        <small class="muted">
                            {{ \App\Support\Money::format((int) $payment->refunded_amount_cents) }} refunded
                            @if (filled($payment->refund_reference))
                                · ref {{ $payment->refund_reference }}
                            @endif
                        </small>
                    @endif
                @else
                    No payment
                @endif
            </dd>

            <dt>Booked</dt>
            <dd>{{ $reservation->created_at?->setTimezone($timezone)->format('j M, H:i') }}
                · {{ $reservation->channel->label() }}</dd>

            <dt>Voucher</dt>
            <dd>
                @if ($reservation->voucher_sent_at === null)
                    <span class="muted">Not sent</span>
                @else
                    Sent {{ $reservation->voucher_sent_at->setTimezone($timezone)->format('j M, H:i') }}
                    @if ($reservation->voucher_delivery === 'delivered')
                        · delivered
                    @endif
                @endif
                <small>
                    <a href="{{ \App\Vouchers\VoucherToken::url($reservation) }}" target="_blank" rel="noopener">
                        Open what the guest sees
                    </a>
                </small>
            </dd>
        </dl>

        @if (! $reservation->isCheckedIn() && ! $reservation->status->isCancelled())
            <form method="POST" action="{{ route('dashboard.reservations.check-in', $reservation) }}"
                  style="margin-top:18px">
                @csrf
                @method('PATCH')
                <button type="submit" class="btn">Check in</button>
            </form>
        @endif
    </section>

    @if ($payment?->refund_failure_reason)
        <div class="notice notice--warn" style="margin-bottom:16px">
            <strong>The last refund failed.</strong>
            {{ $payment->refund_failure_reason }} The money is back with your gateway —
            you will need to arrange a transfer with the guest.
        </div>
    @endif

    @unless ($reservation->status->isCancelled())
        <section class="card">
            <div class="card__head">
                <h2 class="h2">Cancel this booking</h2>
                <span class="muted">After a phone call — guests cannot cancel online.</span>
            </div>

            @if ($canRefund && $payment?->qualifiesAsReversal())
                <div class="notice" style="margin-bottom:14px">
                    Refunding now costs nothing: this soon after the charge your gateway
                    treats it as a reversal, and the fee comes back too.
                </div>
            @elseif ($canRefund)
                <div class="notice" style="margin-bottom:14px">
                    The payment fee is not returned by the gateway, so a full refund
                    leaves you a little out of pocket.
                </div>
            @endif

            <form method="POST" action="{{ route('dashboard.reservations.cancel', $reservation) }}"
                  class="stack">
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
        </section>
    @endunless
@endsection
