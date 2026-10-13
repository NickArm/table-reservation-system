@extends('layouts.base')

@section('body')
    <main class="shell shell--narrow">
        <p style="margin:0 0 28px"><a href="{{ route('home') }}" style="text-decoration:none;font-weight:600">{{ config('app.name') }}</a></p>

        <h1 style="margin:0 0 6px;font-size:var(--step-2);letter-spacing:-0.02em">@yield('heading')</h1>

        @hasSection('subheading')
            <p class="muted" style="margin:0 0 24px">@yield('subheading')</p>
        @else
            <div style="height:24px"></div>
        @endif

        @if (session('status'))
            <div class="notice" style="margin-bottom:20px">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <ul class="errors" style="margin-bottom:20px">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        @yield('form')

        @hasSection('footer')
            <p class="muted" style="margin-top:24px">@yield('footer')</p>
        @endif
    </main>
@endsection
