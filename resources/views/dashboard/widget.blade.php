{{--
    The widget builder (SPEC §9.4).

    One screen, three parts: choose, look, copy. The owner should be able to
    finish here without learning what any of it means — the only thing they
    have to do afterwards is forward one line.
--}}
@extends('layouts.dashboard')

@section('title', 'Website widget — '.$tenant->name)

@section('main')
    <div class="page-head">
        <h1>Website widget</h1>
        <p>
            A search bar for your own site. Visitors pick a date and land on your
            booking page with their choice already filled in.
        </p>
    </div>

    <div class="stack" style="max-width:860px">

        @if ($venue->widgetIsLive())
            <p class="notice">
                <strong>Working on {{ $venue->widget_last_origin }}</strong> —
                last seen {{ $venue->widget_last_seen_at?->diffForHumans() }}.
            </p>
        @elseif ($venue->widget_last_seen_at !== null)
            <p class="notice notice--warn">
                Last seen on {{ $venue->widget_last_origin }}
                {{ $venue->widget_last_seen_at->diffForHumans() }}, and nothing since.
                If the snippet is still on the page, it may have been removed by a
                theme update.
            </p>
        @else
            <p class="notice">
                Not seen on any site yet. Once the snippet is live, this line says
                where and when — so you can tell whether it reached the page.
            </p>
        @endif

        <section class="card stack" data-builder>
            <div class="card__head">
                <h2 class="h2">Choose</h2>
            </div>

            <div class="builder">
                <div>
                    <label class="label" for="w-mode">Shape</label>
                    <select class="field" id="w-mode" data-opt="mode">
                        <option value="search">Search bar</option>
                        <option value="button">Button only</option>
                        <option value="inline">The whole booking flow</option>
                    </select>
                </div>

                @if ($bookingTypes->count() > 1)
                    <div>
                        <label class="label" for="w-type">Booking type</label>
                        <select class="field" id="w-type" data-opt="booking-type">
                            <option value="">Let the visitor choose</option>
                            @foreach ($bookingTypes as $type)
                                <option value="{{ $type->slug }}">{{ $type->name }}</option>
                            @endforeach
                        </select>
                        <small class="muted">
                            Pin one to put a different widget on each page.
                        </small>
                    </div>
                @endif

                <div>
                    <label class="label" for="w-layout">Layout</label>
                    <select class="field" id="w-layout" data-opt="layout">
                        <option value="horizontal">Across (one row)</option>
                        <option value="vertical">Down (a column)</option>
                    </select>
                    <small class="muted">Narrow spaces and sidebars want a column.</small>
                </div>

                <div>
                    <label class="label" for="w-theme">Colours</label>
                    <select class="field" id="w-theme" data-opt="theme">
                        <option value="auto">Follow the visitor's device</option>
                        <option value="light">Always light</option>
                        <option value="dark">Always dark</option>
                    </select>
                </div>

                <div>
                    <label class="label" for="w-accent">Button colour</label>
                    <input class="field" id="w-accent" type="color"
                           value="{{ $defaults['accent'] }}" data-opt="accent">
                </div>

                <div>
                    <label class="label" for="w-people">Starts at</label>
                    <select class="field" id="w-people" data-opt="people">
                        @for ($n = 1; $n <= 8; $n++)
                            <option value="{{ $n }}" @selected($n === $defaults['people'])>
                                {{ $n === 1 ? '1 person' : $n.' people' }}
                            </option>
                        @endfor
                    </select>
                </div>

                <div>
                    <label class="label" for="w-label">Button text</label>
                    <input class="field" id="w-label" type="text"
                           value="{{ $defaults['label'] }}" maxlength="24" data-opt="label">
                </div>

                <div>
                    <label class="label" for="w-onsearch">When they press it</label>
                    <select class="field" id="w-onsearch" data-opt="on-search">
                        <option value="redirect">Go to the booking page</option>
                        <option value="redirect_blank">Open it in a new tab</option>
                        <option value="modal">Open it over their site</option>
                        <option value="inline">Replace the bar with the flow</option>
                    </select>
                </div>
            </div>
        </section>

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">Look</h2>
                <span class="pill pill--muted">Live — real availability</span>
            </div>

            {{-- The real widget, from the real file. Rebuilt whenever a
                 setting changes, because a preview that is drawn rather than
                 run is a preview that can be wrong. --}}
            <div class="preview" data-preview></div>

            <p class="muted">
                This is the widget itself, not a picture of it. Pressing Search here
                goes to your booking page.
            </p>
        </section>

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">Copy</h2>
            </div>

            <pre class="snippet"><code data-snippet data-copy></code></pre>

            <dl class="endpoints">
                <dt>WordPress</dt>
                <dd>
                    Edit the page, add a <strong>Custom HTML</strong> block, paste it in.
                    Not the visual editor — a Custom HTML block, or it will be shown as
                    text.
                </dd>

                <dt>Elementor</dt>
                <dd>Drag in an <strong>HTML</strong> widget and paste it there.</dd>

                <dt>Wix</dt>
                <dd>
                    Add → Embed Code → <strong>Embed HTML</strong>, choose <em>Code</em>,
                    paste it. Give the box a little more height than the bar needs.
                </dd>

                <dt>Squarespace</dt>
                <dd>Add a <strong>Code</strong> block and paste it in.</dd>

                <dt>Plain HTML</dt>
                <dd>Anywhere in the page body. It appears exactly where you put it.</dd>
            </dl>
        </section>

        <section class="card stack">
            <div class="card__head">
                <h2 class="h2">If a script tag is not allowed</h2>
            </div>

            <p class="muted">
                Some locked-down site builders refuse scripts. This is the same bar in a
                plain frame — no script, slightly less clever, works everywhere.
            </p>

            <pre class="snippet"><code data-copy>&lt;iframe src="{{ route('embed.search', $tenant) }}"
        width="100%" height="150" frameborder="0"
        style="border:0;max-width:760px"
        title="Reservations"&gt;&lt;/iframe&gt;</code></pre>
        </section>
    </div>

    {{--
        Page-specific, so it lives here rather than in app.js: this is the one
        screen in the dashboard that has to build a snippet and re-run a web
        component as the form changes.
    --}}
    <script>
        (function () {
            var builder = document.querySelector('[data-builder]');
            var preview = document.querySelector('[data-preview]');
            var snippet = document.querySelector('[data-snippet]');

            if (!builder || !preview || !snippet) {
                return;
            }

            var src = {{ Illuminate\Support\Js::from(asset('embed.js')) }};
            var venue = {{ Illuminate\Support\Js::from($tenant->slug) }};

            function options() {
                var out = { venue: venue };

                builder.querySelectorAll('[data-opt]').forEach(function (field) {
                    var value = field.value;

                    if (value !== '' && value !== null) {
                        out[field.dataset.opt] = value;
                    }
                });

                return out;
            }

            function build() {
                var opts = options();

                // The snippet, with one attribute per line so a developer can
                // read it and change one thing without re-copying.
                var lines = ['<script'];

                lines.push('  src="' + src + '"');

                Object.keys(opts).forEach(function (key) {
                    lines.push('  data-' + key + '="' + String(opts[key]).replace(/"/g, '&quot;') + '"');
                });

                lines.push('  async defer><\/script>');

                snippet.textContent = lines.join('\n');

                // Re-run the widget. The custom element is already defined, so
                // a fresh script tag only re-reads its own attributes — which
                // is why the preview is cleared first.
                preview.innerHTML = '';

                var tag = document.createElement('script');

                tag.src = src;

                Object.keys(opts).forEach(function (key) {
                    tag.setAttribute('data-' + key, opts[key]);
                });

                tag.setAttribute('data-target', '[data-preview]');

                preview.append(tag);
            }

            builder.addEventListener('change', build);
            builder.addEventListener('input', function (event) {
                if (event.target.type === 'text' || event.target.type === 'color') {
                    build();
                }
            });

            build();
        }());
    </script>
@endsection
