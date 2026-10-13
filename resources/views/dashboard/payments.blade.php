@extends('layouts.dashboard')

@section('title', 'Payments')

@section('main')
    <div class="page-head">
        <div>
            <h1>Payments</h1>
            <p class="muted">Your own account, your own money. We never hold it.</p>
        </div>

        @if ($active !== null)
            <span class="pill @if ($active->isTestMode()) pill--muted @else pill--ok @endif">
                {{ $active->gateway->label() }} · {{ $active->mode->label() }}
            </span>
        @endif
    </div>

    @if ($active !== null && $active->isTestMode())
        <div class="notice notice--warn" style="margin-bottom:24px">
            <strong>Test mode.</strong>
            Your booking page shows a banner and no real money moves. Switch to live
            when you are ready to take bookings.
        </div>
    @endif

    @if ($active === null)
        <div class="notice" style="margin-bottom:24px">
            No payment method is switched on, so bookings are confirmed without one and
            guests settle with you at the venue. That is a perfectly good way to run.
        </div>
    @endif

    @foreach ($gateways as $gateway)
        @php $setting = $settings[$gateway->value] ?? null; @endphp

        <section class="card" style="margin-bottom:16px">
            <div class="card__head">
                <h2 class="h2">{{ $gateway->label() }}</h2>

                @if ($setting?->is_active)
                    <span class="pill pill--ok">in use</span>
                @endif
            </div>

            <p class="muted" style="margin:0 0 14px">{{ $gateway->describe() }}</p>

            <form method="POST" action="{{ route('dashboard.payments.update', $gateway->value) }}"
                  class="stack">
                @csrf
                @method('PATCH')

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
                        <p class="muted" style="margin:6px 0 0">
                            In Stripe, add a webhook pointing at
                            <code>{{ $webhookUrls[$gateway->value] }}</code>
                            for the event <code>checkout.session.completed</code>.
                        </p>
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
                        <p class="muted" style="margin:6px 0 0">
                            Point it at <code>{{ $webhookUrls[$gateway->value] }}</code>.
                        </p>
                    </div>
                @endif

                <div class="grid grid--3">
                    @if ($gateway->needsCredentials())
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
                    @else
                        <input type="hidden" name="mode" value="live">
                    @endif

                    <div style="display:flex;align-items:flex-end">
                        <label class="consent" style="padding:10px 12px">
                            <input type="checkbox" name="is_active" value="1"
                                   @checked($setting?->is_active)>
                            <span>Use this one</span>
                        </label>
                    </div>
                </div>

                <div class="row">
                    <button type="submit" class="btn">Save</button>

                    @if ($gateway->needsCredentials() && $setting?->hasCredentials())
                        <button type="submit" class="btn btn--ghost"
                                formaction="{{ route('dashboard.payments.test', $gateway->value) }}">
                            Test connection
                        </button>
                    @endif

                    @if ($setting?->last_tested_at !== null)
                        <span class="muted">
                            last tested {{ $setting->last_tested_at->diffForHumans() }}
                            @if ($setting->last_test_result !== 'ok')
                                — {{ $setting->last_test_result }}
                            @endif
                        </span>
                    @endif
                </div>
            </form>
        </section>
    @endforeach

    <p class="muted" style="margin-top:24px">
        Guests pay into your account directly, so invoicing and tax on those payments
        are yours. We only ever charge you for the subscription.
    </p>
@endsection
