@extends('layouts.dashboard')

@section('title', 'Setup')

@section('main')
    <div class="page-head">
        <div>
            <h1>Setup</h1>
            <p class="muted">What guests can book here, and what you have to give them.</p>
        </div>

        @if ($bookingTypes->isNotEmpty())
            <a class="btn btn--ghost" href="{{ route('public.booking', $tenant) }}">View your page</a>
        @endif
    </div>

    @if ($bookingTypes->isEmpty())
        <div class="empty" style="margin-bottom:24px">
            <h2 class="h2" style="margin-bottom:6px">What do you take bookings for?</h2>
            <p class="muted" style="margin:0 0 20px">
                Pick one to start. You can add the other later, and change anything.
            </p>

            <div class="row" style="justify-content:center">
                @foreach ($presets as $key => $preset)
                    <form method="POST" action="{{ route('dashboard.booking-types.store') }}">
                        @csrf
                        @foreach ($preset['fields'] as $field => $value)
                            <input type="hidden" name="{{ $field }}" value="{{ $value }}">
                        @endforeach
                        <button type="submit" class="btn">{{ $preset['label'] }}</button>
                    </form>
                @endforeach
            </div>
        </div>
    @else
        <div class="grid grid--2" style="margin-bottom:24px">
            @foreach ($bookingTypes as $bookingType)
                <section class="card">
                    <div class="card__head">
                        <h2 class="h2">{{ $bookingType->name }}</h2>
                        <span class="pill">{{ $bookingType->booking_mode->label() }}</span>
                    </div>

                    <p class="muted" style="margin:0 0 14px">
                        {{ $counts[$bookingType->id] ?? 0 }}
                        {{ $bookingType->resource_type->plural() }}
                        · {{ \App\Support\Money::format($bookingType->default_price_cents) }}
                        {{ $bookingType->price_basis->suffix() }}
                        · {{ $bookingType->payment_requirement->label() }}
                    </p>

                    <a class="btn btn--ghost"
                       href="{{ route('dashboard.booking-types.show', $bookingType) }}">
                        Manage {{ $bookingType->resource_type->plural() }}
                    </a>
                </section>
            @endforeach
        </div>
    @endif

    <section class="card">
        <details class="advanced" @if ($bookingTypes->isEmpty()) open @endif>
            <summary>
                @if ($bookingTypes->isEmpty())
                    Set one up myself
                @else
                    Add another thing to book
                @endif
            </summary>

            <hr class="divider">

            <form method="POST" action="{{ route('dashboard.booking-types.store') }}" class="stack">
                @csrf

                <div class="grid grid--2">
                    <div>
                        <label class="label" for="name">Name</label>
                        <input class="field" type="text" id="name" name="name" required
                               maxlength="80" value="{{ old('name') }}"
                               placeholder="Sunbeds on the beach">
                    </div>

                    <div>
                        <label class="label" for="resource_type">What is it</label>
                        <select class="field" id="resource_type" name="resource_type" required>
                            @foreach ($resourceTypes as $type)
                                <option value="{{ $type->value }}"
                                        @selected(old('resource_type') === $type->value)>
                                    {{ $type->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div>
                    <label class="label" for="booking_mode">How guests pick a time</label>
                    <select class="field" id="booking_mode" name="booking_mode" required>
                        @foreach ($modes as $mode)
                            <option value="{{ $mode->value }}"
                                    @selected(old('booking_mode') === $mode->value)>
                                {{ $mode->label() }} — {{ $mode->description() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="grid grid--3">
                    <div>
                        <label class="label" for="default_price">Price each</label>
                        <input class="field" type="number" id="default_price" name="default_price"
                               step="0.50" min="0" max="9999" required
                               value="{{ old('default_price', '20') }}">
                    </div>

                    <div>
                        <label class="label" for="payment_requirement">Payment</label>
                        <select class="field" id="payment_requirement" name="payment_requirement" required>
                            @foreach ($paymentOptions as $option)
                                <option value="{{ $option->value }}"
                                        @selected(old('payment_requirement') === $option->value)>
                                    {{ $option->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="label" for="min_lead_time_minutes">Close bookings</label>
                        <select class="field" id="min_lead_time_minutes" name="min_lead_time_minutes" required>
                            @foreach ([0 => 'Right up to the time', 30 => '30 minutes before', 60 => '1 hour before', 120 => '2 hours before', 1440 => 'The day before'] as $minutes => $label)
                                <option value="{{ $minutes }}"
                                        @selected((int) old('min_lead_time_minutes', 60) === $minutes)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <details class="advanced">
                    <summary>Deposit wording and description</summary>

                    <div class="stack" style="margin-top:14px">
                        <div>
                            <label class="label" for="deposit_label">
                                What the price is called at checkout
                            </label>
                            <input class="field" type="text" id="deposit_label" name="deposit_label"
                                   maxlength="120" value="{{ old('deposit_label') }}"
                                   placeholder="Deposit, deducted from your bill">
                            <p class="muted" style="margin:6px 0 0">
                                Leave empty to just call it the price.
                            </p>
                        </div>

                        <div>
                            <label class="label" for="description">Shown to guests</label>
                            <textarea class="field" id="description" name="description" rows="3"
                                      style="min-height:88px;padding:12px 14px"
                                      maxlength="2000">{{ old('description') }}</textarea>
                        </div>
                    </div>
                </details>

                <div>
                    <button type="submit" class="btn">Create</button>
                </div>
            </form>
        </details>
    </section>
@endsection
