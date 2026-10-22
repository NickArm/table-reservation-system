{{--
    Every account (SPEC §13.2).

    The search covers name, slug and the owners' email addresses, because
    those are the three things somebody has in front of them when a venue
    rings up — and the email is usually the only one they say correctly.
--}}
@extends('layouts.admin')

@section('title', 'Accounts — '.config('app.name'))

@section('main')
    <div class="page-head">
        <h1>Accounts</h1>
        <p>{{ $tenants->total() }} in total.</p>
    </div>

    <form method="GET" action="{{ route('admin.tenants.index') }}" class="filters" style="margin-bottom:24px">
        <div class="field">
            <label for="q">Search</label>
            <input id="q" type="search" name="q" value="{{ $term }}"
                   placeholder="Name, slug or owner's email">
        </div>

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
                <th>Status</th>
                <th>Plan</th>
                <th>Renews</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($tenants as $account)
                <tr>
                    <td>
                        <a href="{{ route('admin.tenants.show', $account) }}">
                            <strong>{{ $account->name }}</strong>
                        </a>
                        <br>
                        <small class="muted">/reservation/{{ $account->slug }}</small>
                    </td>
                    <td>
                        <span class="pill {{ $account->status->acceptsBookings() ? 'pill--ok' : 'pill--warn' }}">
                            {{ $account->status->label() }}
                        </span>
                    </td>
                    <td>
                        {{ $account->subscription?->plan?->name ?? '—' }}
                        @if ($account->subscription !== null && ! $account->subscription->plan?->is_public)
                            <span class="pill pill--muted">custom</span>
                        @endif
                    </td>
                    <td>
                        @if ($account->isOnTrial())
                            <small class="muted">
                                Trial, {{ $account->trialDaysLeft() }}
                                {{ \Illuminate\Support\Str::plural('day', $account->trialDaysLeft()) }} left
                            </small>
                        @else
                            <small class="muted">
                                {{ $account->subscription?->current_period_end?->format('j M Y') ?? '—' }}
                            </small>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4"><p class="empty">Nothing matches that.</p></td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $tenants->links() }}
@endsection
