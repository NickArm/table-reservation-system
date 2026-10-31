# Working in this codebase

Read `docs/SPEC.md` before changing behaviour, and `docs/PROGRESS.md` to see
where the work stands. Section numbers below point into the spec.

## Non-negotiable

**No AI attribution anywhere** (§31.3). Not in commit messages, not in code
comments, not in documentation, not in anything a user reads. Commit messages
are written in the project's own voice: `Add arrival window occupancy override
per area`.

**A feature is finished only when it has all three** (§22.4):

- [ ] tests, per §25
- [ ] an entry in `DemoRestaurantSeeder`
- [ ] an updated line in `docs/PROGRESS.md`

All three land in the same commit as the work.

**Every feature stays reachable.** A redesign may move a control, rename it,
or put it one tap further away — it may never quietly drop it. Anything that
existed and now has no route to it is lost: nobody files a bug for a feature
they cannot find, so it is never noticed and never fixed. When a screen is
rebuilt, list what was on it first and account for every item: kept here,
moved there, or deliberately removed and written down as removed.

**A feature that introduces a setting, a credential, a webhook or a scheduled
job also updates `docs/ADMIN-SETUP.md`**, in that same commit. A configuration
step that exists only in somebody's memory is a step that gets missed on the
second install, where it arrives as a bug report instead of a checklist line.

## Conventions

- `declare(strict_types=1);` in every PHP file. Pint enforces the rest.
  `phpdoc_types` is switched off in `pint.json` on purpose: `resource` is a PHP
  type keyword, so the fixer rewrites `Builder<Resource>` to `Builder<resource>`
  and quietly breaks the generics on `App\Models\Resource`.
- Validation lives in Form Requests, never in a controller.
- Writes go through single-purpose Actions in `app/Actions`, so the web, the
  API, a console command and a queued job share one implementation.
- Controllers stay thin: validate, call the action, return.
- Models hold relations, scopes and casts. No business logic.
- Backed enums for every state. Never bare strings scattered through the code.
- Money as integer cents. Never a float.
- Timestamps in UTC; convert only when displaying (§3.5).
- **Date columns are `$table->datetime(...)`, never `$table->timestamp(...)`.**
  SQLite treats them the same, so the suite stays green either way; MySQL does
  not. With `explicit_defaults_for_timestamp` off — the default — the server
  hands the first `TIMESTAMP` column in a table an implicit
  `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` and every later
  `NOT NULL` one a zero date, which strict mode rejects: the migration fails on
  the *second* date column with `1067 Invalid default value`. `datetime` also
  has no 2038 ceiling and no server-side timezone conversion. `$table->timestamps()`
  is fine — those two are nullable.
- `config()` everywhere, `env()` only inside `config/` files — with a cached
  config, `env()` returns null and production breaks quietly. Note that
  `Config::string()` throws on a null value *even with a default*, because the
  key exists; for settings that may be unset, use `(string) config(...)`.
- A new date column needs a cast. Without `immutable_datetime` the value comes
  back as a plain string and the first `->format()` blows up.
- **A date-only column is cast with an explicit format:
  `'immutable_date:Y-m-d'`, never a bare `'date'`.** Eloquent writes any date
  cast using the *connection's* format, so a bare `date` cast puts
  `'2026-07-04 00:00:00'` into the column. MySQL truncates it and nobody
  notices; SQLite keeps it, and every `where('date_from', '<=', $date)`
  compares the two as text and quietly matches nothing.
- **A column default never appears on the model `create()` hands back.** The
  database applies it on write; the in-memory instance still has no such
  attribute, so reading it returns null and anything derived from it inherits
  the null. Any value the application reads back right after creating a row is
  passed in explicitly, from `config()`; the column default stays as the floor
  for rows written outside the application. `config/tenancy.php` holds the
  `default_*` keys for exactly this reason.

## Tenancy

Every tenant-owned model uses `BelongsToTenant`. The trait filters reads and
stamps writes, so no controller has to remember either.

There is deliberately no "no tenant means no filter" path: with nothing bound
and no explicit bypass, `TenantScope` throws `TenantNotResolved`. A silently
unscoped query on a shared table is a data leak, and failing loudly is the
cheaper outcome.

To work across accounts — super admin screens, console tasks, seeders — be
explicit:

```php
app(TenantContext::class)->withoutScoping(fn () => /* ... */);
app(TenantContext::class)->asTenant($tenant, fn () => /* ... */);
Venue::acrossTenants()->get();
```

In `tinker`, call `app(\App\Tenancy\TenantContext::class)->bypass();` first.

`tests/Feature/Tenancy/TenantIsolationTest.php` walks every model on disk. A
new model with a `tenant_id` that forgets the trait fails the suite
immediately — leave that test alone unless you are strengthening it.

## The queue

**Nothing tenant-scoped goes onto the queue as a model.** A queued job starts
in a fresh process with no tenant bound, and `SerializesModels` would try to
re-query the model on unserialize — before any code of ours gets a chance to
bind one. `TenantScope` then refuses, correctly.

So jobs, listeners and events carry **identifiers**, and bind the tenant
themselves:

```php
use App\Tenancy\Concerns\RunsAsTenant;

