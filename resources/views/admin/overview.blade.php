{{--
    The platform at a glance (SPEC §13.2).

    Two questions, in order: is the money where it should be, and is there
    anybody I need to ring today. The second is why the list below the figures
    is accounts that need something rather than all of them — and why each row
    says what is wrong with it and carries the way in.
--}}
@extends('layouts.admin')

@section('title', 'Platform — '.config('app.name'))

@section('main')
    <div class="page-head">
        <div>
            <h1>Overview</h1>
            <p class="muted">Where the platform stands today.</p>
        </div>

        <a class="btn btn--ghost btn--small" href="{{ route('admin.tenants.index') }}">All accounts</a>
    </div>

    <div class="stat-grid">
        <div class="stat">
            <b>{{ \App\Support\Money::format($figures->mrrCents()) }}</b>
            <span class="muted">monthly recurring</span>
            <small class="stat__note">
                Across {{ $figures->activeAccounts() }}
                {{ \Illuminate\Support\Str::plural('account', $figures->activeAccounts()) }}
            </small>
        </div>

        <div class="stat">
            <b>{{ $figures->activeAccounts() }}</b>
            <span class="muted">paying</span>
            <small class="stat__note">Renewing on their own</small>
        </div>

        <div class="stat">
            <b class="{{ $figures->trialsEndingSoon() > 0 ? 'stat--warn' : '' }}">{{ $figures->onTrial() }}</b>
            <span class="muted">on trial</span>
            <small class="stat__note">
                @if ($figures->trialsEndingSoon() > 0)
                    {{ $figures->trialsEndingSoon() }} ending this week
                @else
                    None ending this week
                @endif
            </small>
        </div>

        <div class="stat">
            <b class="{{ $figures->lapsedThisMonth() > 0 ? 'stat--warn' : '' }}">{{ $figures->lapsedThisMonth() }}</b>
            <span class="muted">lapsed this month</span>
            <small class="stat__note">
                @if ($figures->failedPayments() > 0)
                    {{ $figures->failedPayments() }} payment outstanding
                @else
                    No payments outstanding
                @endif
            </small>
        </div>
    </div>

    <div class="work">
        <div>
            <section class="card" style="padding:0;overflow:hidden">
                <div class="card__head" style="padding:14px 20px;margin:0;border-bottom:1px solid var(--line)">
                    <h2 class="h2">Worth a call</h2>
                    <span class="muted">Trials about to end, and payments that bounced</span>
                </div>

                @forelse ($needAttention as $account)
                    @php $reason = $accountReason($account); @endphp

                    <div class="account">
                        <span class="account__mark account__mark--{{ $reason['kind'] }}" aria-hidden="true">
                            {{ $account->initials() }}
                        </span>

                        <span class="account__who">
                            <strong>{{ $account->name }}</strong>
                            <small class="muted">
                                {{ $reason['body'] }}
                                @if (filled($account->venue?->city))
                                    · {{ $account->venue->city }}
                                @endif
                            </small>
                        </span>

                        <span class="account__state">
                            <span class="pill {{ $account->status->acceptsBookings() ? 'pill--ok' : 'pill--warn' }}">
                                {{ $account->status->label() }}
                            </span>
                            <small class="muted">{{ $account->subscription?->plan?->name ?? 'No plan' }}</small>
                        </span>

                        <a class="btn btn--ghost btn--small" href="{{ route('admin.tenants.show', $account) }}">
                            Open
                        </a>
                    </div>
                @empty
                    <p class="empty" style="margin:0;border:0">Nothing needs chasing. Rare and worth enjoying.</p>
                @endforelse
            </section>
        </div>

        <aside class="work__rail">
            {{--
                The quiet parts, on the page somebody actually opens. A
                command that stops running shows up here within two of its own
                intervals rather than on the day it is noticed.
            --}}
            <section class="card">
                <div class="card__head">
                    <h2 class="h2">The quiet parts</h2>
                    <span class="pill {{ $health->looksHealthy() ? 'pill--ok' : 'pill--warn' }}">
                        {{ $health->looksHealthy() ? 'All running' : 'Needs a look' }}
                    </span>
                </div>

                <div class="needs">
                    <span class="needs__dot {{ $health->failedJobs() > 0 ? 'needs__dot--late' : '' }}"
                          aria-hidden="true"></span>
                    <span class="needs__text">
                        <strong>{{ $health->failedJobs() }} failed jobs</strong>
                        <small class="muted">{{ $health->pendingJobs() }} waiting in the queue</small>
                    </span>
                </div>

                <div class="needs">
                    <span class="needs__dot {{ $health->failedPayments() > 0 ? 'needs__dot--table' : '' }}"
                          aria-hidden="true"></span>
                    <span class="needs__text">
                        <strong>{{ $health->failedPayments() }} payments to chase</strong>
                        <small class="muted">{{ $health->stalePendingPayments() }} stuck half-paid</small>
                    </span>
                </div>

                <div class="needs">
                    <span class="needs__dot" aria-hidden="true"></span>
                    <span class="needs__text">
                        <strong>{{ $health->bouncedVouchers() }} bounced emails</strong>
                        <small class="muted">Last 30 days, every venue</small>
                    </span>
                </div>

                <p style="margin:12px 0 0">
                    <a href="{{ route('admin.system') }}">The whole of it</a>
                </p>
            </section>

            <section class="card">
                <div class="card__head">
                    <h2 class="h2">Lately</h2>
                    <a class="muted" href="{{ route('admin.audit') }}">Full log</a>
                </div>

                @forelse ($recent as $entry)
                    <div class="needs">
                        <span class="needs__dot" aria-hidden="true"></span>
                        <span class="needs__text">
                            <strong>{{ $entry->summary }}</strong>
                            <small class="muted">
                                {{ $entry->who() }} · {{ $entry->occurred_at->diffForHumans() }}
                            </small>
                        </span>
                    </div>
                @empty
                    <p class="muted" style="margin:0">Nothing has been done by hand yet.</p>
                @endforelse
            </section>
        </aside>
    </div>
@endsection
