@extends('layouts.auth')

@section('title', 'Create your account')
@section('heading', 'Start taking bookings')
@section('subheading', config('tenancy.trial_days').' days free. No card needed.')

@section('form')
    <form method="POST" action="{{ route('register') }}" class="stack">
        @csrf

        <div>
            <label class="label" for="venue_name">Venue name</label>
            <input class="field" id="venue_name" name="venue_name" type="text"
                   value="{{ old('venue_name') }}" required autofocus
                   autocomplete="organization" placeholder="Crystal Seaside">
            <p class="muted" style="margin:6px 0 0">This is what your guests will see.</p>
        </div>

        <div>
            <label class="label" for="name">Your name</label>
            <input class="field" id="name" name="name" type="text"
                   value="{{ old('name') }}" required autocomplete="name">
        </div>

        <div>
            <label class="label" for="email">Email</label>
            <input class="field" id="email" name="email" type="email"
                   value="{{ old('email') }}" required autocomplete="email">
        </div>

        <div>
            <label class="label" for="password">Password</label>
            <input class="field" id="password" name="password" type="password"
                   required autocomplete="new-password">
        </div>

        <div>
            <label class="label" for="password_confirmation">Confirm password</label>
            <input class="field" id="password_confirmation" name="password_confirmation"
                   type="password" required autocomplete="new-password">
        </div>

        {{--
            Agreeing to the terms (SPEC §12.2). Unticked by default and
            required — a pre-ticked box is not consent anywhere in the EU, and
            the links have to be real links rather than plain text, because
            agreeing to something you cannot open is the thing that makes a
            clause unenforceable.
        --}}
        <label class="consent">
            <input type="checkbox" name="terms" value="1" required @checked(old('terms'))>
            <span>
                I agree to the
                <a href="{{ route('legal', 'terms') }}" target="_blank" rel="noopener">Terms of Service</a>
                and the
                <a href="{{ route('legal', 'privacy') }}" target="_blank" rel="noopener">Privacy Policy</a>.
            </span>
        </label>

        <button class="btn btn--block" type="submit">Create account</button>
    </form>
@endsection

@section('footer')
    Already have an account? <a href="{{ route('login') }}">Sign in</a>
@endsection
