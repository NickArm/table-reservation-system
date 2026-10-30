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
    @include('partials.crumb', ['trail' => [
        ['Setup', route('dashboard.setup')],
        ['Terms & cancellation', null],
    ]])

    <div class="page-head">
        <div>
            <h1>Terms &amp; cancellation</h1>
            <p class="muted">What your guests agree to when they book.</p>
        </div>
    </div>

    {{-- Whether a guest can actually read these, in one line. It is the only
         thing about this screen that is urgent: an unpublished cancellation
         policy is a refund argument waiting to happen. --}}
    @php
        $published = collect($documents)->filter(fn (array $entry): bool => $entry['document'] !== null)->count();
        $total = count($documents);
    @endphp

    <div class="notice @if ($published < $total) notice--warn @endif" style="margin-bottom:24px;max-width:820px">
        @if ($published === 0)
            <strong>Neither is published.</strong>
            Guests are booking without agreeing to anything, which leaves you
            without a cancellation policy to point at.
        @elseif ($published < $total)
            <strong>{{ $published }} of {{ $total }} published.</strong>
            The other one is still our suggested wording and no guest has seen it.
        @else
            <strong>Both are live.</strong>
            Every guest ticks these before paying, and each booking keeps the
            version its guest agreed to.
        @endif
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

                {{-- Published wording is folded. Two editors of eighteen rows
                     open at once is most of a screen of monospace to scroll
                     past, on a page somebody usually opens to check a date. --}}
                <details class="advanced" @if ($document === null) open @endif>
                    <summary>
                        @if ($document === null)
                            Read it through and publish it
                        @else
                            Change the wording
                        @endif
                    </summary>

                    <form method="POST" action="{{ route('dashboard.legal.update', $type->path()) }}"
                          class="stack" style="margin-top:14px">
                        @csrf
                        @method('PATCH')

                        <div>
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

                        <div>
                            <button type="submit" class="btn">
                                {{ $document === null ? 'Publish it' : 'Save changes' }}
                            </button>
                        </div>
                    </form>
                </details>

                @if ($document !== null)
                    <p class="muted" style="margin:0;font-size:var(--step--2)">
                        Last saved {{ $document->published_at?->format('j M Y') }}.
                        Bookings already made keep the version their guest agreed to.
                    </p>
                @endif
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
