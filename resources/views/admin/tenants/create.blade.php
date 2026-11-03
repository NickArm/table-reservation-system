{{--
    Opening an account for somebody (SPEC §4.1).

    Four fields and a plan. Everything else a sign-up collects — password,
    timezone, the venue's address — either belongs to the owner or has a
    sensible default, and asking an operator to type it during a telephone
    call is how it gets typed wrong.

    No password field, on purpose. See the note by the invitation below.
--}}
@extends('layouts.admin')

@section('title', 'Open an account — '.config('app.name'))

@section('main')
    @include('partials.crumb', ['trail' => [
        ['Accounts', route('admin.tenants.index')],
        ['Open an account', null],
    ]])

    <div class="page-head">
        <div>
            <h1>Open an account</h1>
            <p class="muted">
                For a venue that agreed a price on the telephone. It starts paid,
                not on trial.
            </p>
        </div>
    </div>

    <div class="stack" style="max-width:620px">
        <form method="POST" action="{{ route('admin.tenants.store') }}" class="card stack">
            @csrf

            <div class="field">
                <label for="venue_name">Venue</label>
                <input id="venue_name" type="text" name="venue_name" required maxlength="120"
                       value="{{ old('venue_name') }}" placeholder="Crystal Seaside">
                <small class="muted">
                    The name guests see, and what the booking address is made from.
                    The address cannot be changed later, so a typo here is permanent.
                </small>
            </div>

            <div class="field">
                <label for="owner_name">Owner</label>
                <input id="owner_name" type="text" name="owner_name" required maxlength="120"
                       value="{{ old('owner_name') }}" placeholder="Maria Stamati">
            </div>

            <div class="field">
                <label for="owner_email">Their email</label>
                <input id="owner_email" type="email" name="owner_email" required maxlength="255"
                       value="{{ old('owner_email') }}" placeholder="maria@crystalseaside.gr">
                <small class="muted">
                    This is how they sign in, and where the invitation goes.
                </small>
            </div>

            <div class="field">
                <label for="plan_id">Plan</label>
                <select id="plan_id" name="plan_id" required>
                    @foreach ($plans as $plan)
                        <option value="{{ $plan->id }}" @selected((int) old('plan_id') === (int) $plan->id)>
                            {{ $plan->name }} — {{ \App\Support\Money::format((int) $plan->price_cents) }}
                            {{ $plan->billing_period->per() }}
                        </option>
                    @endforeach
                </select>
                <small class="muted">
                    The paid period starts today and runs for the length of the plan.
                </small>
            </div>

            <div class="choices">
                <label class="choice">
                    <input type="checkbox" name="send_invitation" value="1"
                           @checked(old('send_invitation', true))>
                    <span>Email them the invitation now</span>
                </label>
            </div>

            <p class="notice">
                <strong>They pay you directly.</strong> Nothing here will ever charge
                a card: you invoice them, and the system only knows the date it runs
                out. You will be told
                {{ (int) config('tenancy.manual_notice_days') }} days before that, so
                there is time to send the next one.
            </p>

            <p class="notice">
                <strong>No password is set, by anybody.</strong> They get a link that
                lets them choose their own, good for
                {{ (int) config('tenancy.invitation_days') }} days. The same link is
                shown on the next screen, so you can send it over whatever they
                actually read.
            </p>

            <button type="submit" class="btn">Open the account</button>
        </form>
    </div>
@endsection
