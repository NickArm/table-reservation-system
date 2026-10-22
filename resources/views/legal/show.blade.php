{{--
    One of the platform's legal pages (SPEC §12.2).

    Indexable on purpose — these are the pages somebody checks before signing
    up, and a `noindex` on them makes the product look like it is hiding
    something. The rest of the application is `noindex` by default, so this is
    one of the few places that passes `indexable`.

    The draft notice is deliberately impossible to miss. A half-finished legal
    page that looks finished is worse than an obviously unfinished one: the
    first is relied on, the second is questioned.
--}}
@extends('layouts.base', ['accent' => '#0e6e6e', 'indexable' => true])

@section('title', $type->title().' — '.config('app.name'))
@section('description', $type->who())

@section('body')
    <header class="legal-head">
        <div class="legal-head__inner">
            <a href="{{ route('home') }}">{{ config('app.name') }}</a>
        </div>
    </header>

    <main class="shell shell--narrow">
        <div class="page-head">
            <h1>{{ $type->title() }}</h1>

            @if ($document !== null)
                <p class="muted">
                    Version {{ $document->version }} ·
                    last updated {{ $document->published_at?->format('j F Y') }}
                </p>
            @endif
        </div>

        @if ($document === null)
            <p class="notice notice--warn">
                <strong>Not published yet.</strong>
                This document is being drafted. Until it is here, write to us and we
                will answer the question directly.
            </p>
        @elseif ($document->isPlaceholder())
            <p class="notice notice--warn">
                <strong>Draft — pending legal review.</strong>
                The text below is a placeholder written by us, not by a lawyer. It
                describes what we actually intend, but it is not the final wording and
                should not be relied on as a contract yet.
            </p>
        @endif

        @if ($document !== null)
            <article class="prose">
                {!! $document->html() !!}
            </article>
        @endif

        <nav class="legal-nav" aria-label="Other documents">
            @foreach ($others as $other)
                <a href="{{ route('legal', $other->path()) }}">{{ $other->title() }}</a>
            @endforeach
        </nav>
    </main>
@endsection
