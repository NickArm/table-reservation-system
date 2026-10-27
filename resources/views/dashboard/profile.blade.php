{{--
    The venue's public profile (SPEC §33.1).

    Nothing on this screen is needed to take a booking, and it says so at the
    top. What it buys is being found: the booking page's structured data,
    canonical URL and Open Graph tags are all built from these fields, and
    until this screen existed the only way to fill them in was the seeder.

    So the nudge is a percentage and a short list of what is missing, not a
    wall of red — the owner is being offered something, not told off.
--}}
@extends('layouts.dashboard')

@section('title', 'Your venue — '.$tenant->name)

@section('main')
    <div class="page-head">
        <h1>Your venue</h1>
        <p>What a guest — and Google — sees about the place.</p>
    </div>

    <div class="stack" style="max-width:760px">

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">How complete it is</h2>
                <span class="pill {{ $completeness['percent'] >= 60 ? 'pill--ok' : 'pill--warn' }}">
                    {{ $completeness['percent'] }}%
                </span>
            </div>

            @if ($completeness['missing'] === [])
                <p class="muted">
                    Everything is filled in. Nothing more to do here.
                </p>
            @else
                <p class="muted">
                    Still missing: <strong>{{ implode(', ', $completeness['missing']) }}</strong>.
                    None of it stops you taking bookings — it is what decides whether
                    somebody searching for a table nearby finds you at all.
                </p>
            @endif

            @unless ($venue->shouldBeIndexed())
                <p class="notice">
                    Your booking page is currently <strong>not</strong> offered to search
                    engines. A page with almost nothing on it is held back on purpose:
                    hundreds of near-identical thin pages are penalised across a whole
                    domain, which would cost every other venue here too. Fill the profile
                    past 60% and it goes in by itself.
                </p>
            @endunless
        </section>

        <form method="POST" action="{{ route('dashboard.profile.update') }}" class="stack">
            @csrf
            @method('PATCH')

            <section class="card stack">
                <div class="card__head"><h2 class="h2">The place</h2></div>

                <div class="field">
                    <label for="name">Name</label>
                    <input id="name" type="text" name="name" required maxlength="120"
                           value="{{ old('name', $venue->name) }}">
                </div>

                <div class="field">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" rows="5"
                              maxlength="2000">{{ old('description', $venue->description) }}</textarea>
                    <small class="muted">
                        A few sentences in the owner's own words. It appears on the
                        booking page and is the first thing a stranger reads — what the
                        view is like, what the kitchen does, whether there is shade.
                    </small>
                </div>

                <div class="row">
                    <div class="field">
                        <label for="venue_category">What kind of place</label>
                        <select id="venue_category" name="venue_category">
                            <option value="">Not saying</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->value }}"
                                    @selected(old('venue_category', $venue->venue_category?->value) === $category->value)>
                                    {{ $category->label() }}
                                </option>
                            @endforeach
                        </select>
                        <small class="muted">
                            Decides how search engines are told to read the page.
                        </small>
                    </div>

                    <div class="field">
                        <label for="cuisine">Cuisine</label>
                        <input id="cuisine" type="text" name="cuisine" maxlength="80"
                               placeholder="Mediterranean, Greek, seafood"
                               value="{{ old('cuisine', $venue->cuisine) }}">
                        <small class="muted">
                            Used only for restaurants and tavernas.
                        </small>
                    </div>

                    <div class="field">
                        <label for="price_range">Price</label>
                        <select id="price_range" name="price_range">
                            <option value="">Not saying</option>
                            @foreach (['€', '€€', '€€€', '€€€€'] as $range)
                                <option value="{{ $range }}"
                                    @selected(old('price_range', $venue->price_range) === $range)>
                                    {{ $range }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </section>

            <section class="card stack">
                <div class="card__head"><h2 class="h2">Where it is</h2></div>

                <div class="field">
                    <label for="address">Street</label>
                    <input id="address" type="text" name="address" maxlength="200"
                           value="{{ old('address', $venue->address) }}">
                </div>

                <div class="row">
                    <div class="field">
                        <label for="city">Town</label>
                        <input id="city" type="text" name="city" maxlength="100"
                               value="{{ old('city', $venue->city) }}">
                    </div>
                    <div class="field">
                        <label for="postal_code">Postcode</label>
                        <input id="postal_code" type="text" name="postal_code" maxlength="20"
                               value="{{ old('postal_code', $venue->postal_code) }}">
                    </div>
                    <div class="field">
                        <label for="region">Region</label>
                        <input id="region" type="text" name="region" maxlength="100"
                               placeholder="Corfu"
                               value="{{ old('region', $venue->region) }}">
                    </div>
                    <div class="field">
                        <label for="country">Country</label>
                        <input id="country" type="text" name="country" maxlength="2"
                               placeholder="GR"
                               value="{{ old('country', $venue->country) }}">
                    </div>
                </div>

                <div class="row">
                    <div class="field">
                        <label for="latitude">Latitude</label>
                        <input id="latitude" type="text" name="latitude"
                               inputmode="decimal" placeholder="39.7345"
                               value="{{ old('latitude', $venue->latitude) }}">
                    </div>
                    <div class="field">
                        <label for="longitude">Longitude</label>
                        <input id="longitude" type="text" name="longitude"
                               inputmode="decimal" placeholder="19.9298"
                               value="{{ old('longitude', $venue->longitude) }}">
                    </div>
                </div>

                <p class="muted">
                    {{--
                        Said plainly because the owner will otherwise type one
                        number and wonder why the form objects.
                    --}}
                    Both numbers or neither — half a coordinate puts the pin in the sea.
                    Open the place in Google Maps, right-click it and the two numbers are
                    the first thing on the menu.
                </p>
            </section>

            <section class="card stack">
                <div class="card__head"><h2 class="h2">How to reach you</h2></div>

                <div class="row">
                    <div class="field">
                        <label for="phone">Telephone</label>
                        <input id="phone" type="tel" name="phone" maxlength="32"
                               placeholder="+30 26630 00000"
                               value="{{ old('phone', $venue->phone) }}">
                        <small class="muted">
                            Printed on every booking confirmation. It is how a guest
                            changes or cancels, so it has to be a number somebody answers.
                        </small>
                    </div>

                    <div class="field">
                        <label for="public_email">Email a guest may write to</label>
                        <input id="public_email" type="email" name="public_email" maxlength="160"
                               value="{{ old('public_email', $venue->public_email) }}">
                        <small class="muted">
                            Not where we send you alerts — that is on the Alerts screen.
                        </small>
                    </div>

                    <div class="field">
                        <label for="website_url">Website</label>
                        <input id="website_url" type="url" name="website_url" maxlength="200"
                               placeholder="https://..."
                               value="{{ old('website_url', $venue->website_url) }}">
                    </div>
                </div>
            </section>

            <section class="card stack">
                <div class="card__head"><h2 class="h2">Being found</h2></div>

                <label class="choice">
                    <input type="checkbox" name="is_indexable" value="1"
                        @checked(old('is_indexable', $venue->is_indexable))>
                    <span>Let search engines list my booking page</span>
                </label>

                <div class="field">
                    <label for="meta_title">Title in search results</label>
                    <input id="meta_title" type="text" name="meta_title" maxlength="70"
                           placeholder="{{ $venue->name }} — book a table"
                           value="{{ old('meta_title', $venue->meta_title) }}">
                    <small class="muted">Up to about 70 characters; longer gets cut off.</small>
                </div>

                <div class="field">
                    <label for="meta_description">Line underneath it</label>
                    <textarea id="meta_description" name="meta_description" rows="2"
                              maxlength="160">{{ old('meta_description', $venue->meta_description) }}</textarea>
                    <small class="muted">Up to about 160 characters.</small>
                </div>
            </section>

            <p>
                <button type="submit" class="btn">Save</button>
                <a href="{{ route('public.booking', $tenant) }}" class="btn btn--ghost">
                    See the booking page
                </a>
            </p>
        </form>
    </div>
@endsection
