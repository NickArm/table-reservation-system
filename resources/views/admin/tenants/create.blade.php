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

    <form method="POST" action="{{ route('admin.tenants.store') }}"
          class="stack" style="max-width:560px">
        @csrf

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">The venue</h2>
            </div>

            <div class="form-row">
                <label class="label" for="venue_name">What it is called</label>
                <input class="field" id="venue_name" type="text" name="venue_name"
                       required maxlength="120" autofocus
                       @error('venue_name') aria-invalid="true" @enderror
                       value="{{ old('venue_name') }}" placeholder="Crystal Seaside">
                <p class="form-row__help">
                    The name guests see, and what the booking address is made from.
                    <strong>The address cannot be changed later</strong>, so a typo
                    here is permanent.
                </p>
                @error('venue_name')
                    <p class="form-row__error">{{ $message }}</p>
                @enderror
            </div>
        </section>

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">Who runs it</h2>
            </div>

            <div class="form-grid">
                <div class="form-row">
                    <label class="label" for="owner_name">Owner</label>
                    <input class="field" id="owner_name" type="text" name="owner_name"
                           required maxlength="120"
                           @error('owner_name') aria-invalid="true" @enderror
                           value="{{ old('owner_name') }}" placeholder="Maria Stamati">
                    @error('owner_name')
                        <p class="form-row__error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="form-row">
                    <label class="label" for="owner_email">Their email</label>
                    <input class="field" id="owner_email" type="email" name="owner_email"
                           required maxlength="190"
                           @error('owner_email') aria-invalid="true" @enderror
                           value="{{ old('owner_email') }}"
                           placeholder="maria@crystalseaside.gr">
                    @error('owner_email')
                        <p class="form-row__error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <p class="form-row__help">
                The address is how they sign in, and where the invitation goes.
            </p>
        </section>

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">What they pay</h2>
            </div>

            <div class="form-row">
                <label class="label" for="plan_id">Plan</label>
                <select class="field" id="plan_id" name="plan_id" required
                        @error('plan_id') aria-invalid="true" @enderror>
                    @foreach ($plans as $plan)
                        <option value="{{ $plan->id }}"
                            @selected((int) old('plan_id') === (int) $plan->id)>
                            {{ $plan->name }} — {{ \App\Support\Money::format((int) $plan->price_cents) }}
                            {{ $plan->billing_period->per() }}
                        </option>
                    @endforeach
                </select>
                <p class="form-row__help">
                    The paid period starts today and runs for the length of the plan.
                </p>
                @error('plan_id')
                    <p class="form-row__error">{{ $message }}</p>
                @enderror
            </div>

            <div class="choices">
                <label class="choice">
                    <input type="checkbox" name="send_invitation" value="1"
                           @checked(old('send_invitation', true))>
                    <span>Email them the invitation now</span>
                </label>
            </div>
        </section>

        <p class="notice">
            <strong>They pay you directly.</strong> Nothing here will ever charge a
            card: you invoice them, and the system only knows the date it runs out.
            You will be told {{ (int) config('tenancy.manual_notice_days') }} days
            before that, so there is time to send the next one.
        </p>

        <p class="notice">
            <strong>No password is set, by anybody.</strong> They get a link that lets
            them choose their own, good for
            {{ (int) config('tenancy.invitation_days') }} days. The same link is shown
            on the next screen, so you can send it over whatever they actually read.
        </p>

        <div class="form-actions">
            <button type="submit" class="btn">Open the account</button>
            <a class="btn btn--ghost" href="{{ route('admin.tenants.index') }}">Cancel</a>
        </div>
    </form>
@endsection
