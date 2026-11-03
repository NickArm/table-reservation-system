{{--
    The one screen an invited owner sees before the dashboard.

    No email field, unlike the password reset: the token already names the
    person, and asking them to type the address the invitation was sent to is
    a question with one right answer that only ever goes wrong.
--}}
@extends('layouts.auth')

@section('title', $dead ? 'That link has run out' : 'Choose your password')
@section('heading', $dead ? 'That link has run out' : 'Choose your password')

@section('form')
    @if ($dead)
        <p class="muted">
            Invitations stop working after a few days, and after they have been
            used once. Reply to the email that brought you here and we will send
            a fresh one — nothing has been lost, the account is still there.
        </p>

        <p style="margin-top:16px">
            <a class="btn btn--block" href="{{ route('login') }}">Go to sign in</a>
        </p>
    @else
        <p class="muted" style="margin-bottom:20px">
            Signing in as <strong>{{ $user->email }}</strong>. Pick something only
            you know — this account can see every booking and every guest.
        </p>

        <form method="POST" action="{{ route('invitation.store', ['token' => $token]) }}" class="stack">
            @csrf

            <div>
                <label class="label" for="password">Password</label>
                <input class="field" id="password" name="password" type="password"
                       required autofocus autocomplete="new-password">
            </div>

            <div>
                <label class="label" for="password_confirmation">Confirm password</label>
                <input class="field" id="password_confirmation" name="password_confirmation"
                       type="password" required autocomplete="new-password">
            </div>

            <button class="btn btn--block" type="submit">Save it and go in</button>
        </form>
    @endif
@endsection
