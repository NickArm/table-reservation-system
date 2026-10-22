@extends('layouts.base', ['accent' => $venue->accent_color])

@section('title', 'Your details — '.$venue->name)

@section('body')
    @include('public.partials.test-mode')

    <header class="bar">
        <div class="bar__inner">
            <form method="POST" action="{{ route('public.hold.release', [$tenant, $hold]) }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="bar__back" aria-label="Back to the list">←</button>
            </form>
            <strong>{{ $venue->name }}</strong>

            {{-- Counts down without a line of JavaScript: the page is only
                 open for fifteen minutes anyway (SPEC §24.1). --}}
            <span class="pill" style="margin-left:auto">
                held until {{ $hold->expires_at->setTimezone($timezone)->format('H:i') }}
            </span>
        </div>
    </header>

    <main class="shell" style="max-width:560px">
        <section class="card" style="margin-bottom:20px">
            <div class="card__head">
                <h1 class="h2">{{ $resource->label }}</h1>
                <strong>
                    @if ($price->isFree())
                        No payment
                    @else
                        {{ \App\Support\Money::format($price->totalCents) }}
                    @endif
                </strong>
            </div>

            <dl class="facts">
                <dt>When</dt>
                <dd>
                    {{ $hold->starts_at->setTimezone($timezone)->format('l j F') }},
                    {{ $hold->starts_at->setTimezone($timezone)->format('H:i') }}
                    @if ($hold->ends_at->setTimezone($timezone)->format('H:i') !== '00:00')
                        → {{ $hold->ends_at->setTimezone($timezone)->format('H:i') }}
                    @endif
                </dd>

                <dt>Who</dt>
                <dd>{{ $hold->party_size }} {{ \Illuminate\Support\Str::plural('person', $hold->party_size) }}</dd>

                @if ($area !== null)
                    <dt>Where</dt>
                    <dd>
                        {{ $area->name }}
                        {{-- The line that sold them the spot, repeated here so
                             the last screen before paying confirms they picked
                             the row they meant. --}}
                        @if (filled($area->description))
                            <span class="muted">{{ $area->description }}</span>
                        @endif
                    </dd>
                @endif

                @if ($resource->componentSummary() !== '')
                    <dt>Includes</dt>
                    <dd>{{ $resource->componentSummary() }}</dd>
                @endif

                @if (! $price->isFree() && filled($bookingType->deposit_label))
                    <dt>Payment</dt>
                    <dd>{{ $bookingType->deposit_label }}</dd>
                @endif
            </dl>
        </section>

        @if ($errors->any())
            <ul class="errors" style="margin:0 0 20px">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        <form method="POST" action="{{ route('public.book.store', [$tenant, $hold]) }}" class="stack">
            @csrf

            {{-- Not shown, not read out, not tabbed to. Bots fill it in. --}}
            <div style="position:absolute;left:-9999px" aria-hidden="true">
                <label for="website">Leave this empty</label>
                <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
            </div>

            <div>
                <label class="label" for="name">Your name</label>
                <input class="field" type="text" id="name" name="name" required maxlength="80"
                       autocomplete="name" value="{{ old('name') }}">
            </div>

            <div>
                <label class="label" for="email">Email</label>
                <input class="field" type="email" id="email" name="email" required maxlength="160"
                       autocomplete="email" inputmode="email" value="{{ old('email') }}">
                <p class="muted" style="margin:6px 0 0">Your confirmation goes here.</p>
            </div>

            <div>
                <label class="label" for="phone">Phone</label>
                <div class="phone">
                    <select class="field" name="calling_code" aria-label="Country code">
                        @foreach ($callingCodes as $code => $label)
                            <option value="{{ $code }}" @selected(old('calling_code', '+30') === $code)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>

                    <input class="field" type="tel" id="phone" name="phone" required maxlength="30"
                           autocomplete="tel" inputmode="tel" value="{{ old('phone') }}">
                </div>
                <p class="muted" style="margin:6px 0 0">
                    Only so we can reach you about this booking.
                </p>
            </div>

            <div>
                <label class="label" for="notes">Anything we should know?</label>
                <textarea class="field" id="notes" name="notes" rows="3" maxlength="500"
                          style="min-height:88px;padding:12px 14px"
                          placeholder="A birthday, an allergy, a bottle on ice…">{{ old('notes') }}</textarea>
            </div>

            {{--
                Agreeing to the venue's terms (SPEC §12.1).

                The links only appear once the venue has actually written the
                documents — a link to a page that 404s is worse than no link,
                and most accounts have written nothing on their first day. The
                sentence underneath is the policy itself, which is the part a
                tourist on a phone will actually read.
            --}}
            <label class="consent">
                <input type="checkbox" name="terms" value="1" required @checked(old('terms'))>
                <span>
                    @if ($documents['terms'] || $documents['cancellation'])
                        I agree to
                        @if ($documents['terms'])
                            the <a href="{{ route('public.legal', [$tenant, 'terms']) }}"
                                   target="_blank" rel="noopener">terms</a>
                        @else
                            the terms
                        @endif
                        and
                        @if ($documents['cancellation'])
                            the <a href="{{ route('public.legal', [$tenant, 'cancellation-policy']) }}"
                                   target="_blank" rel="noopener">cancellation policy</a>.
                        @else
                            the cancellation policy.
                        @endif
                    @else
                        I agree to the terms and the cancellation policy.
                    @endif

                    <small class="muted">{{ $cancellationPolicy }}</small>
                </span>
            </label>

            <label class="consent">
                <input type="checkbox" name="marketing" value="1" @checked(old('marketing'))>
                <span class="muted">Send me occasional news from {{ $venue->name }}. Optional.</span>
            </label>

            <button type="submit" class="btn btn--block">
                @if ($price->isFree())
                    Confirm booking
                @elseif ($payOnline)
                    Pay {{ \App\Support\Money::format($price->totalCents) }}
                @else
                    Confirm — pay {{ \App\Support\Money::format($price->totalCents) }} at the venue
                @endif
            </button>
        </form>
    </main>
@endsection
