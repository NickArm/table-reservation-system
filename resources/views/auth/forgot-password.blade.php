@extends('layouts.auth')

@section('title', 'Reset your password')
@section('heading', 'Reset your password')
@section('subheading', 'Tell us your email and we will send a link.')

@section('form')
    <form method="POST" action="{{ route('password.email') }}" class="stack">
        @csrf

        <div>
            <label class="label" for="email">Email</label>
            <input class="field" id="email" name="email" type="email"
                   value="{{ old('email') }}" required autofocus autocomplete="email">
        </div>

        <button class="btn btn--block" type="submit">Send reset link</button>
    </form>
@endsection

@section('footer')
    <a href="{{ route('login') }}">Back to sign in</a>
@endsection
