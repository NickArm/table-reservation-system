{{--
    Every account (SPEC §13.2).

    The search covers name, slug and the owners' email addresses, because
    those are the three things somebody has in front of them when a venue
    rings up — and the email is usually the only one they say correctly.

    So the search gets its own line, and the statuses are chips: the question
    after "find me this venue" is nearly always "who is past due", and that
    should not be a dropdown and a Filter button.
--}}
@extends('layouts.admin')

@section('title', 'Accounts — '.config('app.name'))

@section('main')
    <div class="page-head">
        <div>
            <h1>Accounts</h1>
            <p class="muted">{{ $tenants->total() }} in total.</p>
        </div>
    </div>

    <form method="GET" action="{{ route('admin.tenants.index') }}" class="searchbar">
        @if ($status !== null)
            <input type="hidden" name="status" value="{{ $status->value }}">
        @endif

        <label class="label" for="q"
               style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">
            Search accounts
        </label>
        <input class="field" type="search" id="q" name="q" value="{{ $term }}"
               placeholder="Name, slug or owner's email">
        <button class="btn" type="submit">Search</button>
    </form>

    <div class="chips" style="margin-bottom:16px">
        @foreach ($statusFilters as $filter)
            <a class="chip" href="{{ $filter['href'] }}"
               @if ($filter['on']) aria-current="page" @endif>
                {{ $filter['label'] }}
                <span class="chip__count">{{ $filter['count'] }}</span>
            </a>
        @endforeach
    </div>

    <section class="card" style="padding:0;overflow:hidden">
        @forelse ($tenants as $account)
            <div class="account">
                <span class="account__mark account__mark--{{ $account->status->acceptsBookings() ? 'plain' : 'late' }}"
                      aria-hidden="true">{{ $account->initials() }}</span>

                <span class="account__who">
                    <strong>{{ $account->name }}</strong>
                    <small class="muted">
                        /reservation/{{ $account->slug }}
                        @if (filled($account->venue?->city))
                            · {{ $account->venue->city }}
                        @endif
                    </small>
                </span>

                <span class="account__state">
                    <span class="pill {{ $account->status->acceptsBookings() ? 'pill--ok' : 'pill--warn' }}">
                        {{ $account->status->label() }}
                    </span>
                    <small class="muted">
                        @if ($account->isOnTrial())
                            Trial, {{ $account->trialDaysLeft() }}
                            {{ \Illuminate\Support\Str::plural('day', $account->trialDaysLeft()) }} left
                        @else
                            {{ $account->subscription?->plan?->name ?? 'No plan' }}
                            @if ($account->subscription !== null && ! $account->subscription->plan?->is_public)
                                · custom
                            @endif
                        @endif
                    </small>
                </span>

                <span class="account__renews">
                    @unless ($account->isOnTrial())
                        <b>{{ $account->subscription?->current_period_end?->format('j M Y') ?? '—' }}</b>
                        <small>renews</small>
                    @endunless
                </span>

                <a class="btn btn--ghost btn--small" href="{{ route('admin.tenants.show', $account) }}">Open</a>
            </div>
        @empty
            <p class="empty" style="margin:0;border:0">Nothing matches that.</p>
        @endforelse
    </section>

    {{ $tenants->links() }}
@endsection
