# Checking `public/embed.js` in a real browser

The widget is the one piece of this project that no PHP test can exercise: it
is a Web Component running on somebody else's page, and the things most likely
to break — Shadow DOM isolation, the colour reaching the button past a hostile
theme, two widgets on one page staying independent, dark mode following the
visitor's device — only exist in a browser.

So this runs it in one. It is a development tool, not part of the suite:
shared hosting has no Node, and `php artisan test` must stay the single
command that proves the application works.

## Running it

```bash
cd tests/Browser/widget
npm install          # playwright, once
npx playwright install chromium
node server.mjs &    # a stub of the two endpoints the widget calls
node check.mjs       # one widget, on a page with deliberately hostile CSS
node check-many.mjs  # three widgets on one page, and dark mode
kill %1
```

`server.mjs` answers `/api/internal/{slug}/booking-types` and `/times` with
the shapes `app/Api/V1/Payload.php` promises, and pretends to be the booking
page so the redirect can be inspected. If those shapes change, change them
here too — a stub that has drifted from the real thing is worse than none.

## What it checks, and why each one is here

| Check | The failure it would catch |
|---|---|
| A shadow root is attached | Without it the host page's CSS reaches inside, and ours leaks out |
| The button keeps its accent and 15px type under `all: unset` | The isolation silently not working |
| `origin` comes from the script's own `src` | A staging or self-hosted copy calling the wrong host |
| Times arrive with prices | The API shape changed under the widget |
| The type selector appears only with more than one type | A taverna with one booking type being asked which kind |
| Search lands on `/reservation/{slug}?…&channel=widget&ref=…` | Attribution lost, so the owner cannot see the widget earning its keep |
| Three widgets on one page keep their own settings | Settings kept in the script's closure instead of on the element — the second tag would silently become a copy of the first |
| `theme="auto"` follows the device, `theme="light"` overrides it | The stylesheet selects on the attribute, so an unset theme loses dark mode |
