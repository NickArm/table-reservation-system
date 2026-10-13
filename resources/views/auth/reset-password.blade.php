@extends('layouts.auth')

@section('title', 'Choose a new password')
@section('heading', 'Choose a new password')

@section('form')
    <form method="POST" action="{{ route('password.update') }}" class="stack">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div>
            <label class="label" for="email">Email</label>
            <input class="field" id="email" name="email" type="email"
                   value="{{ old('email', $email) }}" required autocomplete="email">
        </div>

        <div>
            <label class="label" for="password">New password</label>
            <input class="field" id="password" name="password" type="password"
                   required autofocus autocomplete="new-password">
        </div>

        <div>
            <label class="label" for="password_confirmation">Confirm new password</label>
            <input class="field" id="password_confirmation" name="password_confirmation"
                   type="password" required autocomplete="new-password">
        </div>

        <button class="btn btn--block" type="submit">Save password</button>
    </form>
@endsection
