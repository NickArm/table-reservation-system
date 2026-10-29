{{--
    The front page (SPEC §33).

    Written for one reader: somebody who runs a beach bar on an island, is
    looking at this in February on a phone, and has been sold software before.
    So it opens with the thing none of the competitors do — sunbeds — and
    hands out a working login before asking for anything at all.

    The demo box is a config switch, not a paragraph. The day this has paying
    customers is the day those credentials stop being printed here.
--}}
@extends('layouts.base')

@section('title', config('app.name').' — reservations for beach bars and tavernas')
@section('description', 'Take sunbed and table bookings online, prepaid, into your own Stripe or PayPal account. Built for Greek beach bars and tavernas.')

@section('body')
    <div class="land">
        <header class="land__bar shell">
            <a class="land__mark" href="{{ route('home') }}">{{ config('app.name') }}</a>

            <nav class="row" style="gap:8px">
                <a class="btn btn--ghost btn--small" href="{{ route('login') }}">Sign in</a>
                <a class="btn btn--small" href="{{ route('register') }}">Start free</a>
            </nav>
        </header>

        {{-- The sun, and the sea under it. Two gradients and no photograph:
             a stock image of somebody else's beach is the first thing an
             owner here recognises as not theirs. --}}
        <section class="land__hero">
            <div class="land__sun" aria-hidden="true"></div>

            <div class="shell land__heroinner">
                <p class="land__eyebrow">For beach bars, beach clubs and tavernas</p>

                <h1 class="land__title">
                    Your sunbeds, booked and paid for<br>
                    before anyone walks down to the sand.
                </h1>

                <p class="land__sub">
                    Guests pick a spot, a day and a time on their phone, pay, and get a
                    voucher. You open one screen in the morning and know exactly who is
                    coming, where they are sitting, and what they have already paid.
                </p>

                <div class="land__cta">
                    <a class="btn btn--big" href="{{ route('register') }}">
                        Start free for {{ config('tenancy.trial_days') }} days
                    </a>
                    <a class="btn btn--ghost btn--big" href="#demo">See the real thing first</a>
                </div>

                <p class="land__fine">
                    No card. No installation. Nothing to take down at the end of the season.
                </p>
            </div>
        </section>

        {{-- The one sentence that decides whether anything below gets read. --}}
        <section class="shell land__band">
            <div class="land__claim">
                <strong>Everyone else built this for restaurants.</strong>
                A table is booked for two hours at eight. A sunbed is booked for a
                whole Tuesday, in the front row, by four people who want to be
                together. We built the second one properly, and the first one too.
            </div>
        </section>

        <section class="shell land__section" id="features">
            <h2 class="land__h2">What you actually get</h2>

            <div class="land__grid">
                @php
                    $features = [
                        [
                            'sun',
                            'Sunbeds and tables, side by side',
                            'Set up a front row of doubles, a back row of singles and twelve tables inside, each with its own hours, prices and minimum stay. "A1 to A20" creates twenty at once.',
                        ],
                        [
                            'card',
                            'The money goes to you, not through us',
                            'Your own Stripe or PayPal keys. Guests pay into your account, we never hold a cent of it, and your tax affairs stay yours. Or switch payments off and let them settle at the table.',
                        ],
                        [
                            'tag',
                            'Saturday is not Tuesday',
                            'A different price per day, per time of day, per row. Set a rule once — front row at the weekend, half price after six — and it applies itself for the rest of the season.',
                        ],
                        [
                            'bell',
                            'The morning screen',
                            'Who is coming today, in arrival order, with what they asked for. One tap to seat them, one to mark a no-show. Built to be read on a phone in the middle of service.',
                        ],
                        [
                            'people',
                            'You will know your regulars',
                            'Every booking matched to a guest, with their history, their notes and their no-shows. The fourth time the same family books the same two sunbeds, the screen tells you.',
                        ],
                        [
                            'globe',
                            'On your own website in one line',
                            'Paste one line into your site and a search bar appears on it. Visitors pick a date there and land here ready to pay. Works on WordPress, Wix, Squarespace, anything.',
                        ],
                        [
                            'mail',
                            'Nobody is chasing anybody',
                            'The guest gets a voucher the second they pay and a reminder before they are due. You get an email and a bell. Quiet hours hold the night-time ones until morning.',
                        ],
                        [
                            'shield',
                            'Your terms, agreed in writing',
                            'Your own cancellation policy and booking terms, ticked at checkout, with each booking keeping the exact version its guest agreed to. Suggested wording included — change it to match how you work.',
                        ],
                        [
                            'chart',
                            'How the season went',
                            'What came in after refunds, which days carried it, how many never turned up and what it cost you, and where the bookings came from. The October screen.',
                        ],
                    ];
                @endphp

                @foreach ($features as [$icon, $title, $body])
                    <article class="land__card">
                        <span class="land__icon" aria-hidden="true">
                            @include('marketing.partials.icon', ['name' => $icon])
                        </span>
                        <h3>{{ $title }}</h3>
                        <p>{{ $body }}</p>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="shell land__section">
            <h2 class="land__h2">Three taps, from the sand</h2>

            <p class="muted" style="max-width:56ch;margin:0 0 28px">
                A tourist on a beach with one bar of signal will not read a page, make
                an account or install anything. So they do not have to.
            </p>

            <ol class="land__steps">
                <li>
                    <b>1</b>
                    <strong>Pick a day and how many</strong>
                    <span>They land on your page from a QR code, your website or a link you sent on WhatsApp.</span>
                </li>
                <li>
                    <b>2</b>
                    <strong>Choose the actual spot</strong>
                    <span>Front row or back, table by the water or inside, with the price for that day already on it.</span>
                </li>
                <li>
                    <b>3</b>
                    <strong>Pay, and that is it</strong>
                    <span>Card or PayPal, a voucher by email, and a note to you with anything they asked for.</span>
                </li>
            </ol>
        </section>

        {{-- Config-gated. An owner weighing this up will not fill in a signup
             form to find out what it looks like, so they get a real login to a
             real venue with a season of bookings behind it. --}}
        @if (config('demo.enabled'))
            <section class="shell land__section" id="demo">
                <div class="land__demo">
                    <div class="land__demohead">
                        <h2 class="land__h2" style="margin:0">Go and have a look</h2>
                        <p class="muted" style="margin:6px 0 0">
                            A real beach bar with a season of real bookings in it. Sign in,
                            break whatever you like — it is rebuilt from scratch regularly.
                        </p>
                    </div>

                    <div class="land__logins">
                        @foreach (config('demo.accounts') as $account)
                            <div class="land__login">
                                <span class="pill pill--muted">{{ $account['role'] }}</span>
                                <p class="land__cred"><code>{{ $account['email'] }}</code></p>
                                <p class="land__cred"><code>{{ config('demo.password') }}</code></p>
                                <p class="muted">{{ $account['sees'] }}</p>
                            </div>
                        @endforeach
                    </div>

                    <p style="margin:0">
                        <a class="btn" href="{{ route('login') }}">Sign in to the demo</a>
                    </p>
                </div>
            </section>
        @endif

        <section class="shell land__section">
            <div class="land__close">
                <h2 class="land__h2" style="margin:0 0 10px">Set it up before the season</h2>
                <p class="muted" style="margin:0 0 22px;max-width:52ch">
                    {{ config('tenancy.trial_days') }} days free, no card, and everything
                    switched on. If it is not doing what you need by the end of it,
                    you have lost an afternoon.
                </p>

                <div class="land__cta">
                    <a class="btn btn--big" href="{{ route('register') }}">Start free</a>
                </div>
            </div>
        </section>

        <footer class="land__foot shell">
            <span class="muted">© {{ now()->year }} {{ config('app.name') }}</span>

            <nav class="row" style="gap:14px;flex-wrap:wrap">
                <a class="muted" href="{{ route('legal', 'terms') }}">Terms</a>
                <a class="muted" href="{{ route('legal', 'privacy') }}">Privacy</a>
                <a class="muted" href="{{ route('legal', 'cookies') }}">Cookies</a>
                <a class="muted" href="{{ route('login') }}">Sign in</a>
            </nav>
        </footer>
    </div>
@endsection
