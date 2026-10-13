@extends('layouts.base', ['accent' => $venue->accent_color])

@section('title', 'Booked — '.$venue->name)

@section('body')
    <main class="shell" style="max-width:560px">
        <div class="card" style="margin-top:40px">
            <p class="muted" style="margin:0 0 4px">{{ $venue->name }}</p>
            <h1 style="margin:0 0 18px;font-size:var(--step-2);letter-spacing:-0.02em">
                You are booked
            </h1>

            <div class="code">
                <span class="muted">Your code</span>
                <strong>{{ $reservation->code }}</strong>
            </div>

            <dl class="facts" style="margin-top:20px">
                <dt>When</dt>
                <dd>
                    {{ $reservation->startsAtLocal($timezone)->format('l j F') }},
                    {{ $reservation->startsAtLocal($timezone)->format('H:i') }}
                </dd>

                <dt>What</dt>
                <dd>
                    {{ $reservation->resource_snapshot['label'] ?? $bookingType->name }}
                    @if ($reservation->resource_snapshot['area'] ?? null)
                        · {{ $reservation->resource_snapshot['area'] }}
                    @endif
                </dd>

                @if ($reservation->snapshotContents() !== '')
                    <dt>Includes</dt>
                    <dd>{{ $reservation->snapshotContents() }}</dd>
                @endif

                <dt>Who</dt>
                <dd>
                    {{ $reservation->customer_name }} ·
                    {{ $reservation->party_size }}
                    {{ \Illuminate\Support\Str::plural('person', $reservation->party_size) }}
                </dd>

                @if ($reservation->amount_cents > 0)
                    <dt>To pay</dt>
                    <dd>
                        {{ \App\Support\Money::format($reservation->amount_cents) }}
                        @if (filled($reservation->deposit_label_snapshot))
                            <small class="muted">{{ $reservation->deposit_label_snapshot }}</small>
                        @endif
                    </dd>
                @endif

                @if (filled($reservation->notes))
                    <dt>Your note</dt>
                    <dd>{{ $reservation->notes }}</dd>
                @endif
            </dl>

            {{--
                The same QR that is in the email. Most guests will show this
                screen rather than dig the email out, so it belongs here too.
            --}}
            <div style="display:flex;justify-content:center;padding:22px 0 6px">
                {!! \App\Support\QrCode::svg($voucher->url(), 168) !!}
            </div>

            <p class="muted" style="margin:0;text-align:center">
                Show this at the door. We have emailed it to
                {{ $reservation->customer_email }} as well.
            </p>
        </div>

        <div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap">
            <a class="btn btn--ghost" style="flex:1" href="{{ $voucher->url() }}">Open your voucher</a>
            <a class="btn btn--ghost" style="flex:1"
               href="{{ route('voucher.ics', ['code' => $reservation->code, 'token' => \App\Vouchers\VoucherToken::for($reservation)]) }}">
                Add to calendar
            </a>
        </div>

        <section class="card" style="margin-top:16px">
            <h2 class="h2" style="margin-bottom:8px">To change or cancel</h2>
            <p style="margin:0 0 12px">
                Please call us — we do not have an online cancellation yet, and a
                phone call is faster anyway.
            </p>

            @if (filled($venue->phone))
                <a class="btn btn--block" href="tel:{{ $venue->phone }}">{{ $venue->phone }}</a>
            @endif

            <p class="muted" style="margin:14px 0 0">{{ $cancellationPolicy }}</p>
        </section>

        @if (filled($venue->address) || filled($venue->city))
            <section class="card" style="margin-top:16px">
                <h2 class="h2" style="margin-bottom:8px">Finding us</h2>
                <p style="margin:0">
                    {{ $venue->address }}@if (filled($venue->city)), {{ $venue->city }}@endif
                </p>

                @if ($venue->latitude !== null && $venue->longitude !== null)
                    <p style="margin:10px 0 0">
                        <a href="https://www.openstreetmap.org/?mlat={{ $venue->latitude }}&mlon={{ $venue->longitude }}#map=17/{{ $venue->latitude }}/{{ $venue->longitude }}"
                           rel="noopener noreferrer" target="_blank">Open the map</a>
                    </p>
                @endif
            </section>
        @endif

        <p class="muted" style="margin-top:28px">
            This is a booking confirmation, not a tax document.
        </p>
    </main>
@endsection
