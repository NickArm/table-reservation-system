{{--
    Billing (SPEC §4).

    Three questions in order: where do I stand, what are the options, and what
    has happened. An owner arrives here either because a banner sent them or
    because something went wrong with a payment, and in both cases the first
    thing they need is a plain sentence about their own account.
--}}
@extends('layouts.dashboard')

@section('title', 'Billing — '.$tenant->name)

@section('main')
    @include('partials.crumb', ['trail' => [
        ['Setup', route('dashboard.setup')],
        ['Billing', null],
    ]])

    <div class="page-head">
        <h1>Billing</h1>
        <p>Your subscription, and what it covers.</p>
    </div>

    <div class="stack" style="max-width:760px">

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">Where you stand</h2>
                <span class="pill {{ $tenant->status->acceptsBookings() ? 'pill--ok' : 'pill--warn' }}">
                    {{ $tenant->status->label() }}
                </span>
            </div>

            @if ($entitlements->isOnTrial())
                <p>
                    <strong>{{ $tenant->trialDaysLeft() }} days left in your trial.</strong>
                    Everything is switched on, and no card has been asked for.
                </p>
                <p class="muted">
                    When it ends, your booking page stops taking new reservations. Nothing
                    is deleted — the bookings you already have stay valid, and your setup
                    waits for you.
                </p>
            @elseif ($subscription !== null && $subscription->isCurrent())
                <p>
                    On <strong>{{ $plan?->name ?? 'a plan' }}</strong> at
                    {{ $subscription->price() }} {{ $plan?->billing_period->per() }}.
                    @if ($subscription->cancel_at_period_end)
                        Cancelled — it runs until
                        <strong>{{ $subscription->current_period_end?->format('j M Y') }}</strong>
                        and will not renew.
                    @else
                        Renews on
                        <strong>{{ $subscription->current_period_end?->format('j M Y') }}</strong>.
                    @endif
                </p>

                @if ($subscription->payment_failed_at !== null)
                    <p class="notice notice--warn">
                        The last payment did not go through. Bookings keep working for now —
                        update the card and nothing else changes.
                    </p>
                @endif
            @else
                <p class="notice notice--warn">
                    <strong>Your subscription has run out.</strong>
                    Your booking page is not taking new reservations, and the dashboard is
                    read-only.
                </p>
                <p class="muted">
                    Nothing has been deleted. Bookings already made are still listed and
                    still valid, your export still works, and everything you set up is kept
                    for twelve months — so a venue that closes for the winter comes back in
                    April to exactly what it had.
                </p>
            @endif
        </section>

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">Plans</h2>
            </div>

            @if ($testBilling && $provider !== null)
                <p class="notice notice--warn">
                    <strong>Test mode.</strong>
                    No real money moves. Anything you subscribe to here is a test
                    subscription at the payment provider.
                </p>
            @endif

            @forelse ($plans as $option)
                @php
                    $isCurrent = $plan?->is($option) && ($subscription?->isCurrent() ?? false);
                    $canBuy = $provider !== null
                        && $option->sellableThrough($provider)
                        && ! $option->isFree()
                        && (! $isCurrent || ($subscription?->cancel_at_period_end ?? false));
                @endphp

                <div class="plan {{ $plan?->is($option) ? 'plan--current' : '' }}">
                    <div>
                        <strong>{{ $option->name }}</strong>
                        @if ($plan?->is($option))
                            <span class="pill pill--ok">Your plan</span>
                        @endif
                        @unless ($option->is_public)
                            <span class="pill pill--muted">Just for you</span>
                        @endunless

                        <p class="muted" style="margin:6px 0 0">{{ $option->description }}</p>
                    </div>

                    <div class="plan__price">
                        <strong>{{ $option->price() }}</strong>
                        <small class="muted">{{ $option->billing_period->per() }}</small>

                        @if ($option->billing_period === \App\Enums\BillingPeriod::Yearly)
                            <small class="muted">
                                about {{ $option->monthlyEquivalent() }} a month
                            </small>
                        @endif

                        @if ($canBuy)
                            {{--
                                One button, one plan. It is a form rather than a
                                link because it starts a subscription, and a GET
                                that charges money is a GET a browser will
                                happily make twice.
                            --}}
                            <form method="POST"
                                  action="{{ route('dashboard.billing.checkout', $option) }}">
                                @csrf
                                <button type="submit" class="btn">
                                    @if ($isCurrent)
                                        Keep this plan
                                    @elseif ($plan === null)
                                        Choose this plan
                                    @else
                                        Switch to this
                                    @endif
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            @empty
                <p class="empty">No plans are on offer at the moment.</p>
            @endforelse

            @if ($provider === null)
                <p class="notice">
                    Online payment for subscriptions is not switched on yet. Reply to any
                    of our emails and we will sort your plan out with you directly.
                </p>
            @endif
        </section>

        @if ($subscription !== null && $subscription->isCurrent() && ! $subscription->cancel_at_period_end)
            <section class="card stack">
                <div class="card__head">
                    <h2 class="h2">Stopping</h2>
                </div>

                <p class="muted">
                    You can stop whenever you like. You keep everything until
                    <strong>{{ $subscription->current_period_end?->format('j M Y') }}</strong>,
                    which you have already paid for, and nothing is deleted after that —
                    your setup waits twelve months in case you come back.
                </p>

                <form method="POST" action="{{ route('dashboard.billing.cancel') }}">
                    @csrf
                    <button type="submit" class="btn">Cancel my subscription</button>
                </form>
            </section>
        @endif

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">What has happened</h2>
            </div>

            @forelse ($history as $event)
                <div class="event">
                    <div>
                        <strong>{{ $event->type->label() }}</strong>
                        @if ($event->wasByHand())
                            <span class="pill pill--muted">by support</span>
                        @endif

                        <p class="muted" style="margin:4px 0 0">{{ $event->summary }}</p>

                        @if (filled($event->reason))
                            <p class="muted" style="margin:4px 0 0">
                                Reason: {{ $event->reason }}
                            </p>
                        @endif
                    </div>

                    <div class="event__meta">
                        @if ($event->amount() !== null)
                            <strong>{{ $event->amount() }}</strong>
                        @endif
                        <small class="muted">{{ $event->occurred_at->format('j M Y') }}</small>
                    </div>
                </div>
            @empty
                <p class="empty">Nothing yet. Your trial has not been billed for.</p>
            @endforelse
        </section>
    </div>
@endsection
