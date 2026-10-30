{{--
    Every subscription, as money rather than as accounts (SPEC §13.2).

    Accounts answers "who is this venue". This answers "where is the money",
    which is why the order is not alphabetical but by what is wrong: a bounced
    payment first, then whatever ends soonest.
--}}
@extends('layouts.admin')

@section('title', 'Subscriptions — '.config('app.name'))

@section('main')
    <div class="page-head">
        <div>
            <h1>Subscriptions</h1>
            <p class="muted">{{ $subscriptions->total() }} in total, anything bounced at the top.</p>
        </div>
    </div>

    <div class="stat-grid">
        <div class="stat">
            <b>{{ \App\Support\Money::format($figures->mrrCents()) }}</b>
            <span>Monthly recurring</span>
        </div>
        <div class="stat">
            <b>{{ $figures->failedPayments() }}</b>
            <span>Payments outstanding</span>
        </div>
        <div class="stat">
            <b>{{ $figures->lapsedThisMonth() }}</b>
            <span>Lapsed this month</span>
        </div>
    </div>

    <form method="GET" action="{{ route('admin.subscriptions') }}" class="filters"
          style="margin-bottom:24px">
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">Any</option>
                @foreach (\App\Enums\TenantStatus::cases() as $case)
                    <option value="{{ $case->value }}" @selected($status === $case)>
                        {{ $case->label() }}
                    </option>
                @endforeach
            </select>
        </div>

        <button type="submit" class="btn btn--ghost">Filter</button>
    </form>

    <table class="table">
        <thead>
            <tr>
                <th>Account</th>
                <th>Plan</th>
                <th>Paying</th>
                <th>Period ends</th>
                <th>Payment</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($subscriptions as $subscription)
                <tr>
                    <td>
                        @if ($subscription->tenant !== null)
                            <a href="{{ route('admin.tenants.show', $subscription->tenant) }}">
                                <strong>{{ $subscription->tenant->name }}</strong>
                            </a>
                        @else
                            <small class="muted">—</small>
                        @endif
                        <br>
                        <span class="pill {{ $subscription->status->acceptsBookings() ? 'pill--ok' : 'pill--warn' }}">
                            {{ $subscription->status->label() }}
                        </span>
                    </td>
                    <td>
                        {{ $subscription->plan?->name ?? '—' }}
                        @if ($subscription->plan !== null && ! $subscription->plan->is_public)
                            <span class="pill pill--muted">custom</span>
                        @endif
                        <br>
                        <small class="muted">
                            {{ $subscription->provider?->label() ?? '—' }}
                        </small>
                    </td>
                    <td>
                        <strong>{{ $subscription->price() }}</strong>
                        <br>
                        <small class="muted">{{ $subscription->plan?->billing_period->per() }}</small>
                    </td>
                    <td>
                        <small class="muted">
                            {{ $subscription->current_period_end?->format('j M Y') ?? '—' }}
                        </small>
                        @if ($subscription->cancel_at_period_end)
                            <br><span class="pill pill--muted">will not renew</span>
                        @endif
                    </td>
                    <td>
                        @if ($subscription->payment_failed_at !== null)
                            <span class="pill pill--warn">failed</span>
                            <br>
                            <small class="muted">
                                {{ $subscription->payment_failed_at->diffForHumans() }}
                            </small>
                            <br>
                            <small class="muted">
                                {{-- Which of the three emails has gone out, so
                                     "we never told them" and "they have ignored
                                     three" are not the same row. --}}
                                @if ($subscription->dunning_sent_for === null)
                                    not chased yet
                                @else
                                    chased on day {{ $subscription->dunning_sent_for }}
                                @endif
                            </small>
                        @else
                            <small class="muted">fine</small>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5"><p class="empty">Nothing matches that.</p></td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $subscriptions->links() }}
@endsection
