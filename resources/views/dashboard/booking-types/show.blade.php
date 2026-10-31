@extends('layouts.dashboard')

@section('title', $bookingType->name)

@section('main')
    @php
        $noun = $bookingType->resource_type->plural();
        $all = $resources->collapse();
        $total = $all->count();
        $open = $all->filter(fn (\App\Models\Resource $resource): bool => $resource->isBookable())->count();
    @endphp

    @include('partials.crumb', ['trail' => [
        ['Setup', route('dashboard.setup')],
        ['Tables and sunbeds', route('dashboard.booking-types.index')],
        [$bookingType->name, null],
    ]])

    <div class="page-head">
        <div>
            <h1>{{ $bookingType->name }}</h1>
            <p class="muted">
                {{ $open }} of {{ $total }} {{ $noun }} open for booking
            </p>
        </div>

        <div class="row">
            <span class="pill">{{ $bookingType->booking_mode->label() }}</span>

            {{-- A price beside "No payment" is the same noise the tiles used
                 to carry: "€0 each · No payment" reads as a fault rather than
                 as a choice. When nothing is taken up front, say that once. --}}
            @if ($bookingType->payment_requirement === \App\Enums\PaymentRequirement::None)
                <span class="pill pill--muted">Pay at the venue</span>
            @else
                <span class="pill">
                    {{ \App\Support\Money::format($bookingType->default_price_cents) }}
                    {{ $bookingType->price_basis->suffix() }}
                </span>
                <span class="pill">{{ $bookingType->payment_requirement->label() }}</span>
            @endif

            @include('partials.add-button', [
                'key' => 'area',
                'label' => 'Add an area',
                'primary' => true,
            ])
        </div>
    </div>

    <x-sheet key="area" title="Add an area">
        <form method="POST"
              action="{{ route('dashboard.booking-types.areas.store', $bookingType) }}"
              class="stack">
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
    </x-sheet>

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
            @php
                $areaSeats = (int) $areaResources->sum('capacity_max');
                $areaShut = $areaResources->reject(fn (\App\Models\Resource $r): bool => $r->isBookable())->count();
            @endphp

            <div class="card__head">
                <div>
                    <h2 class="h2">{{ $area->name }}</h2>

                    {{-- One sentence where two pills were. The count and the
                         seats are the same fact and belong in the same line. --}}
                    <p class="muted" style="margin:2px 0 0;font-size:var(--step--2)">
                        {{ $areaResources->count() }} {{ $noun }}@if ($areaSeats > 0), seats {{ $areaSeats }}@endif
                        @if ($area->occupancy !== null)
                            · held {{ $area->effectiveOccupancy($bookingType)->label() }}
                        @endif
                    </p>
                </div>

                @include('partials.add-button', [
                    'key' => 'spots-'.$area->id,
                    'label' => 'Add '.$noun,
                ])
            </div>

            @if ($areaResources->isEmpty())
                <p class="muted" style="margin:0 0 14px">
                    Nothing here yet. Add your {{ $noun }} below.
                </p>
            @else
                {{-- The tile carries how many people fit, not the price. The
                     price is the same for every spot in the area unless a rule
                     says otherwise, so printing it twenty times taught nothing
                     — and printed €0 twenty times for a venue that takes no
                     deposit, which reads as a fault. How many a table seats is
                     the one thing that actually differs between them. --}}
                <div class="tiles">
                    @foreach ($areaResources as $resource)
                        <form method="POST" action="{{ route('dashboard.resources.toggle', $resource) }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit"
                                    class="tile @unless ($resource->isBookable()) tile--closed @endunless"
                                    aria-pressed="{{ $resource->isBookable() ? 'true' : 'false' }}"
                                    aria-label="{{ $resource->label }}, seats {{ $resource->capacity_max }}, {{ $resource->isBookable() ? 'taking bookings — press to close it' : 'closed — press to open it' }}">
                                {{ $resource->label }}
                                <small>{{ $resource->capacity_max }}</small>
                            </button>
                        </form>
                    @endforeach

                    {{-- Adding, where the things being added are. --}}
                    <a class="tile tile--add"
                       href="{{ request()->fullUrlWithQuery(['add' => 'spots-'.$area->id]) }}"
                       aria-label="Add {{ $noun }} to {{ $area->name }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
                             stroke-linecap="round" aria-hidden="true">
                            <path d="M12 5.5v13M5.5 12h13"></path>
                        </svg>
                    </a>
                </div>
            @endif

            <x-sheet :key="'spots-'.$area->id" :title="'Add '.$noun.' to '.$area->name">

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
            </x-sheet>

            {{--
                The quiet line. Everything the old card showed as four buttons
                and two folds is still here, one tap away and named:

                  Rename              — was unreachable: the only form that
                                        wrote a name passed it as a hidden
                                        field to preserve it
                  Seats & timing      — was "How long a booking holds a spot
                                        here"
                  Open or close several — was Open all / Close all / Open only
                                        the first N, sitting between the tiles
                                        and the next area with nothing to say
                                        which they acted on
                  Remove {noun}       — unchanged, still behind a confirm
            --}}
            <div class="arearow">
                <p class="muted" style="margin:0;font-size:var(--step--2)">
                    @if ($areaResources->isEmpty())
                        Nothing here yet.
                    @elseif ($areaShut === 0)
                        All taking bookings. Tap one to close it.
                    @else
                        {{ $areaShut }} closed — tap to open again
                    @endif
                </p>

                <div class="arearow__acts">
                    @include('partials.add-button', [
                        'key' => 'rename-'.$area->id,
                        'label' => 'Rename',
                        'quiet' => true,
                    ])
                    @include('partials.add-button', [
                        'key' => 'timing-'.$area->id,
                        'label' => 'Seats & timing',
                        'quiet' => true,
                    ])

                    @if ($areaResources->isNotEmpty())
                        @include('partials.add-button', [
                            'key' => 'bulk-'.$area->id,
                            'label' => 'Open or close several',
                            'quiet' => true,
                        ])
                        @include('partials.add-button', [
                            'key' => 'remove-'.$area->id,
                            'label' => 'Remove '.$noun,
                            'quiet' => true,
                            'danger' => true,
                        ])
                    @endif
                </div>
            </div>

            <x-sheet :key="'rename-'.$area->id" title="Rename this area">
                <form method="POST" action="{{ route('dashboard.areas.update', $area) }}" class="stack">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="occupancy" value="{{ $area->occupancy?->value }}">
                    <input type="hidden" name="max_stay_minutes" value="{{ $area->max_stay_minutes }}">
                    <input type="hidden" name="turnover_buffer_minutes" value="{{ $area->turnover_buffer_minutes }}">

                    <div>
                        <label class="label" for="name_{{ $area->id }}">Name</label>
                        <input class="field" type="text" id="name_{{ $area->id }}" name="name"
                               required maxlength="80" value="{{ $area->name }}">
                        <p class="muted" style="margin:6px 0 0;font-size:var(--step--2)">
                            Guests see this when they pick a spot.
                        </p>
                    </div>

                    <div>
                        <label class="label" for="desc_{{ $area->id }}">Shown to guests</label>
                        <input class="field" type="text" id="desc_{{ $area->id }}" name="description"
                               maxlength="500" value="{{ $area->description }}"
                               placeholder="Behind the front row, more shade">
                    </div>

                    <div><button type="submit" class="btn">Save</button></div>
                </form>
            </x-sheet>

            <x-sheet :key="'timing-'.$area->id" title="How long a booking holds a spot here">
                <form method="POST" action="{{ route('dashboard.areas.update', $area) }}" class="stack">
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
                                   value="{{ $area->turnover_buffer_minutes }}">
                        </div>
                    </div>

                    <p class="muted" style="margin:0;font-size:var(--step--2)">
                        The front row sells a whole day while the back row turns over.
                        Leave these alone and the area follows {{ $bookingType->name }}.
                    </p>

                    <div><button type="submit" class="btn">Save</button></div>
                </form>
            </x-sheet>

            @if ($areaResources->isNotEmpty())
                <x-sheet :key="'bulk-'.$area->id" :title="'Open or close several in '.$area->name">
                    <form method="POST" action="{{ route('dashboard.areas.availability', $area) }}"
                          class="stack">
                        @csrf
                        @method('PATCH')

                        <div class="row">
                            <button type="submit" name="status" value="open" class="btn btn--ghost">
                                Open all {{ $areaResources->count() }}
                            </button>
                            <button type="submit" name="status" value="closed" class="btn btn--ghost">
                                Close all {{ $areaResources->count() }}
                            </button>
                        </div>

                        <div>
                            <label class="label" for="open_first_{{ $area->id }}">
                                Or open only the first
                            </label>
                            <div class="row" style="gap:8px">
                                <input class="field" type="number" min="0"
                                       max="{{ $areaResources->count() }}"
                                       id="open_first_{{ $area->id }}" name="open_first"
                                       style="max-width:110px"
                                       placeholder="{{ $areaResources->count() }}">
                                <button type="submit" class="btn">Apply</button>
                            </div>
                            <p class="muted" style="margin:6px 0 0;font-size:var(--step--2)">
                                For a quiet evening: take bookings for the first few and
                                keep the rest for whoever walks in.
                            </p>
                        </div>
                    </form>
                </x-sheet>

                {{--
                    Removing is for a range that was typed wrong, which is why
                    it is in here and not on the tiles. During service the tile
                    is a close button, and a remove control next to it would be
                    mistapped by somebody holding three plates.
                --}}
                <x-sheet :key="'remove-'.$area->id" :title="'Remove '.$noun.' from '.$area->name">
                    <p class="muted" style="margin:0 0 12px">
                        For good. If what you want is to stop taking bookings for one
                        today, tap it on the card instead — that is reversible, and it
                        leaves the bookings you already have alone.
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
                                    {{ $resource->label }} &times;
                                </button>
                            </form>
                        @endforeach
                    </div>
                </x-sheet>
            @endif

        </section>
    @endforeach

    <section class="card" style="margin-bottom:16px">
        <div class="card__head">
            <h2 class="h2">Prices</h2>

            <span class="row" style="gap:10px">
                <span class="muted">
                    @if ($bookingType->payment_requirement === \App\Enums\PaymentRequirement::None)
                        Nothing is taken up front — guests settle with you
                    @else
                        Usually {{ \App\Support\Money::format($bookingType->default_price_cents) }}
                        {{ $bookingType->price_basis->suffix() }}
                    @endif
                </span>

                @include('partials.add-button', ['key' => 'price', 'label' => 'Add a price rule'])
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

        <x-sheet key="price" title="A different price, on certain days">

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
        </x-sheet>
    </section>

    <section class="card" style="margin-bottom:16px">
        <div class="card__head">
            <h2 class="h2">Closed dates</h2>

            <span class="row" style="gap:10px">
                <span class="muted">Stops new bookings. Anything already booked stays.</span>
                @include('partials.add-button', ['key' => 'closure', 'label' => 'Close a date'])
            </span>
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

        <x-sheet key="closure" title="Close a date">

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
        </x-sheet>
    </section>

@endsection
