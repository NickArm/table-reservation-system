{{--
    Where a venue puts its own payment keys (SPEC §7.4, §24.3).

    One decision, not three forms: a venue takes money one way, and the screen
    is ordered around which way that is. What happens when a guest books right
    now is stated at the top in words, because "is this live?" is the only
    question anybody opens this page with.

    Keys that are already in are folded away. A password box sitting open next
    to a key that works invites somebody to type over it, and an empty box here
    means "leave it alone" — which is not what an open box looks like.
--}}
@extends('layouts.dashboard')

@section('title', 'Payments')

@section('main')
    @include('partials.crumb', ['trail' => [
        ['Setup', route('dashboard.setup')],
        ['Payments', null],
    ]])

    <div class="page-head">
        <div>
            <h1>Payments</h1>
            <p class="muted">Your own account, your own money. We never hold it.</p>
        </div>
    </div>

    {{-- The answer to the question this page is opened with. --}}
    @php
        $state = match (true) {
            $active === null => 'none',
            ! $active->gateway->isOnline() => 'venue',
            $active->isTestMode() => 'test',
            default => 'live',
        };
    @endphp

    <div class="paystate paystate--{{ $state }}">
        <span class="paystate__dot" aria-hidden="true"></span>

        <span class="paystate__text">
            @if ($state === 'live')
                <strong>Guests are paying you through {{ $active->gateway->label() }}.</strong>
                <small>
                    Real money, straight into your account. We never touch it.
                </small>
            @elseif ($state === 'test')
                <strong>Test mode — no money is moving.</strong>
                <small>
                    Your booking page shows a banner and every payment is pretend.
                    Switch {{ $active->gateway->label() }} to live keys when you are ready.
                </small>
            @elseif ($state === 'venue')
                <strong>Guests pay you at the venue.</strong>
                <small>
                    Bookings are confirmed without a payment. Nothing wrong with
                    running this way — most places here do.
                </small>
            @else
                <strong>Nothing is switched on yet.</strong>
                <small>
                    Bookings are confirmed without a payment and
                    guests settle with you at the venue.
                    Switch one of these on if you would rather take the money up front.
                </small>
            @endif
        </span>
    </div>

    {{-- Whatever is in use comes first. Scrolling past PayPal to reach the
         Stripe keys you are trying to fix is the small daily tax of a screen
         that treats every option as equally likely. --}}
    @php
        $ordered = collect($gateways)->sortByDesc(
            fn (\App\Enums\Gateway $gateway): int => ($settings[$gateway->value] ?? null)?->is_active ? 1 : 0
        );
    @endphp

    @foreach ($ordered as $gateway)
        @php
            $setting = $settings[$gateway->value] ?? null;
            $stored = $setting?->hasCredentials() ?? false;
        @endphp

        <section class="card" style="margin-bottom:16px">
            <div class="card__head">
                <h2 class="h2">{{ $gateway->label() }}</h2>

                <span class="row" style="gap:6px">
                    @if ($setting?->is_active)
                        <span class="pill pill--ok">in use</span>
                    @endif

                    @if ($gateway->needsCredentials() && $setting?->mode !== null)
                        <span class="pill {{ $setting->isTestMode() ? 'pill--warn' : 'pill--muted' }}">
                            {{ $setting->mode->label() }} keys
                        </span>
                    @endif
                </span>
            </div>

            <p class="muted" style="margin:0 0 14px">{{ $gateway->describe() }}</p>

            {{-- Stored, but unreadable: APP_KEY is not the one the keys were
                 saved with. "Add your keys" would be wrong advice to somebody
                 whose keys are sitting right there — so say what happened,
                 and that re-entering them fixes it. --}}
            @if ($setting?->credentialsAreUnreadable())
                <div class="notice notice--warn" style="margin-bottom:14px">
                    <strong>We can no longer read the keys saved here.</strong>
                    They were encrypted with a different application key —
                    which happens when the site is moved, restored from a
                    backup, or has its key regenerated. Nothing of yours has
                    leaked. Paste them in again below and it is fixed.
                </div>
            @endif

            <form method="POST" action="{{ route('dashboard.payments.update', $gateway->value) }}"
                  class="stack">
                @csrf
                @method('PATCH')

                @if ($gateway->needsCredentials())
                    {{-- Keys already in are folded: an empty box here means
                         "leave it alone", and an open one does not look like
                         that. --}}
                    <details class="advanced" @if (! $stored) open @endif>
                        <summary>
                            @if ($stored)
                                Keys are in · replace them
                            @else
                                Your {{ $gateway->label() }} keys
                            @endif
                        </summary>

                        <div class="stack" style="margin-top:14px">
                            @if ($gateway === \App\Enums\Gateway::Stripe)
                                <div>
                                    <label class="label" for="stripe_secret">Secret key</label>
                                    <input class="field" type="password" id="stripe_secret" name="secret_key"
                                           autocomplete="off" spellcheck="false"
                                           placeholder="{{ $setting?->mask('secret_key') ?? 'sk_live_…' }}">
                                </div>

                                <div>
                                    <label class="label" for="stripe_webhook">Webhook signing secret</label>
                                    <input class="field" type="password" id="stripe_webhook" name="webhook_secret"
                                           autocomplete="off" spellcheck="false"
                                           placeholder="{{ $setting?->mask('webhook_secret') ?? 'whsec_…' }}">
                                </div>
                            @elseif ($gateway === \App\Enums\Gateway::PayPal)
                                <div class="grid grid--2">
                                    <div>
                                        <label class="label" for="paypal_id">Client ID</label>
                                        <input class="field" type="password" id="paypal_id" name="client_id"
                                               autocomplete="off" spellcheck="false"
                                               placeholder="{{ $setting?->mask('client_id') ?? 'AX…' }}">
                                    </div>

                                    <div>
                                        <label class="label" for="paypal_secret">Secret</label>
                                        <input class="field" type="password" id="paypal_secret" name="client_secret"
                                               autocomplete="off" spellcheck="false"
                                               placeholder="{{ $setting?->mask('client_secret') ?? 'EK…' }}">
                                    </div>
                                </div>

                                <div>
                                    <label class="label" for="paypal_webhook">Webhook ID</label>
                                    <input class="field" type="password" id="paypal_webhook" name="webhook_id"
                                           autocomplete="off" spellcheck="false"
                                           placeholder="{{ $setting?->mask('webhook_id') ?? 'WH-…' }}">
                                </div>
                            @endif

                            <p class="muted" style="margin:0;font-size:var(--step--2)">
                                Leave a box empty to keep the key that is already in.
                                We never send one back to this page.
                            </p>
                        </div>
                    </details>

                    {{-- The step owners get wrong, so it is not small print
                         under a field: without it the payment succeeds at the
                         gateway and the booking never confirms. --}}
                    <div class="hook">
                        <p class="hook__title">
                            Paste this into {{ $gateway->label() }} as a webhook
                            @if ($gateway === \App\Enums\Gateway::Stripe)
                                for <code>checkout.session.completed</code>
                            @endif
                        </p>

                        <pre class="snippet"><code data-copy>{{ $webhookUrls[$gateway->value] }}</code></pre>
                    </div>

                    <div class="grid grid--3">
                        <div>
                            <label class="label" for="mode_{{ $gateway->value }}">Keys</label>
                            <select class="field" id="mode_{{ $gateway->value }}" name="mode">
                                @foreach (\App\Enums\GatewayMode::cases() as $mode)
                                    <option value="{{ $mode->value }}"
                                            @selected(($setting?->mode ?? \App\Enums\GatewayMode::Test) === $mode)>
                                        {{ $mode->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="label" for="descriptor_{{ $gateway->value }}">
                                On the statement
                            </label>
                            <input class="field" type="text" id="descriptor_{{ $gateway->value }}"
                                   name="statement_descriptor" maxlength="22"
                                   value="{{ $setting?->statement_descriptor }}"
                                   placeholder="{{ \Illuminate\Support\Str::limit($venue?->name ?? '', 20, '') }}">
                        </div>

                        <div style="display:flex;align-items:flex-end">
                            <label class="consent" style="padding:10px 12px">
                                <input type="checkbox" name="is_active" value="1"
                                       @checked($setting?->is_active)>
                                <span>Use this one</span>
                            </label>
                        </div>
                    </div>
                @else
                    <input type="hidden" name="mode" value="live">

                    <label class="consent" style="padding:10px 12px;max-width:260px">
                        <input type="checkbox" name="is_active" value="1" @checked($setting?->is_active)>
                        <span>Use this one</span>
                    </label>
                @endif

                <div class="row">
                    <button type="submit" class="btn">Save</button>

                    {{--
                        One form, one method, one address. The previous
                        version used `formaction` to point this button at the
                        test route, which changes the URL and not the method —
                        so the form's own PATCH went to a POST-only route and
                        the owner got a 405 instead of an answer.

                        It saves first on purpose: somebody who has just typed
                        a key and pressed this means "is this one right", and
                        testing without saving would check the previous key
                        and report success.
                    --}}
                    @if ($gateway->needsCredentials())
                        <button type="submit" name="test" value="1" class="btn btn--ghost">
                            Save and test
                        </button>
                    @endif
                </div>

                {{-- A failed test is the reason the money is not arriving, so
                     it reads as a failure rather than as a grey aside. --}}
                @if ($setting?->last_tested_at !== null)
                    <p class="hook__result {{ $setting->last_test_result === 'ok' ? 'hook__result--ok' : 'hook__result--bad' }}">
                        @if ($setting->last_test_result === 'ok')
                            <strong>These keys work.</strong>
                        @else
                            <strong>Last test failed.</strong> {{ $setting->last_test_result }}
                        @endif
                        <span class="muted">· checked {{ $setting->last_tested_at->diffForHumans() }}</span>
                    </p>
                @endif
            </form>
        </section>
    @endforeach

    <p class="muted" style="margin-top:24px">
        Guests pay into your account directly, so invoicing and tax on those payments
        are yours. We only ever charge you for the subscription.
    </p>
@endsection
