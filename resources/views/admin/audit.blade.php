{{--
    Who did what, and why (SPEC §13.2).

    Read-only, and deliberately so: there is no edit and no delete anywhere in
    the application. A log with a delete button is a log nobody can rely on,
    including whoever would have pressed it.
--}}
@extends('layouts.admin')

@section('title', 'Log — '.config('app.name'))

@section('main')
    <div class="page-head">
        <div>
            <h1>Log</h1>
            <p class="muted">Everything done by hand to an account, with a name and a reason on it.</p>
        </div>
    </div>

    <form method="GET" action="{{ route('admin.audit') }}" class="filters" style="margin-bottom:24px">
        <div class="field">
            <label for="action">What happened</label>
            <select id="action" name="action">
                <option value="">Anything</option>
                @foreach (\App\Enums\AuditAction::cases() as $case)
                    <option value="{{ $case->value }}" @selected($action === $case)>
                        {{ $case->label() }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="tenant">Account</label>
            <select id="tenant" name="tenant">
                <option value="">Any</option>
                @foreach ($tenants as $account)
                    <option value="{{ $account->id }}" @selected($tenantId === (int) $account->id)>
                        {{ $account->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <button type="submit" class="btn btn--ghost">Filter</button>
    </form>

    <table class="table">
        <thead>
            <tr>
                <th>When</th>
                <th>What</th>
                <th>Account</th>
                <th>Who</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($entries as $entry)
                <tr>
                    <td><small class="muted">{{ $entry->occurred_at->format('j M Y, H:i') }}</small></td>
                    <td>
                        <strong>{{ $entry->action->label() }}</strong>
                        <br>
                        <small class="muted">{{ $entry->summary }}</small>
                        @if (filled($entry->reason))
                            <br><small class="muted">{{ $entry->reason }}</small>
                        @endif
                    </td>
                    <td>
                        @if ($entry->affected_tenant_id !== null && $entry->affectedTenant !== null)
                            <a href="{{ route('admin.tenants.show', $entry->affectedTenant) }}">
                                {{ $entry->affected_tenant_name }}
                            </a>
                        @else
                            {{--
                                The name without a link: the account has been
                                purged (§29) and the line still has to read.
                            --}}
                            <small class="muted">{{ $entry->affected_tenant_name ?? '—' }}</small>
                        @endif
                    </td>
                    <td>
                        <small class="muted">{{ $entry->who() }}</small>
                        @if (filled($entry->ip))
                            <br><small class="muted">{{ $entry->ip }}</small>
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

    {{ $entries->links() }}
@endsection
