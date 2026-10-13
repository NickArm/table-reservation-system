@extends('layouts.auth')

@section('title', 'Sign in')
@section('heading', 'Sign in')

@section('form')
    <form method="POST" action="{{ route('login') }}" class="stack">
        @csrf

        <div>
            <label class="label" for="email">Email</label>
            <input class="field" id="email" name="email" type="email"
                   value="{{ old('email') }}" required autofocus autocomplete="email">
        </div>

        <div>
            <label class="label" for="password">Password</label>
            <input class="field" id="password" name="password" type="password"
                   required autocomplete="current-password">
        </div>

        <label for="remember" style="display:flex;gap:10px;align-items:center;font-size:var(--step--1)">
            <input id="remember" name="remember" type="checkbox" value="1"
                   style="width:18px;height:18px;accent-color:var(--accent)">
            Keep me signed in
        </label>

        <button class="btn btn--block" type="submit">Sign in</button>
    </form>
@endsection

@section('footer')
    <a href="{{ route('password.request') }}">Forgot your password?</a>
    &nbsp;·&nbsp;
    <a href="{{ route('register') }}">Create an account</a>
@endsection
