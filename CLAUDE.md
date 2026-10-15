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
- `config()` everywhere, `env()` only inside `config/` files — with a cached
  config, `env()` returns null and production breaks quietly. Note that
  `Config::string()` throws on a null value *even with a default*, because the
  key exists; for settings that may be unset, use `(string) config(...)`.
- A new date column needs a cast. Without `immutable_datetime` the value comes
  back as a plain string and the first `->format()` blows up.

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

**Listeners are declared, not discovered.** `bootstrap/app.php` does not call
`withEvents()`, so automatic discovery is off — and that is deliberate. The map
lives in `AppServiceProvider::registerListeners()`, where "what happens after a
booking is confirmed" can be read rather than inferred from a directory
listing. Adding a listener means adding a line there.

Queue configuration goes on the class as attributes, not properties:
`#[Tries(3)]`, `#[Backoff(30, 300)]`.

## Derived counters

A guest's counters (`bookings_count`, `no_show_count`, …) are **recomputed from
the reservations**, never incremented. `ReservationObserver` calls
`RecountGuest` whenever a watched column changes, and `php artisan
guests:recount` rebuilds all of them.

The reason is not efficiency, which would favour incrementing. It is that an
incremented counter drifts — a status changed by a console command, a row
deleted, an event that never fired — and nobody notices until an owner argues
with a number on their own screen. Recomputing makes the reservations the truth
and the counters a cache of them, so the worst a missed hook can do is leave the
cache briefly stale.

Any new denormalised figure follows the same rule: derive it, and ship the
command that rebuilds it.

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
