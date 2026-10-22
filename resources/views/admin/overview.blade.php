{{--
    The platform at a glance (SPEC §13.2).

    Two questions, in order: is the money where it should be, and is there
    anybody I need to ring today. The second is why the list below the figures
    is accounts that need something rather than all of them.
--}}
@extends('layouts.admin')

@section('title', 'Platform — '.config('app.name'))

@section('main')
    <div class="page-head">
        <h1>Overview</h1>
        <p>Where the platform stands today.</p>
    </div>

    <div class="stat-grid" style="margin-bottom:32px">
        <div class="stat">
            <b>{{ \App\Support\Money::format($figures->mrrCents()) }}</b>
            <span>Monthly recurring</span>
        </div>
        <div class="stat">
            <b>{{ $figures->activeAccounts() }}</b>
            <span>Paying accounts</span>
        </div>
        <div class="stat">
            <b>{{ $figures->onTrial() }}</b>
            <span>On trial{{ $figures->trialsEndingSoon() > 0 ? ' · '.$figures->trialsEndingSoon().' ending this week' : '' }}</span>
        </div>
        <div class="stat">
            <b>{{ $figures->lapsedThisMonth() }}</b>
            <span>Lapsed this month</span>
        </div>
    </div>

    @if ($figures->failedPayments() > 0 || $figures->suspended() > 0)
        <p class="notice notice--warn" style="margin-bottom:24px">
            @if ($figures->failedPayments() > 0)
                <strong>{{ $figures->failedPayments() }}</strong>
                {{ \Illuminate\Support\Str::plural('payment', $figures->failedPayments()) }}
                outstanding.
            @endif
            @if ($figures->suspended() > 0)
                {{ $figures->suspended() }}
                {{ \Illuminate\Support\Str::plural('account', $figures->suspended()) }}
                suspended by hand.
            @endif
        </p>
    @endif

    <section class="card stack" style="margin-bottom:24px">
        <div class="card__head">
            <h2 class="h2">Worth a call</h2>
            <span class="muted">Trials about to end, and payments that bounced</span>
        </div>

        @forelse ($needAttention as $account)
            <div class="history__row">
                <div>
                    <a href="{{ route('admin.tenants.show', $account) }}"><strong>{{ $account->name }}</strong></a>
                    <span class="pill {{ $account->status->acceptsBookings() ? 'pill--ok' : 'pill--warn' }}">
                        {{ $account->status->label() }}
                    </span>

                    <p class="muted" style="margin:4px 0 0">
                        @if ($account->isOnTrial())
                            Trial ends in {{ $account->trialDaysLeft() }}
                            {{ \Illuminate\Support\Str::plural('day', $account->trialDaysLeft()) }}
                        @elseif ($account->subscription?->payment_failed_at !== null)
                            Payment failed
                            {{ $account->subscription->payment_failed_at->diffForHumans() }}
                        @else
                            {{ $account->subscription?->plan?->name ?? 'No plan' }}
                        @endif
                    </p>
                </div>

                <div class="event__meta">
                    <small class="muted">{{ $account->venue?->city ?? '' }}</small>
                </div>
            </div>
        @empty
            <p class="empty">Nothing needs chasing. Rare and worth enjoying.</p>
        @endforelse
    </section>

    <section class="card stack">
        <div class="card__head">
            <h2 class="h2">Last few things done</h2>
            <a href="{{ route('admin.audit') }}" class="muted">Full log</a>
        </div>

        @forelse ($recent as $entry)
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
                    <small class="muted">{{ $entry->occurred_at->diffForHumans() }}</small>
                </div>
            </div>
        @empty
            <p class="empty">Nothing has been done by hand yet.</p>
        @endforelse
    </section>
@endsection
