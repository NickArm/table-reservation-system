@extends('layouts.base', ['accent' => $venue->accent_color])

@section('title', $venue->meta_title ?: $venue->name.' — Reservations')

@if (filled($venue->meta_description ?: $venue->description))
    @section('description', str($venue->meta_description ?: $venue->description)->limit(160)->value())
@endif

@push('head')
    <link rel="canonical" href="{{ route('public.booking', $tenant) }}">

    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $venue->name }}">
    <meta property="og:url" content="{{ route('public.booking', $tenant) }}">
    @if (filled($venue->description))
        <meta property="og:description" content="{{ str($venue->description)->limit(160) }}">
    @endif
    <meta name="twitter:card" content="summary_large_image">

    <script type="application/ld+json">{!! json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endpush

@section('body')
    <header class="hero"
            @style([
                "--scrim: {$venue->hero_overlay}%",
                '--hero-image: url('.$venue->heroUrl().')' => $venue->heroUrl() !== null,
            ])>
        @if ($venue->logoUrl() !== null)
            <img class="hero__logo" src="{{ $venue->logoUrl() }}" alt="{{ $venue->name }}"
                 width="96" height="96" fetchpriority="high">
        @endif

        <h1>{{ $venue->name }}</h1>
        @if (filled($venue->city))
            <p>{{ $venue->city }}@if (filled($venue->region)), {{ $venue->region }}@endif</p>
        @endif
    </header>

    <main class="shell" style="max-width:680px">
        <div class="card" style="margin-top:-48px;position:relative">
            <h2 style="margin:0 0 4px;font-size:var(--step-1);letter-spacing:-0.01em">Book a spot</h2>

            @if ($bookingTypes->isEmpty())
                <p class="notice" style="margin:0">
                    Online booking for {{ $venue->name }} opens here shortly.
                    @if (filled($venue->phone))
                        In the meantime, call <a href="tel:{{ $venue->phone }}">{{ $venue->phone }}</a>.
                    @endif
                </p>
            @else
                <p class="muted" style="margin:0 0 16px">
                    {{ $bookingTypes->count() === 1
                        ? 'Pick a date and we will show you what is free.'
                        : 'What would you like to book?' }}
                </p>

                @foreach ($bookingTypes as $bookingType)
                    @php $offering = $offerings[$bookingType->id] ?? null; @endphp

                    {{-- Which of these appears is entirely the venue's own setup. --}}
                    <form method="GET" action="{{ route('public.search', $tenant) }}"
                          class="stack"
                          style="margin-bottom:{{ $loop->last ? '0' : '20px' }}">
                        <input type="hidden" name="type" value="{{ $bookingType->slug }}">

                        {{-- The name stays even with one booking type: the
                             choice is what gets skipped, not the label. --}}
                        <div class="row" style="justify-content:space-between;align-items:baseline">
                            <strong>{{ $bookingType->name }}</strong>
                            <span class="muted">
                                @if ($bookingType->payment_requirement === \App\Enums\PaymentRequirement::None)
                                    free to book
                                @elseif ($offering !== null && ($offering['from_cents'] ?? 0) > 0)
                                    from {{ \App\Support\Money::format($offering['from_cents']) }}
                                    {{ $bookingType->price_basis->suffix() }}
                                @endif
                            </span>
                        </div>

                        <div class="search">
                            <div>
                                <label class="label" for="people_{{ $bookingType->id }}">People</label>
                                <select class="field" id="people_{{ $bookingType->id }}" name="people">
                                    @foreach (range(1, 10) as $number)
                                        <option value="{{ $number }}" @selected($number === 2)>{{ $number }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="label" for="date_{{ $bookingType->id }}">Date</label>
                                <input class="field" type="date" id="date_{{ $bookingType->id }}" name="date"
                                       value="{{ $today }}" min="{{ $today }}"
                                       max="{{ now($venue->timezone)->addDays((int) $bookingType->max_advance_days)->toDateString() }}">
                            </div>

                            <button type="submit" class="btn">
                                {{ $bookingTypes->count() > 1 ? 'Search' : 'See what is free' }}
                            </button>
                        </div>

                        @if ($offering !== null && $offering['areas']->isNotEmpty())
                            <p class="muted" style="margin:0">
                                {{ $offering['areas']->pluck('name')->implode(' · ') }}
                            </p>
                        @endif
                    </form>
                @endforeach
            @endif
        </div>

        @if (filled($venue->description))
            <section style="margin-top:32px">
                <h2 style="margin:0 0 8px;font-size:var(--step-1);letter-spacing:-0.01em">About</h2>
                <p style="margin:0;white-space:pre-line">{{ $venue->description }}</p>
            </section>
        @endif

        <section style="margin-top:32px">
            <h2 style="margin:0 0 8px;font-size:var(--step-1);letter-spacing:-0.01em">Find us</h2>
            <div class="stack" style="gap:4px">
                @if (filled($venue->address))
                    <p style="margin:0">{{ $venue->address }}</p>
                @endif
                @if (filled($venue->city))
                    <p style="margin:0" class="muted">{{ $venue->postal_code }} {{ $venue->city }}</p>
                @endif
                @if (filled($venue->phone))
                    <p style="margin:0"><a href="tel:{{ $venue->phone }}">{{ $venue->phone }}</a></p>
                @endif
                @if (filled($venue->website_url))
                    <p style="margin:0"><a href="{{ $venue->website_url }}" rel="noopener">{{ $venue->website_url }}</a></p>
                @endif
            </div>
        </section>

        <p class="muted" style="margin-top:40px">Reservations by {{ config('app.name') }}</p>
    </main>
@endsection