$this->asTenant($event->tenantId, function (): void {
    $reservation = Reservation::query()->find($event->reservationId);
    // ...
});
```

Two consequences worth stating:

- **Mailables are not queued.** The listener is what sits on the queue; the
  message is built and sent inside the tenant context. A queued Mailable holding
  a model hits the same problem.
- Use `ShouldQueueAfterCommit` whenever the job concerns a row that was just
  written, or a fast worker will not find it.

**Listeners are declared, not discovered.** `bootstrap/app.php` calls
`->withEvents(discover: false)`, because discovery is on by default and would
register every handler in `app/Listeners` a *second* time alongside the
explicit map — every voucher emailed twice, every alert sent twice, which is
exactly what the suite caught. The map lives in
`AppServiceProvider::registerListeners()`, where "what happens after a booking
is confirmed" can be read rather than inferred from a directory listing.
Adding a listener means adding a line there; dropping the `withEvents` line
means sending everything twice.

Queue configuration goes on the class as attributes, not properties:
`#[Tries(3)]`, `#[Backoff(30, 300)]`.

**Testing a job that a queued listener dispatches: call the listener, do not
fire the event.** `Bus::fake()` and `Queue::fake()` both swallow the listener
itself — it is queued too — so the event fires, nothing runs, and the
assertion fails for a reason unrelated to what is being tested:

```php
Bus::fake();
app(AlertTheVenue::class)->handle(new ReservationWasConfirmed(...));
Bus::assertDispatched(EmailVenueAlert::class, ...);
```

Firing the event is right when the listener's own effects are what matter —
`Mail::fake()` leaves the queue alone, so on the `sync` connection the
listener runs for real.

## The embed widget

`public/embed.js` is a **plain static file**, not a Vite entry point. Its
address is pasted into customers' sites, so it has to stay `/embed.js` for
ever; a hashed bundle name would break every one of them on the next deploy.
The budget is 10KB gzipped, checked by a test.

Its settings live **on the element**, never in the file's closure. A second
script tag on the same page runs the file again but finds the custom element
already defined, so anything kept in the closure belongs to whichever tag ran
first — two widgets with different booking types quietly become one.

Changes to it need a browser; no PHP test can exercise a Web Component on
somebody else's page. `tests/Browser/widget/` runs it in Chromium against
deliberately hostile CSS, and its README says what each check is guarding
against. It is a development tool, not part of the suite: shared hosting has
no Node, and `php artisan test` stays the one command that proves the
application works.

## Extensibility

Anything likely to get a second implementation sits behind an interface from
the first: payment gateways, notification channels, storage.

New behaviour hooks onto domain events (`ReservationWasConfirmed`,
`ReservationWasCancelled`) rather than being wired into the core.

If you find yourself writing `if ($tenant->slug === '...')`, the model is
missing a field. Configuration, not a special case.

## Simplicity (§1.1)

Two users: a tourist on a phone at the beach who will not read anything, and a
taverna owner who opens the dashboard five times a day in the middle of
service.

Three taps to pay. Two screens for the owner. Everything else behind
"Advanced". If a feature needs explaining, it is designed wrong.
