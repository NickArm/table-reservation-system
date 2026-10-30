{{--
    What the platform sells (SPEC §4.1).

    The "on it" column is the only number that answers whether a plan can be
    retired, so it is on the list rather than one click away.
--}}
@extends('layouts.admin')

@section('title', 'Plans — '.config('app.name'))

@section('main')
    <div class="page-head">
        <div>
            <h1>Plans</h1>
            <p class="muted">Public packages, and the private ones written for one account.</p>
        </div>
    </div>

    <p style="margin-bottom:24px">
        <a href="{{ route('admin.plans.create') }}" class="btn">Write a new plan</a>
    </p>

    <table class="table">
        <thead>
            <tr>
                <th>Plan</th>
                <th>Price</th>
                <th>For</th>
                <th>At the provider</th>
                <th>On it</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($plans as $plan)
                <tr>
                    <td>
                        <a href="{{ route('admin.plans.edit', $plan) }}">
                            <strong>{{ $plan->name }}</strong>
                        </a>
                        @unless ($plan->is_active)
                            <span class="pill pill--muted">retired</span>
                        @endunless
                        <br>
                        <small class="muted">{{ $plan->slug }}</small>
                    </td>
                    <td>
                        <strong>{{ $plan->price() }}</strong>
                        <br>
                        <small class="muted">{{ $plan->billing_period->per() }}</small>
                    </td>
                    <td>
                        @if ($plan->customForTenant !== null)
                            <a href="{{ route('admin.tenants.show', $plan->customForTenant) }}">
                                {{ $plan->customForTenant->name }}
                            </a>
                        @elseif ($plan->is_public)
                            Everybody
                        @else
                            <span class="muted">Hidden</span>
                        @endif
                    </td>
                    <td>
                        {{--
                            A plan with neither id cannot be bought — the
                            billing page refuses to show a button that would
                            die on the other side — so this column is the
                            first place to look when somebody says the plan
                            is not there.
                        --}}
                        @if (filled($plan->stripe_price_id) || filled($plan->paypal_plan_id))
                            @if (filled($plan->stripe_price_id))
                                <span class="pill pill--ok">Stripe</span>
                            @endif
                            @if (filled($plan->paypal_plan_id))
                                <span class="pill pill--ok">PayPal</span>
                            @endif
                        @else
                            <span class="pill pill--warn">not set up</span>
                        @endif
                    </td>
                    <td>{{ $inUse[$plan->id] ?? 0 }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5"><p class="empty">No plans yet.</p></td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
