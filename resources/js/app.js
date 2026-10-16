/*
 * The dashboard is server-rendered (SPEC §3.3). What little script there is
 * lives here, and every page it touches works without it.
 *
 * Right now that is one thing: filling in a returning guest on the manual
 * booking form. Somebody rings, the owner types three letters of a name, and
 * the phone number they gave last August appears. Without the script the form
 * is simply typed out in full, which is what it was before.
 */

const MIN_QUERY = 2;
const DEBOUNCE_MS = 180;

function fillGuest(form, guest) {
    const set = (name, value) => {
        const field = form.querySelector(`[name="${name}"]`);

        if (field && value) {
            field.value = value;
        }
    };

    set('name', guest.name);
    set('email', guest.email);

    // The stored number is E.164, so it carries its own country code. Split it
    // back onto the two fields the form uses, and fall back to dropping it in
    // whole if the code is not one we offer.
    if (guest.phone) {
        const code = form.querySelector('[name="calling_code"]');
        const phone = form.querySelector('[name="phone"]');

        if (code && phone) {
            const match = Array.from(code.options)
                .map((option) => option.value)
                .filter((value) => guest.phone.startsWith(value))
                .sort((a, b) => b.length - a.length)[0];

            if (match) {
                code.value = match;
                phone.value = guest.phone.slice(match.length);
            } else {
                phone.value = guest.phone;
            }
        }
    }
}

function suggestGuests(input) {
    const list = document.getElementById('guest-suggestions');
    const form = input.closest('form');
    const endpoint = input.dataset.suggest;

    if (!list || !form || !endpoint) {
        return;
    }

    let timer = null;
    let controller = null;

    const close = () => {
        list.replaceChildren();
    };

    const render = (guests) => {
        close();

        guests.forEach((guest) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'suggest__item';
            button.setAttribute('role', 'option');

            const name = document.createElement('span');
            name.textContent = guest.name;
            button.append(name);

            const detail = document.createElement('small');
            detail.textContent = [guest.email, guest.phone, guest.note]
                .filter(Boolean)
                .join(' · ');
            button.append(detail);

            button.addEventListener('click', () => {
                fillGuest(form, guest);
                close();
                form.querySelector('[name="phone"]')?.focus();
            });

            list.append(button);
        });
    };

    const look = async () => {
        const term = input.value.trim();

        if (term.length < MIN_QUERY) {
            close();

            return;
        }

        // Abandon the previous request: somebody typing quickly should not
        // have an older, slower answer land on top of a newer one.
        controller?.abort();
        controller = new AbortController();

        try {
            const response = await fetch(`${endpoint}?q=${encodeURIComponent(term)}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });

            if (!response.ok) {
                return;
            }

            const data = await response.json();
            render(Array.isArray(data.guests) ? data.guests : []);
        } catch (error) {
            if (error.name !== 'AbortError') {
                close();
            }
        }
    };

    input.addEventListener('input', () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(look, DEBOUNCE_MS);
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            close();
        }
    });

    document.addEventListener('click', (event) => {
        if (!list.contains(event.target) && event.target !== input) {
            close();
        }
    });
}

document.querySelectorAll('[data-suggest]').forEach(suggestGuests);

/*
 * A copy button next to anything marked [data-copy] — the API token and the
 * curl line on the Developer page.
 *
 * The button is created here rather than rendered in the template so that
 * without script there is no button at all, instead of one that silently does
 * nothing. The text stays selectable either way.
 */
function addCopyButton(target) {
    if (!navigator.clipboard) {
        return;
    }

    const button = document.createElement('button');

    button.type = 'button';
    button.className = 'btn btn--ghost copy';
    button.textContent = 'Copy';

    button.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(target.textContent.trim());

            button.textContent = 'Copied';
        } catch {
            // Denied permission or an insecure origin. Saying so is more use
            // than a button that claims to have worked.
            button.textContent = 'Press ⌘C';
        }

        window.setTimeout(() => {
            button.textContent = 'Copy';
        }, 2000);
    });

    // Inside the box for a one-line secret, after it for a code block, which
    // is where the eye already is in each case.
    const inline = target.closest('.secret');

    if (inline) {
        inline.append(button);
    } else {
        target.closest('pre')?.after(button);
    }
}

document.querySelectorAll('[data-copy]').forEach(addCopyButton);
