{{--
    The platform's legal texts (SPEC §12.2).

    Four rows, one per document. The column that matters is the last one: a
    placeholder on a live site is the thing to be uncomfortable about, so it
    says so rather than showing a tick.
--}}
@extends('layouts.admin')

@section('title', 'Legal — '.config('app.name'))

@section('main')
    <div class="page-head">
        <h1>Legal</h1>
        <p>Edited here and live immediately. No deploy.</p>
    </div>

    <p class="notice" style="margin-bottom:24px">
        A <strong>published version is frozen</strong>. Accounts agree to a version
        by name, so changing the words afterwards would quietly rewrite an agreement
        somebody already made — every revision gets a new version number, and the old
        one is kept.
    </p>

    <table class="table">
        <thead>
            <tr>
                <th>Document</th>
                <th>Live version</th>
                <th>Address</th>
                <th>State</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($types as $type)
                @php
                    $live = $current[$type->value] ?? null;
                    $draft = $drafts[$type->value] ?? null;
                @endphp

                <tr>
                    <td>
                        <a href="{{ route('admin.legal.edit', $type->path()) }}">
                            <strong>{{ $type->title() }}</strong>
                        </a>
                        <br>
                        <small class="muted">{{ $type->who() }}</small>
                    </td>
                    <td>
                        @if ($live !== null)
                            <strong>{{ $live->version }}</strong><br>
                            <small class="muted">{{ $live->published_at?->format('j M Y') }}</small>
                        @else
                            <span class="muted">—</span>
                        @endif
                    </td>
                    <td>
                        <a href="{{ route('legal', $type->path()) }}" target="_blank" rel="noopener">
                            /{{ $type->path() }}
                        </a>
                    </td>
                    <td>
                        @if ($live === null)
                            <span class="pill pill--warn">nothing published</span>
                        @elseif ($live->isPlaceholder())
                            <span class="pill pill--warn">placeholder</span>
                        @else
                            <span class="pill pill--ok">live</span>
                        @endif

                        @if ($draft !== null)
                            <span class="pill pill--muted">draft {{ $draft->version }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection
