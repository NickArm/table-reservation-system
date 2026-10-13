@extends('layouts.base', ['accent' => $venue->accent_color])

@section('title', 'Finishing up — '.$venue->name)

@section('body')
    <main class="shell shell--narrow" style="padding-block:60px">
        <div class="card">
            <p class="muted" style="margin:0 0 4px">{{ $venue->name }}</p>
            <h1 style="margin:0 0 14px;font-size:var(--step-2);letter-spacing:-0.02em">
                We are confirming your payment
            </h1>

            <p style="margin:0 0 16px">
                Your booking is held under code <strong>{{ $reservation->code }}</strong>.
                Banks sometimes take a minute to tell us the payment went through —
                refresh this page in a moment and it will be confirmed.
            </p>

            <p class="notice" style="margin:0 0 16px">
                Nothing more is needed from you. If anything goes wrong, you are not
                charged.
            </p>

            <a class="btn btn--block" href="{{ url()->current() }}">Check again</a>

            @if (filled($venue->phone))
                <p class="muted" style="margin:16px 0 0">
                    Worried? Call us on <a href="tel:{{ $venue->phone }}">{{ $venue->phone }}</a>.
                </p>
            @endif
        </div>
    </main>
@endsection
