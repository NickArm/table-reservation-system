{{--
    Writing or changing a plan (SPEC §4.1).

    The sentence about the price not applying retroactively is on the form on
    purpose. It is the one thing about this screen somebody will later be
    certain worked the other way round, and the place to say so is where the
    number is typed.
--}}
@extends('layouts.admin')

@section('title', ($plan->exists ? $plan->name : 'New plan').' — '.config('app.name'))

@section('main')
    @php
        $limits = $plan->limits ?? [];
        $features = $plan->features ?? [];
    @endphp

    @include('partials.crumb', ['trail' => [
        ['Plans', route('admin.plans.index')],
        [$plan?->name ?? 'New plan', null],
    ]])

    <div class="page-head">
        <div>
            <h1>{{ $plan->exists ? $plan->name : 'A new plan' }}</h1>
            <p class="muted">
                @if ($plan->exists)
                    Changing the price here does not change what anybody is already paying.
                @else
                    It cannot be bought until it exists at the payment provider too.
                @endif
            </p>
        </div>
    </div>

    <form method="POST" class="stack" style="max-width:720px"
          action="{{ $plan->exists ? route('admin.plans.update', $plan) : route('admin.plans.store') }}">
        @csrf
        @if ($plan->exists)
            @method('PATCH')
        @endif

        <section class="card stack">
            <div class="card__head"><h2 class="h2">The basics</h2></div>

            <div class="field">
                <label for="name">Name</label>
                <input id="name" type="text" name="name" required maxlength="80"
                       value="{{ old('name', $plan->name) }}">
            </div>

            <div class="field">
                <label for="slug">Slug</label>
                <input id="slug" type="text" name="slug" maxlength="80"
                       value="{{ old('slug', $plan->slug) }}">
                <small class="muted">
                    How the code finds this plan. Leave it blank and it comes from the
                    name. Changing it on a live plan will break anything that looks it
                    up — the seeder does.
                </small>
            </div>

            <div class="field">
                <label for="description">What it says on the billing page</label>
                <textarea id="description" name="description" rows="3"
                          maxlength="500">{{ old('description', $plan->description) }}</textarea>
            </div>

            <div class="row">
                <div class="field">
                    <label for="price">Price, in euros</label>
                    <input id="price" type="number" name="price" step="0.01" min="0" required
                           value="{{ old('price', $plan->exists ? number_format((int) $plan->price_cents / 100, 2, '.', '') : '70.00') }}">
                    <small class="muted">
                        Accounts already on this plan keep the price they signed at until
                        they renew.
                    </small>
                </div>

                <div class="field">
                    <label for="billing_period">Period</label>
                    <select id="billing_period" name="billing_period">
                        @foreach (\App\Enums\BillingPeriod::cases() as $period)
                            <option value="{{ $period->value }}"
                                @selected(old('billing_period', $plan->billing_period?->value) === $period->value)>
                                {{ $period->per() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="trial_days">Trial days</label>
                    <input id="trial_days" type="number" name="trial_days" min="0" max="365" required
                           value="{{ old('trial_days', $plan->trial_days ?? config('tenancy.trial_days')) }}">
                </div>
            </div>
        </section>

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">At the payment providers</h2>
            </div>

            <p class="muted">
                Create the plan at Stripe or PayPal first, then paste its id here.
                Until one of these is filled in, the plan gets no button on the
                billing page — a button that dies on the other side is worse than no
                button.
            </p>

            <div class="row">
                <div class="field">
                    <label for="stripe_price_id">Stripe price id</label>
                    <input id="stripe_price_id" type="text" name="stripe_price_id" maxlength="120"
                           placeholder="price_…"
                           value="{{ old('stripe_price_id', $plan->stripe_price_id) }}">
                </div>

                <div class="field">
                    <label for="paypal_plan_id">PayPal plan id</label>
                    <input id="paypal_plan_id" type="text" name="paypal_plan_id" maxlength="120"
                           placeholder="P-…"
                           value="{{ old('paypal_plan_id', $plan->paypal_plan_id) }}">
                </div>
            </div>
        </section>

        <section class="card stack">
            <div class="card__head"><h2 class="h2">Limits</h2></div>

            <p class="muted">Blank means no limit, which is a real answer and not a missing one.</p>

            <div class="row">
                @foreach (\App\Enums\PlanLimit::cases() as $limit)
                    <div class="field">
                        <label for="limit-{{ $limit->value }}">{{ $limit->label() }}</label>
                        <input id="limit-{{ $limit->value }}" type="number" min="0"
                               name="limits[{{ $limit->value }}]"
                               value="{{ old('limits.'.$limit->value, $limits[$limit->value] ?? null) }}">
                    </div>
                @endforeach
            </div>
        </section>

        <section class="card stack">
            <div class="card__head"><h2 class="h2">Features</h2></div>

            <p class="muted">
                Anything left off stays off. A plan written before a feature existed
                should not acquire it on the deploy that adds it.
            </p>

            <div class="choices">
                @foreach (\App\Enums\Feature::cases() as $feature)
                    <label class="choice">
                        <input type="checkbox" name="features[{{ $feature->value }}]" value="1"
                            @checked(old('features.'.$feature->value, $features[$feature->value] ?? false))>
                        <span>{{ $feature->label() }}</span>
                    </label>
                @endforeach
            </div>
        </section>

        <section class="card stack">
            <div class="card__head"><h2 class="h2">Who sees it</h2></div>

            <div class="field">
                <label for="custom_for_tenant_id">Written for one account</label>
                <select id="custom_for_tenant_id" name="custom_for_tenant_id">
                    <option value="">Nobody in particular</option>
                    @foreach ($tenants as $account)
                        <option value="{{ $account->id }}"
                            @selected((int) old('custom_for_tenant_id', $plan->custom_for_tenant_id) === (int) $account->id)>
                            {{ $account->name }}
                        </option>
                    @endforeach
                </select>
                <small class="muted">
                    Pick an account and the plan becomes private to it automatically —
                    a negotiated price must never turn up on somebody else's billing
                    page.
                </small>
            </div>

            <div class="choices">
                <label class="choice">
                    <input type="checkbox" name="is_public" value="1"
                        @checked(old('is_public', $plan->exists ? $plan->is_public : true))>
                    <span>Show on the billing page</span>
                </label>

                <label class="choice">
                    <input type="checkbox" name="is_active" value="1"
                        @checked(old('is_active', $plan->exists ? $plan->is_active : true))>
                    <span>Still sold</span>
                </label>
            </div>

            <div class="field">
                <label for="sort_order">Order on the page</label>
                <input id="sort_order" type="number" name="sort_order" min="0" max="1000" required
                       value="{{ old('sort_order', $plan->sort_order ?? 10) }}">
            </div>
        </section>

        <p>
            <button type="submit" class="btn">
                {{ $plan->exists ? 'Save the plan' : 'Write the plan' }}
            </button>
            <a href="{{ route('admin.plans.index') }}" class="btn btn--ghost">Back</a>
        </p>
    </form>
@endsection
