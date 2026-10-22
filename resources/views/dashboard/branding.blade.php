{{--
    How the booking page looks (SPEC §5.5, §27).

    One colour and two images, and nothing else on purpose. A venue gets to be
    recognisable, not to redesign the page: a neutral shell with one accent
    from the tenant looks deliberate across four hundred venues, while a free
    choice of fonts and backgrounds looks like four hundred different
    mistakes.
--}}
@extends('layouts.dashboard')

@section('title', 'Appearance — '.$tenant->name)

@section('main')
    <div class="page-head">
        <h1>Appearance</h1>
        <p>Your logo, a photograph, and one colour.</p>
    </div>

    <form method="POST" action="{{ route('dashboard.branding.update') }}"
          enctype="multipart/form-data" class="stack" style="max-width:720px">
        @csrf
        @method('PATCH')

        @foreach ($kinds as $kind)
            <section class="card stack">
                <div class="card__head">
                    <h2 class="h2">{{ $kind->label() }}</h2>
                </div>

                <p class="muted">{{ $kind->hint() }}</p>

                @php $current = $venue->{$kind->value.'Url'}(); @endphp

                @if ($current !== null)
                    <p>
                        {{-- Shown on the chequered-looking neutral card so a
                             transparent logo is visible rather than white on
                             white. --}}
                        <img src="{{ $current }}" alt="{{ $kind->label() }}"
                             style="max-width:100%;max-height:180px;background:#f5f5f4;border-radius:8px">
                    </p>
                @endif

                <div class="field">
                    <label for="{{ $kind->value }}">
                        {{ $current === null ? 'Choose a file' : 'Replace it' }}
                    </label>
                    <input id="{{ $kind->value }}" type="file" name="{{ $kind->value }}"
                           accept="image/jpeg,image/png,image/webp">
                    <small class="muted">
                        JPG, PNG or WebP, up to 8 MB. It is resized and stripped of
                        its metadata on the way in — a photograph from a phone
                        carries the place it was taken, and that does not belong on
                        a public page.
                    </small>
                </div>

                @if ($current !== null)
                    <label class="choice">
                        <input type="checkbox" name="remove[]" value="{{ $kind->value }}">
                        <span>Remove it</span>
                    </label>
                @endif
            </section>
        @endforeach

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">Colour</h2>
            </div>

            <div class="row">
                <div class="field">
                    <label for="accent_color">Accent</label>
                    <input id="accent_color" type="color" name="accent_color"
                           value="{{ old('accent_color', $venue->accent_color ?? '#0e6e6e') }}">
                    <small class="muted">
                        Used for buttons and links on your booking page. One colour,
                        not a palette — it keeps the page readable whatever you pick.
                    </small>
                </div>

                <div class="field">
                    <label for="hero_overlay">Shade over the photograph</label>
                    <input id="hero_overlay" type="number" name="hero_overlay"
                           min="0" max="80" step="5"
                           value="{{ old('hero_overlay', $venue->hero_overlay ?? 30) }}">
                    <small class="muted">
                        Per cent. Raise it if the name of the venue is hard to read
                        against a bright photograph; leave it at nothing if the
                        picture is already dark.
                    </small>
                </div>
            </div>
        </section>

        <p>
            <button type="submit" class="btn btn--primary">Save</button>
            <a href="{{ route('public.booking', $tenant) }}" class="btn btn--ghost">
                See the booking page
            </a>
        </p>
    </form>
@endsection
