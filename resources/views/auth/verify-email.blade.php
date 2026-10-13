@extends('layouts.auth')

@section('title', 'Confirm your email')
@section('heading', 'Check your inbox')
@section('subheading', 'We sent a link to '.e(auth()->user()?->email).'. Open it to finish setting up.')

@section('form')
    <div class="stack">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button class="btn btn--block" type="submit">Send it again</button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="btn btn--ghost btn--block" type="submit">Sign out</button>
        </form>
    </div>
@endsection
