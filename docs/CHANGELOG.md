# Changelog

Everything that changed, newest first, in the words of what it does rather than
what it touched.

> **Nothing has been released yet.** Phase 1 is one milestone from done, so
> there are no version numbers to hang these under — the dates are the dates the
> work landed. Version headings start at the first install somebody other than
> us is using.
>
> 🔒 **This file is updated in the same commit as the work.** A changelog
> written afterwards is written from the commit log, and a commit log does not
> remember which of its forty subjects a customer would have noticed.

**This is not `PROGRESS.md`.** That file is the diary: why a decision went the
way it did, what a bug turned out to be, what is still open. This file is the
short answer to "what is different since last week", for somebody who does not
need the reasoning.

---

## 2026-10-31

### Added
- A preview install can rebuild its demo venue on purpose, with
  `php artisan demo:refresh`. It asks first on a live host, and writes the same
  believable place every time rather than a different one.

### Changed
- An area's card is built around the spots themselves — the tiles are the room,
  and everything an area can do (rename, seats and timing, open or close
  several, remove) sits in one row under them.
- The tiles read as a plan of the room rather than a stretched grid: fixed size,
  packed left, a closed one struck through.
- The season figures mean the same thing at four tables and at four hundred.
  The average occupancy is gone — it flattered a small venue and hid a busy
  night at a large one. In its place: the busiest day, how many days got at
  least half full, and how many days took anything at all.
- The money row only appears for a venue that actually takes money; a
  cash-on-arrival place no longer reads as having earned nothing.
- A figure page with fewer than twenty bookings says so, instead of presenting
  a shape drawn from four.
- One size and one place for a page's own action, on every screen that had
  drifted.

### Fixed
- A PayPal refusal now repeats what PayPal said, and which of its two hosts was
  asked. "Those credentials were refused" sent people looking for a typo when
  the keys were simply the sandbox pair pointed at live.
- Gateway keys this installation can no longer decrypt — the usual cause is a
  replaced `APP_KEY` — no longer take the Payments screen down with them. The
  screen loads, says the keys cannot be read, and lets them be replaced. Before
  this, the only page where they could be fixed was the page that would not
  open.
- The Reports charts draw their bars again. They had been given a class name
  another screen already owned, so the rules that sized them lost and the track
  collapsed to nothing.

---

## 2026-10-30

### Added
- `docs/ADMIN-SETUP.md`: what the platform operator has to set up, from a first
  install to the two cron jobs nobody watches.

### Changed
- "Add one of these" sits at the top of whatever it adds to, rather than at the
  bottom of the list it grows.
- Add forms open over the page instead of unfolding below it, so the list stays
  where it was and the back button closes the form.

---

## 2026-10-29

### Added
- **Reports** — how the season went: bookings and money, the busiest weekday,
  where the bookings came from, and how each booking type did. Four period
  presets, with a custom range behind a fold.
- A front page with something to say: what the product does, the nine things it
  actually does, and a way in.
- Every screen under Setup has a way back to it.

### Changed
- The settings live on one page, and the left rail has its four entries back.
- A settings screen states what it is set to before it shows the switches.
- The day opens underneath the week, and the week's bar is split by what is
  booked.
- The booking card is ranked around the telephone call that opens it: who, how
  to reach them, what they asked for, and the three buttons service needs.
- The platform screens say why an account is on the list.
- The figures stop taking a postcard each — tighter stats, filters on one line.

### Fixed
- The calendar meters stopped painting over the text beside them.
- `db:seed` no longer hands out a platform administrator. On a live install it
  was creating a known login with a known password; the account is made by
  `php artisan admin:create`, which asks for the password twice and never takes
  it as an argument.
- The demo seeder refuses production before reaching for a dev dependency, so
  `db:seed --force` on a `--no-dev` install stops failing with
  `Class "Faker\Factory" not found`.
- `optimize:clear` is in the deploy steps, before `optimize`. Without it a
  stale route cache survives a `git pull` and the first new route 500s.
- The fake Stripe keys the scrubber test is built on are shorter, so a secret
  scanner stops recognising them as real.

---

## 2026-10-28

### Changed
- The back office moved onto a left rail.
- **Today** was rebuilt around what is happening right now.
- Search comes first on the bookings list, with the filters one tap away.
- The guest counts are on the screen instead of inside a dropdown.
- A guest card is the start of a booking rather than the end of a search.

---

## 2026-10-27

