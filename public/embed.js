/*
 * The embeddable search bar (SPEC §9).
 *
 * This is how most bookings are meant to arrive: the venue's developer pastes
 * one script tag into their site and a working search bar appears. So the
 * constraints here are somebody else's site, not ours.
 *
 * Served as a plain static file rather than through Vite, because the address
 * has to stay /embed.js for ever. A hashed bundle name would break every
 * customer's page on our next deploy.
 *
 * Shadow DOM rather than an iframe (§9.3). The isolation is the same, but a
 * date picker can overflow the bar instead of being clipped by the frame, the
 * height never needs syncing, and there is no layout shift while it loads.
 *
 * Zero dependencies, no cookies, no third-party anything. The venue's own
 * cookie banner must not acquire a new line because of us, so the only thing
 * remembered is the party size and date, in sessionStorage, for one visit.
 */
(function () {
    'use strict';

    var TAG = 'tablereserved-search';

    /*
     * Every setting, with its default.
     *
     * Settings live on the element, never in this closure. A second script tag
     * on the same page runs this file again but finds the custom element
     * already defined, so anything kept here would belong to whichever tag ran
     * first: two widgets with different booking types would quietly become the
     * same widget, and the builder's live preview could never change.
     */
    var SETTINGS = {
        venue: '',
        'booking-type': '',
        mode: 'search',
        theme: 'auto',
        accent: '',
        layout: 'horizontal',
        'on-search': 'redirect',
        people: '2',
        label: 'Search',
    };

    /* --- Styles ----------------------------------------------------------- */

    var CSS = [
        ':host{display:block;container-type:inline-size;',
        '--tr-accent:#0e6e6e;--tr-ink:#1c1917;--tr-muted:#78716c;--tr-bg:#fff;',
        '--tr-surface:#fafaf9;--tr-line:#d6d3d1;--tr-radius:8px;',
        'font-family:inherit;font-size:16px;line-height:1.4;color-scheme:light}',

        ':host([theme="dark"]){--tr-ink:#f5f5f4;--tr-muted:#a8a29e;--tr-bg:#1c1b19;',
        '--tr-surface:#262523;--tr-line:#4a4742;color-scheme:dark}',

        '@media (prefers-color-scheme:dark){:host([theme="auto"]){',
        '--tr-ink:#f5f5f4;--tr-muted:#a8a29e;--tr-bg:#1c1b19;--tr-surface:#262523;',
        '--tr-line:#4a4742;color-scheme:dark}}',

        '*{box-sizing:border-box}',

        'form{display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;',
        'padding:12px;background:var(--tr-bg);border:1px solid var(--tr-line);',
        'border-radius:calc(var(--tr-radius) + 4px)}',

        ':host([layout="vertical"]) form{flex-direction:column;align-items:stretch}',

        'label{display:block;margin-bottom:4px;color:var(--tr-muted);',
        'font-size:12px;font-weight:600;letter-spacing:.02em}',

        '.field{flex:1 1 130px;min-width:0}',
        ':host([layout="vertical"]) .field{flex:1 1 auto}',

        'select,input{width:100%;padding:10px 12px;border:1px solid var(--tr-line);',
        'border-radius:var(--tr-radius);background:var(--tr-surface);color:var(--tr-ink);',
        'font:inherit;font-size:15px;min-height:44px}',

        'select:focus-visible,input:focus-visible,button:focus-visible,a:focus-visible{',
        'outline:2px solid var(--tr-accent);outline-offset:2px}',

        'button{flex:0 0 auto;padding:10px 20px;border:0;border-radius:var(--tr-radius);',
        'background:var(--tr-accent);color:#fff;font:inherit;font-size:15px;font-weight:600;',
        'cursor:pointer;min-height:44px}',
        ':host([layout="vertical"]) button{width:100%}',
        'button[disabled]{opacity:.6;cursor:default}',

        '.note{flex:1 1 100%;margin:0;color:var(--tr-muted);font-size:13px}',

        /* A phone is the common case, not the exception: most of these sit on
           a beach bar's homepage, opened on the sand. */
        '@container (max-width: 520px){form{flex-direction:column;align-items:stretch}',
        '.field{flex:1 1 auto}button{width:100%}}',

        'a.cta{display:inline-block;padding:12px 22px;border-radius:var(--tr-radius);',
        'background:var(--tr-accent);color:#fff;font-weight:600;text-decoration:none}',

        'dialog{width:min(560px,94vw);max-height:92vh;padding:0;border:0;',
        'border-radius:calc(var(--tr-radius) + 6px);overflow:hidden}',
        'dialog::backdrop{background:rgba(0,0,0,.55)}',
        'dialog iframe{display:block;width:100%;height:min(720px,86vh);border:0}',
        'dialog .close{position:absolute;top:8px;right:10px;padding:6px 10px;',
        'background:transparent;color:#1c1917;font-size:24px;line-height:1}',

        'iframe.inline{display:block;width:100%;min-height:620px;border:0;',
        'border-radius:calc(var(--tr-radius) + 4px)}',
    ].join('');

    /* --- The element ------------------------------------------------------ */

    function define() {
        if (window.customElements.get(TAG)) {
            return;
        }

        window.customElements.define(TAG, class extends HTMLElement {
            /* Read back from our own attributes, so every instance is its own. */
            setting(name) {
                var value = this.getAttribute(name);

                return value === null || value === '' ? SETTINGS[name] : value;
            }

            /*
             * Where we came from. Put on the element by the script tag, so the
             * API, the booking page and the iframes are all addressed relative
             * to wherever this file was served — a staging deploy and a
             * self-hosted copy both work with no configuration.
             */
            get home() {
                return this.getAttribute('origin') || window.location.origin;
            }

            get venue() {
                return this.setting('venue');
            }

            connectedCallback() {
                if (this.shadowRoot) {
                    return;
                }

                var root = this.attachShadow({ mode: 'open' });
                var style = document.createElement('style');

                style.textContent = CSS;
                root.append(style);

                /*
                 * Written back even when the script tag left them out, because
                 * the stylesheet selects on them: :host([theme="auto"]) cannot
                 * match an element with no theme attribute, so a customer who
                 * never set data-theme would silently lose the dark mode that
                 * follows the visitor's device.
                 */
                this.setAttribute('theme', this.setting('theme'));
                this.setAttribute('layout', this.setting('layout'));

                var accent = this.setting('accent');

                if (accent) {
                    this.style.setProperty('--tr-accent', accent);
                }

                var mode = this.setting('mode');

                if (mode === 'button') {
                    this.renderButton(root);
                } else if (mode === 'inline') {
                    this.renderInline(root);
                } else {
                    this.renderSearch(root);
                }
            }

            /* --- Remembering, for one visit only -------------------------- */

            remember(values) {
                try {
                    window.sessionStorage.setItem('tr:' + this.venue, JSON.stringify(values));
                } catch (e) {
                    // Private mode, blocked storage, a quota. None of it matters.
                }
            }

            remembered() {
                try {
                    return JSON.parse(window.sessionStorage.getItem('tr:' + this.venue)) || {};
                } catch (e) {
                    return {};
                }
            }

            /* --- Dates ---------------------------------------------------- */

            /*
             * The visitor's own today, not ours and not the venue's. Somebody
             * in Berlin opening the page at half past midnight means tonight,
             * and the booking page says so if the venue disagrees.
             */
            today() {
                var now = new Date();

                return [
                    now.getFullYear(),
                    String(now.getMonth() + 1).padStart(2, '0'),
                    String(now.getDate()).padStart(2, '0'),
                ].join('-');
            }

            /* --- Talking to us -------------------------------------------- */

            api(path, params) {
                var url = new URL(
                    this.home + '/api/internal/' + encodeURIComponent(this.venue) + path
                );

                Object.keys(params || {}).forEach(function (key) {
                    var value = params[key];

                    if (value !== '' && value !== null && value !== undefined) {
                        url.searchParams.set(key, value);
                    }
                });

                return fetch(url.toString(), {
                    headers: { Accept: 'application/json' },

                    // Nothing here is per-visitor, so no credentials and
                    // nothing for a cache to get wrong.
                    credentials: 'omit',
                }).then(function (response) {
                    if (response.ok) {
                        return response.json();
                    }

                    /*
                     * A refusal carries a reason, and one of them matters: a
                     * venue whose subscription has run out is told to show a
                     * sentence, not an empty box or a console error
                     * (SPEC §4.4). Everything else is a network problem,
                     * where the right move is to let the booking page ask.
                     */
                    return response.json().catch(function () {
                        return null;
                    }).then(function (body) {
                        var failure = new Error('HTTP ' + response.status);

                        failure.code = body && body.error ? body.error.code : null;
                        failure.notice = body && body.error ? body.error.message : null;

                        throw failure;
                    });
                });
            }

            /* --- Where Search goes ---------------------------------------- */

            bookingUrl(values) {
                var url = new URL(this.home + '/reservation/' + encodeURIComponent(this.venue));

                if (values.type) {
                    url.searchParams.set('type', values.type);
                }

                url.searchParams.set('people', values.people);
                url.searchParams.set('date', values.date);

                if (values.time) {
                    url.searchParams.set('time', values.time);
                }

                // Attribution (§9.5). The number that tells an owner the
                // widget is worth what they pay for it.
                url.searchParams.set('channel', 'widget');

                if (window.location.hostname) {
                    url.searchParams.set('ref', window.location.hostname);
                }

                return url.toString();
            }

            /* --- The three shapes ----------------------------------------- */

            /* A plain link. For a menu, a header, a sticky mobile bar. */
            renderButton(root) {
                var link = document.createElement('a');
                var label = this.setting('label');

                link.className = 'cta';
                link.href = this.bookingUrl({
                    type: this.setting('booking-type'),
                    people: this.setting('people'),
                    date: this.today(),
                    time: '',
                });
                link.textContent = label === SETTINGS.label ? 'Book now' : label;

                if (this.setting('on-search') === 'redirect_blank') {
                    link.target = '_blank';
                    link.rel = 'noopener';
                }

                root.append(link);
            }

            /* The whole flow, inside their page. */
            renderInline(root) {
                var frame = document.createElement('iframe');

                frame.className = 'inline';
                frame.title = 'Reservations';
                frame.loading = 'lazy';
                frame.src = this.bookingUrl({
                    type: this.setting('booking-type'),
                    people: this.setting('people'),
                    date: this.today(),
                    time: '',
                });

                root.append(frame);
                this.listenForResize(frame);
            }

            renderSearch(root) {
                var saved = this.remembered();
                var form = document.createElement('form');

                form.innerHTML = [
                    '<div class="field" data-type hidden>',
                    '<label for="tr-type">What</label>',
                    '<select id="tr-type"></select>',
                    '</div>',
                    '<div class="field">',
                    '<label for="tr-people">People</label>',
                    '<select id="tr-people"></select>',
                    '</div>',
                    '<div class="field">',
                    '<label for="tr-date">Date</label>',
                    '<input id="tr-date" type="date">',
                    '</div>',
                    '<div class="field" data-time hidden>',
                    '<label for="tr-time">Time</label>',
                    '<select id="tr-time"></select>',
                    '</div>',
                    '<button type="submit"></button>',
                    '<p class="note" hidden></p>',
                ].join('');

                root.append(form);

                this.form = form;
                this.fields = {
                    type: form.querySelector('#tr-type'),
                    typeBox: form.querySelector('[data-type]'),
                    people: form.querySelector('#tr-people'),
                    date: form.querySelector('#tr-date'),
                    time: form.querySelector('#tr-time'),
                    timeBox: form.querySelector('[data-time]'),
                    button: form.querySelector('button'),
                    note: form.querySelector('.note'),
                };

                this.fields.button.textContent = this.setting('label');

                for (var n = 1; n <= 12; n++) {
                    var option = document.createElement('option');

                    option.value = String(n);
                    option.textContent = n === 1 ? '1 person' : n + ' people';
                    this.fields.people.append(option);
                }

                this.fields.people.value = String(saved.people || this.setting('people'));

                var today = this.today();

                this.fields.date.min = today;
                this.fields.date.value = saved.date && saved.date >= today ? saved.date : today;

                form.addEventListener('submit', (event) => {
                    event.preventDefault();
                    this.go();
                });

                this.fields.date.addEventListener('change', () => this.loadTimes());
                this.fields.people.addEventListener('change', () => this.loadTimes());
                this.fields.type.addEventListener('change', () => this.loadTimes());

                this.loadTypes();
            }

            /*
             * With one booking type the selector never appears: a taverna that
             * only takes tables should not be asked what kind of booking this
             * is (§9.1).
             */
            /*
             * The venue is not taking bookings right now.
             *
             * The form is replaced by the one sentence we were given, because
             * a bar that has not renewed should not have a broken-looking
             * widget on its homepage — and a guest offered times that cannot
             * be booked is worse than a guest told plainly (SPEC §4.4).
             */
            showNotice(message) {
                var note = document.createElement('p');

                note.className = 'note';
                note.style.flex = '1 1 100%';
                note.textContent = message;

                if (this.form) {
                    this.form.replaceWith(note);
                    this.form = null;
                } else {
                    this.shadowRoot.append(note);
                }
            }

            /* Was this refusal the venue being closed, rather than a glitch? */
            closedUs(failure) {
                return !!failure && failure.code === 'bookings_closed';
            }

            loadTypes() {
                if (this.setting('booking-type')) {
                    this.loadTimes();

                    return;
                }

                this.api('/booking-types', {}).then((body) => {
                    var types = body.data || [];

                    if (types.length > 1) {
                        types.forEach((type) => {
                            var option = document.createElement('option');

                            option.value = type.slug;
                            option.textContent = type.name;
                            this.fields.type.append(option);
                        });

                        this.fields.typeBox.hidden = false;
                    } else if (types.length === 1) {
                        this.setAttribute('booking-type', types[0].slug);
                    }

                    this.loadTimes();
                }).catch((failure) => {
                    if (this.closedUs(failure)) {
                        this.showNotice(failure.notice);

                        return;
                    }

                    this.loadTimes();
                });
            }

            /*
             * Only times with something free are offered. A dropdown full of
             * options that then refuse the booking is the complaint people
             * make about every other system (§8.1).
             */
            loadTimes() {
                var fields = this.fields;

                fields.time.innerHTML = '';
                fields.button.disabled = true;
                fields.note.hidden = true;

                this.api('/times', {
                    type: fields.type.value || this.setting('booking-type'),
                    date: fields.date.value,
                    people: fields.people.value,
                }).then((body) => {
                    var times = body.data || [];

                    if (!times.length) {
                        fields.timeBox.hidden = true;
                        fields.note.textContent = 'Nothing free that day. Try another date.';
                        fields.note.hidden = false;

                        // Still clickable: the booking page explains properly,
                        // and a dead button on somebody's homepage looks
                        // broken rather than honest.
                        fields.button.disabled = false;

                        return;
                    }

                    times.forEach(function (slot) {
                        var option = document.createElement('option');

                        option.value = slot.time;
                        option.textContent = slot.from_price
                            ? slot.time + ' — from ' + slot.from_price
                            : slot.time;

                        fields.time.append(option);
                    });

                    fields.timeBox.hidden = false;
                    fields.button.disabled = false;
                }).catch((failure) => {
                    if (this.closedUs(failure)) {
                        this.showNotice(failure.notice);

                        return;
                    }

                    // A glitch, not a closure. The form still works: the
                    // booking page asks for the time itself.
                    fields.timeBox.hidden = true;
                    fields.button.disabled = false;
                });
            }

            values() {
                return {
                    type: this.fields.type.value || this.setting('booking-type'),
                    people: this.fields.people.value,
                    date: this.fields.date.value,
                    time: this.fields.timeBox.hidden ? '' : this.fields.time.value,
                };
            }

            go() {
                var values = this.values();

                this.remember({ people: values.people, date: values.date });

                var url = this.bookingUrl(values);
                var behaviour = this.setting('on-search');

                if (behaviour === 'redirect_blank') {
                    window.open(url, '_blank', 'noopener');
                } else if (behaviour === 'modal') {
                    this.openModal(url);
                } else if (behaviour === 'inline') {
                    this.replaceWithFlow(url);
                } else {
                    window.location.assign(url);
                }
            }

            /* A lightbox over their site. The thing i-host cannot do (§9.3). */
            openModal(url) {
                var dialog = this.shadowRoot.querySelector('dialog');

                if (!dialog) {
                    dialog = document.createElement('dialog');
                    dialog.innerHTML = '<button class="close" type="button" aria-label="Close">×</button>'
                        + '<iframe title="Reservations"></iframe>';

                    dialog.querySelector('.close').addEventListener('click', function () {
                        dialog.close();
                    });

                    this.shadowRoot.append(dialog);
                    this.listenForResize(dialog.querySelector('iframe'));
                }

                dialog.querySelector('iframe').src = url;
                dialog.showModal();
            }

            replaceWithFlow(url) {
                var frame = document.createElement('iframe');

                frame.className = 'inline';
                frame.title = 'Reservations';
                frame.src = url;

                this.form.replaceWith(frame);
                this.listenForResize(frame);
            }

            /*
             * The iframe tells us how tall it is (§9.3).
             *
             * Both the origin and the sending window are checked, because a
             * message handler that trusts its sender is a hole in whoever's
             * site this is embedded on.
             */
            listenForResize(frame) {
                var home = this.home;

                window.addEventListener('message', function (event) {
                    if (event.origin !== home || event.source !== frame.contentWindow) {
                        return;
                    }

                    var data = event.data;

                    if (data && data.type === 'tr:resize' && typeof data.height === 'number') {
                        frame.style.height = Math.max(320, Math.min(4000, data.height)) + 'px';
                    }
                });
            }
        });
    }

    /* --- Putting it on the page ------------------------------------------- */

    var script = document.currentScript;

    if (!script) {
        return;
    }

    if (!script.getAttribute('data-venue')) {
        // Nothing useful can be done, and a console line is kinder to the
        // developer than an empty space on their page.
        if (window.console) {
            console.warn('[TableReserved] Add data-venue="your-slug" to the script tag.');
        }

        return;
    }

    define();

    var element = document.createElement(TAG);

    element.setAttribute('origin', new URL(script.src, window.location.href).origin);

    Object.keys(SETTINGS).forEach(function (name) {
        var value = script.getAttribute('data-' + name);

        if (value !== null && value !== '') {
            element.setAttribute(name, value);
        }
    });

    var selector = script.getAttribute('data-target');
    var target = selector
        ? document.querySelector(selector)
        : document.getElementById('rsv-widget') || document.getElementById('tablereserved');

    if (target) {
        target.append(element);
    } else {
        // No container, so it goes exactly where the tag is. Pasting one line
        // into a page should be enough.
        script.parentNode.insertBefore(element, script.nextSibling);
    }
}());
