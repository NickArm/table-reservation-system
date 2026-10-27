@extends('layouts.dashboard')

@section('title', $bookingType->name)

@section('main')
    @php
        $noun = $bookingType->resource_type->plural();
        $all = $resources->collapse();
        $total = $all->count();
        $open = $all->filter(fn (\App\Models\Resource $resource): bool => $resource->isBookable())->count();
    @endphp

    <div class="page-head">
        <div>
            <p class="muted" style="margin:0 0 4px">
                <a href="{{ route('dashboard.booking-types.index') }}">Setup</a>
            </p>
            <h1>{{ $bookingType->name }}</h1>
            <p class="muted">
                {{ $open }} of {{ $total }} {{ $noun }} open for booking
            </p>
        </div>

        <div class="row">
            <span class="pill">{{ $bookingType->booking_mode->label() }}</span>
            <span class="pill">
                {{ \App\Support\Money::format($bookingType->default_price_cents) }}
                {{ $bookingType->price_basis->suffix() }}
            </span>
            <span class="pill">{{ $bookingType->payment_requirement->label() }}</span>
        </div>
    </div>

    @if ($areas->isEmpty())
        <div class="empty">
            <h2 class="h2" style="margin-bottom:6px">Where are your {{ $noun }}?</h2>
            <p class="muted" style="margin:0 0 20px">
                A row, a terrace, the dining room — whatever you call the parts of your
                place. Guests see these names when they pick a spot.
            </p>

            <div class="row" style="justify-content:center">
                @foreach ($suggestedAreas as $suggestion)
                    <form method="POST"
                          action="{{ route('dashboard.booking-types.areas.store', $bookingType) }}">
                        @csrf
                        <input type="hidden" name="name" value="{{ $suggestion }}">
                        <button type="submit" class="btn btn--ghost">{{ $suggestion }}</button>
                    </form>
                @endforeach
            </div>
        </div>
    @endif

    @foreach ($areas as $area)
        @php $areaResources = $resources[$area->id] ?? collect(); @endphp

        <section class="card" style="margin-bottom:16px">
            <div class="card__head">
                <h2 class="h2">{{ $area->name }}</h2>

                <div class="row">
                    @if ($area->occupancy !== null)
                        <span class="pill pill--ok">
                            {{ $area->effectiveOccupancy($bookingType)->label() }}
                        </span>
                    @endif
                    <span class="pill pill--muted">
                        {{ $areaResources->count() }} {{ $noun }}
                    </span>
                </div>
            </div>

            @if ($areaResources->isEmpty())
                <p class="muted" style="margin:0 0 14px">
                    Nothing here yet. Add your {{ $noun }} below.
                </p>
            @else
                <div class="tiles">
                    @foreach ($areaResources as $resource)
                        <form method="POST" action="{{ route('dashboard.resources.toggle', $resource) }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit"
                                    class="tile @unless ($resource->isBookable()) tile--closed @endunless"
                                    aria-pressed="{{ $resource->isBookable() ? 'true' : 'false' }}"
                                    title="{{ $resource->isBookable() ? 'Open — tap to close' : 'Closed — tap to open' }}">
                                {{ $resource->label }}
                                <small>
                                    @if ($resource->isBookable())
                                        {{ \App\Support\Money::format($resource->base_price_cents) }}
                                    @else
                                        closed
                                    @endif
                                </small>
                            </button>
                        </form>
                    @endforeach
                </div>

                <hr class="divider">

                <form method="POST"
                      action="{{ route('dashboard.areas.availability', $area) }}"
                      class="row">
                    @csrf
                    @method('PATCH')

                    <button type="submit" name="status" value="open" class="btn btn--ghost">
                        Open all
                    </button>
                    <button type="submit" name="status" value="closed" class="btn btn--ghost">
                        Close all
                    </button>

                    <div class="row" style="gap:8px">
                        <label class="label" for="open_first_{{ $area->id }}"
                               style="margin:0 0 14px">
                            Open only the first
                        </label>
                        <input class="field" type="number" min="0"
                               max="{{ $areaResources->count() }}"
                               id="open_first_{{ $area->id }}" name="open_first"
                               style="width:84px"
                               placeholder="{{ $areaResources->count() }}">
                        <button type="submit" class="btn btn--ghost">Apply</button>
                    </div>
                </form>
            @endif

            <details class="advanced" style="margin-top:14px">
                <summary>Add {{ $noun }} to {{ $area->name }}</summary>

                <form method="POST" action="{{ route('dashboard.resources.store') }}"
                      class="stack" style="margin-top:14px">
                    @csrf
                    <input type="hidden" name="area_id" value="{{ $area->id }}">

                    <div class="grid grid--3">
                        <div>
                            <label class="label" for="prefix_{{ $area->id }}">Label starts with</label>
                            <input class="field" type="text" id="prefix_{{ $area->id }}"
                                   name="prefix" maxlength="8" placeholder="A"
                                   value="{{ old('prefix') }}">
                        </div>

                        <div>
                            <label class="label" for="from_{{ $area->id }}">Numbered from</label>
                            <input class="field" type="number" id="from_{{ $area->id }}"
                                   name="from" min="1" max="999" required
                                   value="{{ old('from', 1) }}">
                        </div>

                        <div>
                            <label class="label" for="to_{{ $area->id }}">to</label>
                            <input class="field" type="number" id="to_{{ $area->id }}"
                                   name="to" min="1" max="999" required
                                   value="{{ old('to', 10) }}">
                        </div>
                    </div>

                    <div class="grid grid--2">
                        @if ($templates->isNotEmpty())
                            <div>
                                <label class="label" for="template_{{ $area->id }}">Made of</label>
                                <select class="field" id="template_{{ $area->id }}" name="template_id">
                                    <option value="">Just one {{ $bookingType->resource_type->label() }}</option>
                                    @foreach ($templates as $template)
                                        <option value="{{ $template->id }}">{{ $template->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif

                        <div>
                            <label class="label" for="price_{{ $area->id }}">Price here</label>
                            <input class="field" type="number" id="price_{{ $area->id }}"
                                   name="price" step="0.50" min="0" max="9999"
                                   placeholder="{{ number_format($bookingType->default_price_cents / 100, 2, '.', '') }}">
                            <p class="muted" style="margin:6px 0 0">
                                Leave empty to charge the same as everywhere else.
                            </p>
                        </div>
                    </div>

                    <div>
                        <button type="submit" class="btn">Add</button>
                    </div>
                </form>
            </details>

            @if ($areaResources->isNotEmpty())
                {{--
                    Removing is for a range that was typed wrong, which is why it
                    is in here and not on the tiles. During service the tile is a
                    close button, and a remove control next to it would be mistapped
                    by somebody holding three plates.
                --}}
                <details class="advanced" style="margin-top:8px">
                    <summary>Remove {{ $noun }}</summary>

                    <p class="muted" style="margin:8px 0 12px">
                        For good. If what you want is to stop taking bookings for one
                        today, tap it above instead — that is reversible, and it leaves
                        the bookings you already have alone.
                    </p>

                    <div class="row" style="flex-wrap:wrap;gap:8px">
                        @foreach ($areaResources as $resource)
                            <form method="POST"
                                  action="{{ route('dashboard.resources.destroy', $resource) }}"
                                  onsubmit="return confirm('Remove this permanently? Closing it is reversible; this is not.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn--ghost"
                                        style="min-height:36px;padding:0 14px">
                                    {{ $resource->label }} ✕
                                </button>
                            </form>
                        @endforeach
                    </div>
                </details>
            @endif

            <details class="advanced" style="margin-top:8px">
                <summary>How long a booking holds a spot here</summary>

                <form method="POST" action="{{ route('dashboard.areas.update', $area) }}"
                      class="stack" style="margin-top:14px">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="name" value="{{ $area->name }}">
                    <input type="hidden" name="description" value="{{ $area->description }}">

                    <div class="grid grid--3">
                        <div>
                            <label class="label" for="occupancy_{{ $area->id }}">Held for</label>
                            <select class="field" id="occupancy_{{ $area->id }}" name="occupancy">
                                <option value="">
                                    Same as {{ $bookingType->name }}
                                    ({{ $bookingType->occupancy->label() }})
                                </option>
                                @foreach ($occupancyOptions as $option)
                                    <option value="{{ $option->value }}"
                                            @selected($area->occupancy === $option)>
                                        {{ $option->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="label" for="max_stay_{{ $area->id }}">Minutes, if fixed</label>
                            <input class="field" type="number" id="max_stay_{{ $area->id }}"
                                   name="max_stay_minutes" min="15" max="1440" step="15"
                                   value="{{ $area->max_stay_minutes }}">
                        </div>

                        <div>
                            <label class="label" for="buffer_{{ $area->id }}">Gap between bookings</label>
                            <input class="field" type="number" id="buffer_{{ $area->id }}"
                                   name="turnover_buffer_minutes" min="0" max="240" step="5"
                                   value="{{ $area->turnover_buffer_minutes }}"
                                   placeholder="{{ $bookingType->turnover_buffer_minutes }}">
                        </div>
                    </div>

                    <div class="row" style="justify-content:space-between">
                        <button type="submit" class="btn">Save</button>

                        @if ($areaResources->isEmpty())
                            <button type="submit" class="btn btn--ghost"
                                    form="remove-area-{{ $area->id }}">
                                Remove {{ $area->name }}
                            </button>
                        @endif
                    </div>
                </form>

                @if ($areaResources->isEmpty())
                    <form method="POST" action="{{ route('dashboard.areas.destroy', $area) }}"
                          id="remove-area-{{ $area->id }}">
                        @csrf
                        @method('DELETE')
                    </form>
                @endif
            </details>
        </section>
    @endforeach

    <section class="card" style="margin-bottom:16px">
        <div class="card__head">
            <h2 class="h2">Prices</h2>
            <span class="muted">
                Usually {{ \App\Support\Money::format($bookingType->default_price_cents) }}
                {{ $bookingType->price_basis->suffix() }}
            </span>
        </div>

        @if ($priceRules->isNotEmpty())
            <ul class="bookings" style="margin-bottom:14px">
                @foreach ($priceRules as $rule)
                    <li class="booking" style="padding-left:0;padding-right:0">
                        <span class="booking__who">
                            <strong>{{ $rule->describe() }}</strong>
                            <small class="muted">
                                {{ $closureNames[$rule->scope->value.':'.$rule->scope_id] ?? 'Everything' }}
                                @if ($rule->days_of_week)
                                    · {{ collect($rule->days_of_week)->map(fn ($day) => $weekdays[(int) $day] ?? '')->implode(', ') }}
                                @endif
                                @if ($rule->date_from)
                                    · {{ $rule->date_from->format('j M') }}@if ($rule->date_to)–{{ $rule->date_to->format('j M') }}@endif
                                @endif
                                @if ($rule->time_from)
                                    · {{ substr((string) $rule->time_from, 0, 5) }}–{{ substr((string) $rule->time_to, 0, 5) }}
                                @endif
                            </small>
                        </span>

                        <span class="booking__meta">
                            <span class="pill pill--muted">priority {{ $rule->priority }}</span>

                            <form method="POST" action="{{ route('dashboard.price-rules.destroy', $rule) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn--ghost"
                                        style="min-height:36px;padding:0 14px">Remove</button>
                            </form>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif

        <details class="advanced">
            <summary>
                @if ($priceRules->isEmpty())
                    Charge a different price at weekends or in season
                @else
                    Add another price rule
                @endif
            </summary>

            <form method="POST" action="{{ route('dashboard.price-rules.store') }}"
                  class="stack" style="margin-top:14px">
                @csrf

                <div class="grid grid--2">
                    <div>
                        <label class="label" for="price_target">What it applies to</label>
                        <select class="field" id="price_target" name="target" required>
                            <option value="booking_type:{{ $bookingType->id }}">All {{ $noun }}</option>
                            @foreach ($areas as $area)
                                <option value="area:{{ $area->id }}">{{ $area->name }}</option>
                            @endforeach
                            @foreach ($all as $resource)
                                <option value="resource:{{ $resource->id }}">{{ $resource->label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="label" for="rule_name">Call it</label>
                        <input class="field" type="text" id="rule_name" name="name" maxlength="60"
                               placeholder="Weekend rate" value="{{ old('name') }}">
                    </div>
                </div>

                <div class="grid grid--3">
                    <div>
                        <label class="label" for="price_type">How it changes</label>
                        <select class="field" id="price_type" name="price_type" required>
                            @foreach ($priceTypes as $type)
                                <option value="{{ $type->value }}"
                                        @selected(old('price_type') === $type->value)>
                                    {{ $type->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="label" for="rule_amount">Set price</label>
                        <input class="field" type="number" id="rule_amount" name="amount"
                               step="0.50" min="0" max="9999" value="{{ old('amount') }}"
                               placeholder="35.00">
                    </div>

                    <div>
                        <label class="label" for="rule_percent">Or change by %</label>
                        <input class="field" type="number" id="rule_percent" name="percent"
                               min="-90" max="500" value="{{ old('percent') }}" placeholder="30">
                    </div>
                </div>

                <div>
                    <span class="label">On these days</span>
                    <div class="row" style="gap:14px">
                        @foreach ($weekdays as $number => $label)
                            <label class="muted" style="display:flex;gap:6px;align-items:center">
                                <input type="checkbox" name="days_of_week[]" value="{{ $number }}">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <p class="muted" style="margin:6px 0 0">Tick none for every day.</p>
                </div>

                <details class="advanced">
                    <summary>Only in a season, or only at certain hours</summary>

                    <div class="grid grid--2" style="margin-top:14px">
                        <div>
                            <label class="label" for="rule_date_from">Season from</label>
                            <input class="field" type="date" id="rule_date_from" name="date_from">
                        </div>
                        <div>
                            <label class="label" for="rule_date_to">to</label>
                            <input class="field" type="date" id="rule_date_to" name="date_to">
                        </div>
                        <div>
                            <label class="label" for="rule_time_from">From</label>
                            <input class="field" type="time" id="rule_time_from" name="time_from">
                        </div>
                        <div>
                            <label class="label" for="rule_time_to">to</label>
                            <input class="field" type="time" id="rule_time_to" name="time_to">
                        </div>
                    </div>

                    <div style="margin-top:14px">
                        <label class="label" for="rule_priority">Priority</label>
                        <input class="field" type="number" id="rule_priority" name="priority"
                               min="0" max="999" value="{{ old('priority', 10) }}" style="max-width:120px">
                        <p class="muted" style="margin:6px 0 0">
                            When two rules both fit, the higher number wins.
                        </p>
                    </div>
                </details>

                <div>
                    <button type="submit" class="btn">Add price rule</button>
                </div>
            </form>
        </details>
    </section>

    <section class="card" style="margin-bottom:16px">
        <div class="card__head">
            <h2 class="h2">Closed dates</h2>
            <span class="muted">Stops new bookings. Anything already booked stays.</span>
        </div>

        @if ($closures->isEmpty())
            <p class="muted" style="margin:0 0 14px">
                Nothing closed. Add a date for a private event, a holiday, or the end
                of the season.
            </p>
        @else
            <ul class="bookings" style="margin-bottom:14px">
                @foreach ($closures as $closure)
                    <li class="booking" style="padding-left:0;padding-right:0">
                        <span class="booking__who">
                            <strong>
                                {{ $closure->date_from->format('D j M') }}
                                @if (! $closure->date_from->isSameDay($closure->date_to))
                                    → {{ $closure->date_to->format('D j M') }}
                                @endif
                                @unless ($closure->isAllDay())
                                    · {{ substr((string) $closure->time_from, 0, 5) }}–{{ substr((string) $closure->time_to, 0, 5) }}
                                @endunless
                            </strong>
                            <small class="muted">
                                {{ $closureNames[$closure->scope->value.':'.$closure->scope_id] ?? 'Everything' }}
                                @if ($closure->reason)
                                    · {{ $closure->reason }}
                                @endif
                            </small>
                        </span>

                        <span class="booking__meta">
                            <form method="POST" action="{{ route('dashboard.closures.destroy', $closure) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn--ghost"
                                        style="min-height:36px;padding:0 14px">Reopen</button>
                            </form>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif

        <details class="advanced">
            <summary>Close a date</summary>

            <form method="POST" action="{{ route('dashboard.closures.store') }}"
                  class="stack" style="margin-top:14px">
                @csrf

                <div>
                    <label class="label" for="closure_scope">What closes</label>
                    <select class="field" id="closure_scope" name="target" required>
                        <option value="booking_type:{{ $bookingType->id }}">All {{ $noun }}</option>
                        @foreach ($areas as $area)
                            <option value="area:{{ $area->id }}">{{ $area->name }}</option>
                        @endforeach
                        @foreach ($all as $resource)
                            <option value="resource:{{ $resource->id }}">{{ $resource->label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="grid grid--2">
                    <div>
                        <label class="label" for="date_from">From</label>
                        <input class="field" type="date" id="date_from" name="date_from" required
                               value="{{ old('date_from', now()->toDateString()) }}">
                    </div>

                    <div>
                        <label class="label" for="date_to">To</label>
                        <input class="field" type="date" id="date_to" name="date_to"
                               value="{{ old('date_to') }}">
                        <p class="muted" style="margin:6px 0 0">Leave empty for one day.</p>
                    </div>
                </div>

                <details class="advanced">
                    <summary>Only part of the day</summary>

                    <div class="grid grid--2" style="margin-top:14px">
                        <div>
                            <label class="label" for="time_from">From</label>
                            <input class="field" type="time" id="time_from" name="time_from">
                        </div>
                        <div>
                            <label class="label" for="time_to">To</label>
                            <input class="field" type="time" id="time_to" name="time_to">
                        </div>
                    </div>
                </details>

                <div>
                    <label class="label" for="reason">Why</label>
                    <input class="field" type="text" id="reason" name="reason" maxlength="160"
                           placeholder="Private event" value="{{ old('reason') }}">
                </div>

                <div>
                    <button type="submit" class="btn">Close these dates</button>
                </div>
            </form>
        </details>
    </section>

    <section class="card">
        <details class="advanced">
            <summary>Add another area</summary>

            <form method="POST"
                  action="{{ route('dashboard.booking-types.areas.store', $bookingType) }}"
                  class="stack" style="margin-top:14px">
                @csrf

                <div>
                    <label class="label" for="area_name">Name</label>
                    <input class="field" type="text" id="area_name" name="name" required
                           maxlength="80" placeholder="2nd row" value="{{ old('name') }}">
                </div>

                <div>
                    <label class="label" for="area_description">Shown to guests</label>
                    <input class="field" type="text" id="area_description" name="description"
                           maxlength="500" placeholder="Behind the front row, more shade">
                </div>

                <div>
                    <button type="submit" class="btn">Add area</button>
                </div>
            </form>
        </details>
    </section>
@endsection