### Added
- The **Venue profile** screen — address, coordinates, contact, indexing — and
  `sitemap.xml`, which leaves the thin profiles out.
- Object storage is real rather than one env var away: a `media` disk, the env
  block, and the steps in `DEPLOY.md`.

### Fixed
- Eleven routes no test touched are covered.

---

## 2026-10-22

### Added
- **Subscription payments** — checkout, a return that asks the provider what
  happened instead of believing the URL, and webhooks for every renewal.
- A failed payment is chased on days one, three and seven, and then the account
  locks.
- The **platform admin area**: accounts, plans, suspend and restore,
  impersonation, and an audit log that cannot be edited.
- The platform's **legal pages** — terms, privacy, DPA, cookies — versioned and
  editable without a deploy, so the checkout consent box has something to point
  at.
- An owner can **delete their account**, thirty days from now, with an export
  first and a slug that is never handed out again.
- Errors are reported to Sentry without reporting anybody's secrets: keys,
  guest details and credentials are scrubbed before the report leaves.
- A venue can upload its **logo and hero image**, with EXIF rotation applied and
  metadata stripped. This closed M1.
- **Money** and **System** — where the money is, and whether the parts nobody
  watches are still running.

---

## 2026-10-20

### Added
- The **subscription model**: a fifteen-day trial, monthly and annual packages,
  the lifecycle that advances itself, and a lockout at expiry that is genuinely
  read-only.

### Fixed
- The demo account stopped being seeded already expired.
- The payment settings test button, which could only ever answer 405.

---

## 2026-10-16

### Added
- **Public API v1** — a bearer token per account, test and live, rate limited
  per token, with leak detection on the `Origin` header.
- The **Developer** screen: the token, the endpoints with this venue's slug,
  a `curl` to copy, the OpenAPI document, and recent usage.
- The **embeddable widget** — one line on the customer's own site, a Web
  Component in a shadow root, 6 KB gzipped, with an iframe fallback. A browser
  harness came with it, and found two bugs in it on the first run.

---

## 2026-10-15

### Added
- The **guest registry**: guests per venue, matched on email, with history,
  private notes, tags, a CSV export and GDPR anonymisation.
- **Telephone bookings** — the same three steps the guest gets, with
  autocomplete from the registry, an optional email address, and price and
  availability overridable with a note.
- The **bookings list** with search, filters and totals for the whole filtered
  set, and the **week view**.

### Changed
- Every date column is a `datetime` rather than a `timestamp`, so MySQL accepts
  the migrations. On the second date column in a table it had been failing with
  `1067 Invalid default value`.
- Prepared for deployment to shared hosting.

### Fixed
- The missing booking type relation on reservation holds.
- The test suite runs, and the twelve things it found on the way are fixed.

---

## 2026-10-13

First commit. Everything from the foundations through to the voucher, built
against the spec before any of it was pushed anywhere:

- **Foundations** — Laravel skeleton on PHP 8.4, strict types, Pint, Larastan,
  auth, tenants and venues, the path-based tenant resolver, roles, and a
  tenant-isolation test that reads every model off the disk.
- **Structure** — booking types with Tables and Sunbeds presets, areas,
  resources individually and as sets, templates and bulk create, and open or
  close in one tap.
- **Availability** — opening hours per day with several intervals and past
  midnight, four booking modes, occupancy, closures at three levels, holds with
  a fifteen-minute TTL, buffers, lead times, and a month computed in one pass
  for the date picker.
- **Prices** — a base price plus rules by weekday, season and hour, with
  priority and no stacking, resolved on the server so a price from the client is
  always ignored.
- **The booking page** — search, times with how many are left, spots by row, a
  visible hold, E.164 phone numbers, notes to the venue, consent logging, and a
  confirmation in three screens.
- **Payments** — Stripe Checkout and PayPal Orders with the venue's own keys,
  cash on arrival, a webhook endpoint per venue with signature checks, hourly
  reconciliation, refunds, and an automatic full refund if the spot was taken
  while the guest was paying.
- **The voucher** — an HTML email that survives Outlook, a PDF, a signed QR
  token, a hand-written `.ics`, venue alerts with quiet hours, a daily summary
  in each venue's local time, guest reminders, and a bounce webhook that warns
  the venue its confirmation never arrived.

---

## 2026-10-09

Design. Thirty-three spec sections, the competitor analysis, and a prototype of
four screens — all before the first line of code.
