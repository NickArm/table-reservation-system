{{--
    Settings → Developer (SPEC §15.2).

    The only screen here not written for the owner. It is written for whoever
    they forward it to, which is why it leads with a curl line that already
    has their slug and their token in it: a developer pastes one, sees a
    response, and everything after that is easier.
--}}
@extends('layouts.dashboard')

@section('title', 'Developer — '.$tenant->name)

@section('main')
    <div class="page-head">
        <h1>Developer</h1>
        <p>
            Read your availability from your own server, and send visitors here to book.
            Pass this page to whoever builds your website.
        </p>
    </div>

    <div class="stack" style="max-width:760px">

        @if ($live->isExposed())
            {{-- The leak check (SPEC §15.2.1). A browser always sends an
                 Origin header and a server never does, so a call that
                 carried one came from somebody's JavaScript. --}}
            <section class="card stack notice notice--warn">
                <h2 class="h2">Your live token looks exposed</h2>
                <p>
                    It was used from a browser
                    @if (filled($live->last_origin))
                        on <strong>{{ $live->last_origin }}</strong>
                    @endif
                    {{ $live->exposed_at?->diffForHumans() }}. Anyone who opens the
                    developer tools on that page can read it.
                </p>
                <p class="muted">
                    The token belongs on your server, which then calls us. Replace it
                    below once the page has been fixed, or it keeps working for whoever
                    copied it.
                </p>
            </section>
        @endif

        @foreach ([$live, $test] as $token)
            <section class="card stack">
                <div class="card__head">
                    <h2 class="h2">{{ $token->mode->label() }} token</h2>
                    @if ($token->last_used_at !== null)
                        <span class="pill pill--muted">Last used {{ $token->last_used_at->diffForHumans() }}</span>
                    @else
                        <span class="pill pill--muted">Never used</span>
                    @endif
                </div>

                <p class="muted">{{ $token->mode->describe() }}</p>

                @if ($revealed !== null && $revealed['mode'] === $token->mode->value)
                    <div class="secret secret--open">
                        <code data-copy>{{ $revealed['token'] }}</code>
                    </div>
                    <p class="muted">
                        Shown because you asked. Reload the page and it goes back to hidden.
                    </p>
                @else
                    <div class="secret">
                        <code>{{ $token->masked() }}</code>
                        <form method="POST" action="{{ route('dashboard.developer.reveal', $token->mode->value) }}">
                            @csrf
                            <button type="submit" class="btn btn--ghost">Show</button>
                        </form>
                    </div>
                @endif

                {{-- Two steps rather than a confirm dialog: the warning has
                     to be read, and the whole dashboard works without
                     script. --}}
                <details class="reveal">
                    <summary>Replace this token</summary>

                    <p class="muted">
                        The current {{ strtolower($token->mode->label()) }} token stops working
                        the moment you press the button. Anything using it breaks until the new
                        one is pasted in — so have somewhere to paste it before you start.
                    </p>

                    <form method="POST"
                          action="{{ route('dashboard.developer.regenerate', $token->mode->value) }}">
                        @csrf
                        <button type="submit" class="btn btn--ghost">
                            Replace the {{ strtolower($token->mode->label()) }} token
                        </button>
                    </form>
                </details>
            </section>
        @endforeach

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">Try it</h2>
            </div>

            <p class="muted">
                Your slug is already in these. Replace <code>YOUR_TOKEN</code> with the
                live token above.
            </p>

            @php
                $base = rtrim(config('app.url'), '/').'/api/v1';
                $type = $bookingType?->slug;
                $tomorrow = now($venue->timezone)->addDay()->toDateString();
            @endphp

            <pre class="snippet"><code data-copy>curl -s "{{ $base }}/times?{{ $type ? 'type='.$type.'&' : '' }}date={{ $tomorrow }}&people=2" \
  -H "Authorization: Bearer YOUR_TOKEN"</code></pre>

            <dl class="endpoints">
                <dt><code>GET /api/v1/venue</code></dt>
                <dd>Your name, timezone and currency.</dd>

                <dt><code>GET /api/v1/booking-types</code></dt>
                <dd>What you take bookings for, and how far ahead.</dd>

                <dt><code>GET /api/v1/days?month={{ now($venue->timezone)->format('Y-m') }}</code></dt>
                <dd>A whole month in one call, for colouring a calendar.</dd>

                <dt><code>GET /api/v1/times?date={{ $tomorrow }}&amp;people=2</code></dt>
                <dd>The times offered that day. A time with nothing free is not listed.</dd>

                <dt><code>GET /api/v1/availability?date={{ $tomorrow }}&amp;time=11:00</code></dt>
                <dd>What is free at that exact time, grouped by area, with prices.</dd>

                <dt><code>GET /api/v1/booking-url?people=2</code></dt>
                <dd>Where to send the visitor once your form has their choice.</dd>
            </dl>

            <p class="muted">
                Full schema: <a href="{{ route('api.v1.openapi') }}">openapi.json</a> —
                point a client generator at it.
            </p>
        </section>

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">Two rules worth knowing</h2>
            </div>

            <dl class="endpoints">
                <dt>Keep the token on your server</dt>
                <dd>
                    Never in the page's JavaScript, where anyone can read it. Your form
                    calls your server, and your server calls us. We watch for tokens
                    arriving from a browser and say so on this page.
                </dd>

                <dt>Bookings and payments happen here</dt>
                <dd>
                    These endpoints tell you what is free. The hold and the payment live
                    with the availability engine, so your form sends the visitor to your
                    booking page with their choice in the address.
                </dd>
            </dl>
        </section>

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">Last 7 days</h2>
                @if ($totals['rejected'] > 0)
                    <span class="pill pill--warn">{{ $totals['rejected'] }} refused</span>
                @endif
            </div>

            @if ($totals['calls'] === 0)
                <p class="empty">
                    No calls yet. Paste the curl line above and this fills in.
                </p>
            @else
                <p>
                    <strong>{{ number_format($totals['calls']).' calls' }}</strong>,
                    averaging {{ $totals['median_ms'] }}ms.
                    @if ($totals['rejected'] > 0)
                        <span class="muted">
                            {{ number_format($totals['rejected']) }} were refused — worth a
                            look, since a form quietly getting errors looks healthy in
                            every other measure.
                        </span>
                    @endif
                </p>

                @php $peak = max(max($daily), 1); @endphp

                <ul class="bars" aria-label="Calls per day">
                    @foreach ($daily as $day => $calls)
                        <li>
                            <span class="bars__bar"
                                  style="--height: {{ max(2, (int) round($calls / $peak * 100)) }}%"
                                  title="{{ $calls }} on {{ $day }}"></span>
                            <span class="bars__label">{{ \Carbon\CarbonImmutable::parse($day)->format('D') }}</span>
                        </li>
                    @endforeach
                </ul>

                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Endpoint</th>
                            <th scope="col">Calls</th>
                            <th scope="col">Refused</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($endpoints as $row)
                            <tr>
                                <td><code>{{ $row->endpoint }}</code></td>
                                <td>{{ number_format((int) $row->calls) }}</td>
                                <td>
                                    @if ((int) $row->rejected > 0)
                                        <span class="pill pill--warn">{{ (int) $row->rejected }}</span>
                                    @else
                                        <span class="muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    </div>
@endsection
