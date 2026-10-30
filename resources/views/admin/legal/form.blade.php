{{--
    Writing one of the platform's legal texts (SPEC §12.2).

    Two buttons, not one: Save as draft and Publish. The lawyer's text arrives
    in pieces and gets read twice before it goes live, and a screen with only
    Publish turns every half-finished paste into a live contract.
--}}
@extends('layouts.admin')

@section('title', $type->title().' — '.config('app.name'))

@section('main')
    @include('partials.crumb', ['trail' => [
        ['Legal', route('admin.legal.index')],
        [$type->title(), null],
    ]])

    <div class="page-head">
        <div>
            <h1>{{ $type->title() }}</h1>
            <p class="muted">
                Published at <a href="{{ route('legal', $type->path()) }}" target="_blank"
                                rel="noopener">/{{ $type->path() }}</a>
                · {{ $type->who() }}
            </p>
        </div>
    </div>

    @if ($isDraft)
        <p class="notice" style="margin-bottom:24px;max-width:820px">
            You are editing an <strong>unpublished draft</strong>. Nothing on this page
            is live until you publish it.
        </p>
    @elseif ($document !== null && $document->isPlaceholder())
        <p class="notice notice--warn" style="margin-bottom:24px;max-width:820px">
            <strong>This is the placeholder.</strong>
            The public page says so, in as many words. Replace it with the real
            wording and give it a version number of its own — the placeholder is
            <code>{{ $document->version }}</code>.
        </p>
    @endif

    <form method="POST" action="{{ route('admin.legal.update', $type->path()) }}"
          class="stack" style="max-width:820px">
        @csrf
        @method('PATCH')

        <section class="card stack">
            <div class="card__head"><h2 class="h2">The text</h2></div>

            <div class="field">
                <label for="version">Version</label>
                <input id="version" type="text" name="version" required maxlength="32"
                       value="{{ old('version', $isDraft ? $document?->version : '') }}"
                       placeholder="1.0">
                <small class="muted">
                    What an account will have agreed to, by name. A published version
                    cannot be edited, so every revision needs a new one.
                </small>
            </div>

            <div class="field">
                <label for="content">Content</label>
                <textarea id="content" name="content" rows="28" required
                          style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px"
                >{{ old('content', $document?->content) }}</textarea>
                <small class="muted">
                    Markdown. Headings with <code>##</code>, lists with <code>-</code>,
                    links with <code>[text](url)</code>. Raw HTML is stripped — a
                    document pasted out of Word brings half a stylesheet with it.
                </small>
            </div>
        </section>

        <section class="card stack">
            <div class="card__head"><h2 class="h2">Telling accounts about it</h2></div>

            <div class="field">
                <label for="change_summary">What changed, in one line</label>
                <input id="change_summary" type="text" name="change_summary" maxlength="255"
                       value="{{ old('change_summary') }}"
                       placeholder="Added a clause about seasonal suspension">
                <small class="muted">
                    Shown on the banner. Nobody reads a diff of a terms page; one
                    sentence is read, and most of the EU expects a material change to
                    be pointed out rather than buried.
                </small>
            </div>

            @if ($type->isAgreedTo())
                <div class="choices">
                    <label class="choice">
                        <input type="checkbox" name="needs_reacceptance" value="1"
                            @checked(old('needs_reacceptance'))>
                        <span>Venues have to agree again</span>
                    </label>
                </div>

                <p class="muted">
                    Puts a banner on every dashboard until each account agrees. For a
                    real change only — a banner that appears for every typo fix is a
                    banner nobody reads by the third time.
                </p>
            @endif
        </section>

        <p>
            <button type="submit" name="publish" value="1" class="btn">
                Publish it
            </button>
            <button type="submit" name="publish" value="0" class="btn">
                Save as a draft
            </button>
            <a href="{{ route('admin.legal.index') }}" class="btn btn--ghost">Back</a>
        </p>
    </form>

    @if ($history->isNotEmpty())
        <section class="card stack" style="max-width:820px;margin-top:24px">
            <div class="card__head">
                <h2 class="h2">Published versions</h2>
            </div>

            <p class="muted">
                Kept rather than replaced: an account that agreed to an older version
                agreed to those words, and this is where they are.
            </p>

            @foreach ($history as $version)
                <div class="history__row">
                    <div>
                        <strong>{{ $version->version }}</strong>
                        @if ($version->is_current)
                            <span class="pill pill--ok">live</span>
                        @endif
                        @if ($version->needs_reacceptance)
                            <span class="pill pill--muted">needed re-acceptance</span>
                        @endif

                        @if (filled($version->change_summary))
                            <p class="muted" style="margin:4px 0 0">{{ $version->change_summary }}</p>
                        @endif
                    </div>
                    <div class="event__meta">
                        <small class="muted">{{ $version->published_at?->format('j M Y') }}</small>
                    </div>
                </div>
            @endforeach
        </section>
    @endif
@endsection
