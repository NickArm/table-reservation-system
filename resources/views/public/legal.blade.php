{{--
    A venue's own terms or cancellation policy, as a guest reads them
    (SPEC §12.1).

    Carries the venue's branding rather than ours: the guest is agreeing with
    the venue, not with us, and a page in our colours would suggest otherwise
    (§7.4). `noindex`, because the useful page for a search engine is the
    booking page — four hundred near-identical cancellation policies are
    exactly the thin content that gets a whole domain penalised (§33).
--}}
@extends('layouts.base', ['accent' => $venue->accent_color ?? '#0e6e6e'])

@section('title', $type->title().' — '.$venue->name)

@section('body')
    <header class="legal-head">
        <div class="legal-head__inner">
            <a href="{{ route('public.booking', $tenant) }}">{{ $venue->name }}</a>
        </div>
    </header>

    <main class="shell shell--narrow">
        <div class="page-head">
            <h1>{{ $type->title() }}</h1>
            <p class="muted">
                {{ $venue->name }} ·
                last updated {{ $document->published_at?->format('j F Y') }}
            </p>
        </div>

        <article class="prose">
            {!! $document->html() !!}
        </article>

        @if (filled($venue->phone))
            <p class="notice" style="margin-top:32px">
                To change or cancel a booking, call
                <a href="tel:{{ $venue->phone }}">{{ $venue->phone }}</a>.
            </p>
        @endif
    </main>
@endsection
