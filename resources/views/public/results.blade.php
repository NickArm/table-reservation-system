@extends('layouts.base', ['accent' => $venue->accent_color])

@section('title', 'Book at '.$venue->name)

@section('body')
    @include('public.partials.test-mode')

    <header class="bar">
        <div class="bar__inner">
            <a href="{{ route('public.booking', $tenant) }}" class="bar__back" aria-label="Back">←</a>
            <strong>{{ $venue->name }}</strong>
        </div>
    </header>

    <main class="shell" style="max-width:680px">
        @if ($errors->any())
            <ul class="errors" style="margin:0 0 20px">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        <form method="GET" action="{{ route('public.search', $tenant) }}" class="card" style="margin-bottom:20px">
            @if ($bookingTypes->count() > 1)
                <div style="margin-bottom:14px">
                    <label class="label" for="type">What</label>
                    <select class="field" id="type" name="type" onchange="this.form.submit()">
                        @foreach ($bookingTypes as $type)
                            <option value="{{ $type->slug }}" @selected($type->id === $bookingType->id)>
                                {{ $type->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @else
                <input type="hidden" name="type" value="{{ $bookingType->slug }}">
            @endif

            <div class="search">
                <div>
                    <label class="label" for="people">People</label>
                    <select class="field" id="people" name="people">
                        @foreach (range(1, 10) as $number)
                            <option value="{{ $number }}" @selected($number === $partySize)>{{ $number }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="label" for="date">Date</label>
                    <input class="field" type="date" id="date" name="date" value="{{ $date }}"
                           min="{{ $today }}" max="{{ $maxDate }}">
                </div>

                <button type="submit" class="btn">Search</button>
            </div>
        </form>

        <h1 style="margin:0 0 4px;font-size:var(--step-1);letter-spacing:-0.01em">
            {{ $localDate->format('l j F') }}
        </h1>
        <p class="muted" style="margin:0 0 20px">
            {{ $partySize }} {{ \Illuminate\Support\Str::plural('person', $partySize) }}
            · {{ $bookingType->name }}
        </p>

        @if ($times === [])
            <div class="card">
                <h2 class="h2" style="margin-bottom:6px">Nothing free that day</h2>
                <p class="muted" style="margin:0 0 16px">
                    Try another date — or call us and we will see what we can do.
                </p>

                <div class="row">
                    <a class="btn btn--ghost"
                       href="{{ route('public.search', [$tenant, 'type' => $bookingType->slug, 'people' => $partySize, 'date' => $localDate->addDay()->toDateString()]) }}">
                        Try {{ $localDate->addDay()->format('D j M') }}
                    </a>

                    @if (filled($venue->phone))
                        <a class="btn btn--ghost" href="tel:{{ $venue->phone }}">Call {{ $venue->phone }}</a>
                    @endif
                </div>
            </div>
        @else
            <div class="times" role="group" aria-label="Times">
                @foreach ($times as $offer)
                    <a class="time @if ($selected !== null && $offer->label() === $selected->label()) time--on @endif"
                       href="{{ route('public.search', [
                           $tenant,
                           'type' => $bookingType->slug,
                           'people' => $partySize,
                           'date' => $date,
                           'time' => $offer->label(),
                       ]) }}"
                       @if ($selected !== null && $offer->label() === $selected->label()) aria-current="true" @endif>
                        {{ $offer->label() }}
                        <small>{{ $offer->available }} left</small>
                    </a>
                @endforeach
            </div>

            @if ($selected !== null)
                <p class="muted" style="margin:18px 0 12px">
                    {{ $selected->slot->describe() }}
                </p>

                @foreach ($areas as $area)
                    <section class="card" style="margin-bottom:14px">
                        <div class="card__head">
                            <h2 class="h2">{{ $area->area->name }}</h2>
                            <span class="pill">{{ $area->count() }} free</span>
                        </div>

                        @if (filled($area->area->description))
                            <p class="muted" style="margin:0 0 14px">{{ $area->area->description }}</p>
                        @endif

                        <div class="spots">
                            @foreach ($area->offers as $spot)
                                <form method="POST" action="{{ route('public.hold', $tenant) }}">
                                    @csrf
                                    <input type="hidden" name="resource" value="{{ $spot->resource->id }}">
                                    <input type="hidden" name="starts_at" value="{{ $spot->slot->startsAt->toIso8601String() }}">
                                    <input type="hidden" name="people" value="{{ $partySize }}">

                                    <button type="submit" class="spot">
                                        <span class="spot__label">{{ $spot->label() }}</span>

                                        @if ($spot->contents() !== '')
                                            <span class="spot__what muted">{{ $spot->contents() }}</span>
                                        @endif

                                        <span class="spot__price">
                                            @if ($spot->price->isFree())
                                                Free
                                            @else
                                                {{ \App\Support\Money::format($spot->price->totalCents) }}
                                            @endif
                                        </span>

                                        @if ($spot->price->rule !== null)
                                            <span class="spot__what muted">{{ $spot->price->rule->describe() }}</span>
                                        @endif
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            @endif
        @endif

        <p class="muted" style="margin-top:32px">Reservations by {{ config('app.name') }}</p>
    </main>
@endsection
