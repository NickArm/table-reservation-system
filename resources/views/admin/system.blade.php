{{--
    The parts nobody watches (SPEC §13.2).

    Every figure on this page answers the same question: is something broken
    in a way that does not announce itself. A missing cron, a dead queue
    worker, a webhook the gateway kept retrying into nothing — none of them
    throws, none of them emails anybody, and all of them look exactly like a
    quiet week until a venue rings up.
--}}
@extends('layouts.admin')

@section('title', 'System — '.config('app.name'))

@section('main')
    <div class="page-head">
        <h1>System</h1>
        <p>The things that fail silently.</p>
    </div>

    @if ($health->looksHealthy())
        <p class="notice" style="margin-bottom:24px">
            <strong>Nothing to do.</strong>
            Every scheduled command has run on time, the queue is clear, and no
            payment is waiting on a webhook.
        </p>
    @else
        <p class="notice notice--warn" style="margin-bottom:24px">
            <strong>Something needs looking at.</strong>
            The rows below say which.
        </p>
    @endif

    <section class="card stack" style="margin-bottom:24px">
        <div class="card__head">
            <h2 class="h2">Scheduled commands</h2>
            <span class="muted">All of them run from one cron: <code>schedule:run</code></span>
        </div>

        <table class="table">
            <thead>
                <tr>
                    <th>Command</th>
                    <th>Last ran</th>
                    <th>What it did</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($scheduled as $command => $about)
                    @php
                        $run = $runs[$command] ?? null;
                        $late = $run === null || $run->isOverdue($about['every']);
                    @endphp

                    <tr>
                        <td>
                            <code>{{ $command }}</code>
                            <br>
                            <small class="muted">
                                every {{ $about['every'] === 24 ? 'day' : 'hour' }}
                            </small>
                        </td>
                        <td>
                            @if ($run === null)
                                {{-- Never, which on a server that has been up a
                                     while means the cron was never added. --}}
                                <span class="pill pill--warn">never</span>
                            @else
                                <span class="pill {{ $late || ! $run->ok ? 'pill--warn' : 'pill--ok' }}">
                                    {{ $run->ranAt()->diffForHumans() }}
                                </span>
                                @unless ($run->ok)
                                    <br><small class="muted">it threw</small>
                                @endunless
                            @endif
                        </td>
                        <td>
                            @if ($run !== null && filled($run->summary))
                                <small class="muted">{{ $run->summary }}</small>
                            @endif

                            @if ($late)
                                {{-- Why it matters, said where the problem is,
                                     because "tenants:purge is late" means
                                     nothing on its own. --}}
                                <p class="muted" style="margin:4px 0 0">{{ $about['matters'] }}</p>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section class="card stack" style="margin-bottom:24px">
        <div class="card__head">
            <h2 class="h2">Queue</h2>
        </div>

        <div class="facts">
            <div>
                <span class="label">Waiting</span>
                <strong>{{ $health->pendingJobs() }}</strong>
            </div>
            <div>
                <span class="label">Oldest has waited</span>
                <strong>
                    @php $oldest = $health->oldestPendingJobMinutes(); @endphp
                    {{ $oldest === null ? '—' : $oldest.' min' }}
                </strong>
            </div>
            <div>
                <span class="label">Failed</span>
                <strong>{{ $health->failedJobs() }}</strong>
            </div>
        </div>

        <p class="muted">
            A queue with jobs waiting is normal. A queue with jobs waiting that do
            not move is a worker that died — the second figure is the one that tells
            the two apart. With <code>QUEUE_CONNECTION=sync</code> there is no queue
            at all and these read nothing.
        </p>

        @if ($failures->isNotEmpty())
            <div class="card__head"><h3 class="h2">What failed</h3></div>

            @foreach ($failures as $failure)
                <div class="history__row">
                    <div>
                        <small class="muted">{{ $failure->reason }}</small>
                    </div>
                    <div class="event__meta">
                        <small class="muted">{{ $failure->queue }}</small>
                        <small class="muted">{{ $failure->failed_at }}</small>
                    </div>
                </div>
            @endforeach

            <p class="muted">
                <code>php artisan queue:retry all</code> puts them back, once whatever
                they were failing on is fixed.
            </p>
        @endif
    </section>

    <section class="card stack">
        <div class="card__head">
            <h2 class="h2">Payments and delivery</h2>
        </div>

        <div class="facts">
            <div>
                <span class="label">Waiting on a webhook</span>
                <strong>{{ $health->stalePendingPayments() }}</strong>
            </div>
            <div>
                <span class="label">Refused, last week</span>
                <strong>{{ $health->failedPayments() }}</strong>
            </div>
            <div>
                <span class="label">Bounced confirmations, last month</span>
                <strong>{{ $health->bouncedVouchers() }}</strong>
            </div>
        </div>

        <p class="muted">
            A payment pending for more than an hour means the gateway said it would
            tell us and has not (§24.2). <code>payments:reconcile</code> exists to go
            and ask, so a figure here that stays up means that command is not running
            — which is the row above, not a gateway problem.
        </p>
    </section>
@endsection
