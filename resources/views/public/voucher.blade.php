{{--
    The voucher as the guest holds it at the door (SPEC §11.2).

    This page is read outdoors, in sunlight, by someone who has just been asked
    for their booking. So the code is the largest thing on it, the status is a
    colour you can read at arm's length, and nothing below the fold is needed
    to get in.
--}}
@extends('layouts.base', ['accent' => $venue->accent_color])

@section('title', $voucher->reservation->code.' · '.$venue->name)

@section('body')
    <main class="shell" style="max-width:520px">

        @php
            $reservation = $voucher->reservation;
            $status = $reservation->status;
            $cancelled = $status->isCancelled();
        @endphp

        <div class="card" style="margin-top:32px">
            <p class="muted" style="margin:0 0 4px">{{ $venue->name }}</p>

            <div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap">
                <h1 style="margin:0;font-size:var(--step-2);letter-spacing:-0.02em">
                    {{ $cancelled ? 'Cancelled' : $voucher->startsAt()->format('H:i') }}
                </h1>
                <span class="pill {{ $cancelled ? '' : 'pill--ok' }}">{{ $status->label() }}</span>
            </div>

            <p class="muted" style="margin:2px 0 18px">
                {{ $voucher->startsAt()->format('l j F Y') }}
            </p>

            @if ($cancelled)
                <div class="notice notice--warn" style="margin-bottom:18px">
                    This booking has been cancelled.
                    @if (filled($reservation->cancellation_reason))
                        {{ $reservation->cancellation_reason }}
                    @endif
                </div>
            @endif

            <div class="code">
                <span class="muted">Booking code</span>
                <strong>{{ $reservation->code }}</strong>
            </div>

            <div style="display:flex;justify-content:center;padding:18px 0 4px">
                {!! \App\Support\QrCode::svg($voucher->url(), 196) !!}
            </div>

            <dl class="facts" style="margin-top:14px">
                <dt>What</dt>
                <dd>{{ $voucher->what() }}</dd>

                @if ($voucher->includes() !== '')
                    <dt>Includes</dt>
                    <dd>{{ $voucher->includes() }}</dd>
                @endif

                <dt>Guests</dt>
                <dd>{{ $reservation->customer_name }} · {{ $voucher->party() }}</dd>

                @if ($voucher->amount() !== null)
                    <dt>{{ $voucher->amountLabel() }}</dt>
                    <dd>
                        {{ $voucher->amount() }}
                        @if ($voucher->depositLabel() !== null)
                            <small class="muted">{{ $voucher->depositLabel() }}</small>
                        @endif
                    </dd>
                @endif

                @if (filled($reservation->notes))
                    <dt>Your note</dt>
                    <dd>{{ $reservation->notes }}</dd>
                @endif
            </dl>
        </div>

        @if ($canCheckIn && ! $cancelled)
            {{--
                Staff of this venue, looking at a guest's voucher. Check-in is
                idempotent, so a second tap is harmless — which is the whole
                reason a door can be run from a phone camera (SPEC §32.4).
            --}}
            <form class="card" style="margin-top:16px"
                  method="POST" action="{{ route('dashboard.reservations.check-in', $reservation) }}">
                @csrf
                @method('PATCH')

                @if ($reservation->isCheckedIn())
                    <p style="margin:0" class="muted">
                        Checked in at {{ $reservation->checked_in_at?->setTimezone($venue->timezone)->format('H:i') }}.
                    </p>
                @else
                    <button class="btn btn--block" type="submit">Check in · {{ $voucher->party() }}</button>
                @endif
            </form>
        @endif

        @unless ($cancelled)
            <div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap">
                <a class="btn btn--ghost" style="flex:1"
                   href="{{ route('voucher.ics', ['code' => $reservation->code, 'token' => request()->route('token')]) }}">
                    Add to calendar
                </a>
                <a class="btn btn--ghost" style="flex:1"
                   href="{{ route('voucher.pdf', ['code' => $reservation->code, 'token' => request()->route('token')]) }}">
                    Save as PDF
                </a>
            </div>

            <section class="card" style="margin-top:16px">
                <h2 class="h2" style="margin-bottom:8px">To change or cancel</h2>
                <p style="margin:0 0 12px">
                    Please call us. There is no online cancellation, and a phone
                    call is faster anyway.
                </p>

                @if (filled($venue->phone))
                    <a class="btn btn--block" href="tel:{{ $venue->phone }}">{{ $venue->phone }}</a>
                @endif

                <p class="muted" style="margin:14px 0 0">{{ $voucher->cancellationPolicy() }}</p>
            </section>
        @endunless

        @if ($voucher->address() !== null)
            <section class="card" style="margin-top:16px">
                <h2 class="h2" style="margin-bottom:8px">Finding us</h2>
                <p style="margin:0">{{ $voucher->address() }}</p>

                @if ($voucher->mapUrl() !== null)
                    <p style="margin:10px 0 0">
                        <a href="{{ $voucher->mapUrl() }}" rel="noopener noreferrer" target="_blank">Open the map</a>
                    </p>
                @endif
            </section>
        @endif

        <p class="muted" style="margin-top:28px">
            This is a booking confirmation, not a tax document.
        </p>
    </main>
@endsection
