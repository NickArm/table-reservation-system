{{--
    A venue's own terms and cancellation policy (SPEC §12.1).

    Two boxes, pre-filled with text that already works, and Save publishes.
    No draft step, no version numbers: the author is a taverna owner who has
    just corrected a sentence and expects it to be corrected (§1.1). The
    versioning happens underneath, so a booking keeps the words its guest
    agreed to.
--}}
@extends('layouts.dashboard')

@section('title', 'Legal — '.$tenant->name)

@section('main')
    <div class="page-head">
        <h1>Terms &amp; cancellation</h1>
        <p>What your guests agree to when they book.</p>
    </div>

    <div class="stack" style="max-width:820px">
        @foreach ($documents as $entry)
            @php
                $type = $entry['type'];
                $document = $entry['document'];
            @endphp

            <section class="card stack">
                <div class="card__head">
                    <h2 class="h2">{{ $type->title() }}</h2>

                    @if ($document !== null)
                        <a href="{{ route('public.legal', [$tenant, $type->path()]) }}"
                           target="_blank" rel="noopener" class="muted">
                            See the page guests see
                        </a>
                    @else
                        <span class="pill pill--muted">not published yet</span>
                    @endif
                </div>

                <p class="muted">{{ $type->explain() }}</p>

                @if ($entry['isDefault'])
                    <p class="notice">
                        This is our suggested wording — nothing is published until you
                        press Save. <strong>Read it through and change what does not
                        match how you work</strong>, especially how long before a
                        booking a guest gets their money back.
                    </p>
                @endif

                <form method="POST" action="{{ route('dashboard.legal.update', $type->path()) }}"
                      class="stack">
                    @csrf
                    @method('PATCH')

                    <div class="field">
                        <label for="content-{{ $type->value }}" class="label">
                            {{ $type->title() }}
                        </label>
                        <textarea id="content-{{ $type->value }}" name="content" rows="18"
                                  required class="field"
                                  style="min-height:320px;padding:12px 14px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px"
                        >{{ old('content', $entry['content']) }}</textarea>
                        <small class="muted">
                            Headings start with <code>##</code>, lists with
                            <code>-</code>. Everything else is plain writing.
                        </small>
                    </div>

                    <p>
                        <button type="submit" class="btn btn--primary">
                            {{ $document === null ? 'Publish it' : 'Save changes' }}
                        </button>

                        @if ($document !== null)
                            <small class="muted" style="margin-left:12px">
                                Last saved {{ $document->published_at?->format('j M Y') }}.
                                Bookings already made keep the version their guest
                                agreed to.
                            </small>
                        @endif
                    </p>
                </form>
            </section>
        @endforeach

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">Ours, not yours</h2>
            </div>

            <p class="muted">
                These are the platform's documents and cover our relationship with
                you, not yours with your guests. The data processing agreement is the
                one that says your guest list is yours: you decide what happens to
                it, we only process it on your instructions.
            </p>

            <p>
                <a href="{{ route('legal', 'terms') }}" target="_blank" rel="noopener">Terms of Service</a> ·
                <a href="{{ route('legal', 'privacy') }}" target="_blank" rel="noopener">Privacy Policy</a> ·
                <a href="{{ route('legal', 'dpa') }}" target="_blank" rel="noopener">Data Processing Agreement</a> ·
                <a href="{{ route('legal', 'cookies') }}" target="_blank" rel="noopener">Cookie Notice</a>
            </p>
        </section>
    </div>
@endsection
