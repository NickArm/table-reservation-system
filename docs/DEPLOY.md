# Deploying to cPanel

> First target: `https://table.armenisnick.gr/` — cPanel, MySQL, no Node.
> Phase 1 is complete: billing, legal, the widget and the API are all in.
> **Read §6 before running `db:seed` on a live host.**
>
> This file is about getting the code onto a server. What has to be *true*
> before the first customer pays — your own payment credentials, the texts
> every account agrees to, the jobs nobody watches — is in
> [`ADMIN-SETUP.md`](ADMIN-SETUP.md).

---

## What the server needs

| | |
|---|---|
| PHP | **8.3 or newer** (8.4 preferred), with `mbstring`, `openssl`, `pdo_mysql`, `curl`, `zip`, `fileinfo`, `gd` |
| Database | MySQL 5.7+ or MariaDB 10.3+, `utf8mb4` |
| Composer | 2.x (cPanel usually has `composer` in the terminal; otherwise upload `vendor/`) |
| Node | **Not needed.** The compiled CSS and JS are committed (see below) |

Set the PHP version in cPanel → *MultiPHP Manager* before anything else. The
app will not boot on 8.2.

---

## 1. Document root

Laravel serves from `public/`, and nothing above it may be reachable over the
web — `.env` sits one level up.

In cPanel → *Domains* → `table.armenisnick.gr` → set the document root to:

```
/home/<user>/table-reservation/public
```

Do **not** move `index.php` to the account root and rewrite paths. It works
until something in `storage/` becomes fetchable.

## 2. The code

```bash
cd ~
git clone https://github.com/NickArm/table-reservation-system.git table-reservation
cd table-reservation
composer install --no-dev --optimize-autoloader
```

Later deployments:

```bash
cd ~/table-reservation
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear  # ⚠️ before, not instead
php artisan optimize        # config, routes and views cached in one go
php artisan up
```

**`optimize:clear` is not optional on a pull that adds a route.** `optimize`
writes a route cache and a config cache; it does not notice that the old ones
describe different code. Pull a commit that adds `/terms` without clearing,
and every page that links to it dies with

```
Route [legal] not defined.
```

— pointing at a line that is plainly right in front of you, in a file that
plainly registers the route. Both caches are generated files; clearing them
costs nothing and the next command rebuilds them.

## 3. The environment file

```bash
cp .env.production.example .env
php artisan key:generate
```

Then fill in, in `.env`:

- `APP_URL=https://table.armenisnick.gr` — vouchers, QR codes and webhook
  addresses are all built from this. A wrong value produces QR codes that
  point nowhere, and they are already printed by then.
- the four `DB_*` values from cPanel → *MySQL Databases*
- the `MAIL_*` values from Brevo → *SMTP & API*
- `MAIL_WEBHOOK_SECRET` — any long random string:
  `php artisan key:generate --show`

## 4. Migrate and link storage

```bash
php artisan migrate --force
php artisan storage:link
```

`storage:link` makes venue logos and hero images reachable. Without it the
booking page loads with empty image frames and no error anywhere.

If cPanel refuses symlinks, set `MEDIA_DISK=public` aside and serve media from
object storage instead (SPEC §30.1) — but try the symlink first, it usually
works.

Permissions, if the terminal complains:

```bash
chmod -R 775 storage bootstrap/cache
```

## 5. The two cron jobs ⚠️

**Without these, the system looks like it works and quietly does not.** No
confirmation emails, no reminders, no morning list, payments whose webhooks
were missed are never reconciled — and **no trial ever ends**, which means
`/register` hands out the whole product for nothing, for ever. The first
cron below is the one that runs the subscription sweep, so it is not
optional in the way a missed reminder is.

cPanel → *Cron Jobs*, both every minute:

```
* * * * * cd /home/<user>/table-reservation && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

```
* * * * * cd /home/<user>/table-reservation && /usr/local/bin/php artisan queue:work --stop-when-empty --max-time=55 --tries=3 >> /dev/null 2>&1
```

Check the PHP binary path in cPanel → *MultiPHP Manager*; on some hosts it is
`/opt/cpanel/ea-php83/root/usr/bin/php`.

### Checking the subscription sweep is actually running

`schedule:run` is what fires `subscriptions:advance` every night, and that
command is what ends trials and locks accounts that have not paid. If it is
not running, nothing complains — accounts simply stay on trial for ever. So
after the first deploy, run it once by hand and read what it says:

```
php artisan subscriptions:advance
```

It prints how many accounts it checked and how many it moved. A run that
reports `Checked 0` means no accounts exist yet; a run that errors means the
cron would have been failing silently every night.

**If the host will not run a queue worker at all**, set `QUEUE_CONNECTION=sync`
in `.env`. Everything still works and nothing is lost — the guest simply waits
two or three seconds longer on the confirmation screen while the email and the
PDF are produced. That is the right trade if the alternative is silence.

## 6. Seed, then create the administrator ⚠️

A live install still has to seed. Two of the three seeders are not demo data:
`PlanSeeder` writes the subscription packages, and `PlatformDocumentSeeder`
writes the placeholder terms, privacy, DPA and cookie texts that `/terms` and
the signup consent box read. Without them, signup has nothing to agree to and
no plan to put anybody on.

```bash
php artisan db:seed --force
```

The demo venue is **not** created — `DemoRestaurantSeeder` refuses outright in
production, so there is no `owner@demo.test` and no demo bookings.

**The platform administrator is not created either, and that is deliberate.**
`db:seed` used to make one at `admin@tablereserved.test` with the password
`password`, which is correct on a laptop and, on a live host, a published set
of credentials to an account that reads every venue, every guest list and
every booking on the platform. Make your own:

```bash
php artisan admin:create
```

It asks for the address and the password twice, requires at least twelve
characters with letters and numbers, and refuses anything that appears in a
known breach list. If the address already exists it offers to promote that
account instead, which is usually what you want: one login for the venue and
the platform.

Sign in to the platform screens at `/admin`.

### Then the first venue

Register it through the interface like any customer would:

```
https://table.armenisnick.gr/register
```

That creates the account, its venue, and a trial. Then *Setup* → add a booking
type, an area and some tables or sunbeds, and the public page is live at
`/reservation/<your-slug>`.

## 7. Mail delivery

In Brevo, add the webhook so that bounces come back to us (SPEC §10.3):

```
https://table.armenisnick.gr/webhooks/mail/<MAIL_WEBHOOK_SECRET>
```

Events to tick: `delivered`, `hard_bounce`, `soft_bounce`, `blocked`,
`invalid_email`, `spam`.

For the sending domain, SPF, DKIM and DMARC have to exist or the confirmations
land in spam. Brevo's own setup page walks through the DNS records.

Payment webhooks are **per venue** and are shown, with the venue's own
address filled in, on the dashboard's *Payments* screen.

---

## Things that bite on a first MySQL deploy

**Indexed strings are capped at 191 characters.** `AppServiceProvider` sets
`Schema::defaultStringLength(191)`, because utf8mb4 spends four bytes a
character and InnoDB's older index limit is 767 bytes per column — a
`varchar(255)` in an index needs 1020 and the migration fails outright. If the
server turns out to be new enough for 3072-byte keys, the cap can be lifted;
there is no reason to.

**Date columns are `datetime`, never `timestamp`.** MySQL's
`explicit_defaults_for_timestamp` is off by default, and with it off the server
quietly invents defaults for `TIMESTAMP` columns: the first one in a table gets
`DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`, and every later
`NOT NULL` one gets `'0000-00-00 00:00:00'` — which strict mode then refuses,
so the migration dies on *the second* date column with
`1067 Invalid default value`. `DATETIME` has none of that behaviour, no 2038
ceiling, and no server-side timezone conversion, which is what this app wants:
it stores UTC and says so (SPEC §3.5). SQLite treats the two identically, so
the local test suite never sees the difference — this is MySQL-only, and it
only shows up on deploy. Laravel's own three migrations are left alone; they
have one date column each and an explicit `useCurrent()` where it matters.

**The compiled assets are committed**, under `public/build`. Shared hosting has
no Node, and a deploy that needs `npm run build` is a deploy that fails with a
white page. The trade-off is noise in diffs whenever the CSS changes, which is
the cheaper problem. After changing anything in `resources/css` or
`resources/js`, run `npm run build` locally and commit what changes.

**`php artisan optimize` caches the config**, and from that moment `env()`
returns `null` everywhere outside `config/`. Nothing here calls `env()` outside
config files (SPEC §31.2) — keep it that way, or production breaks silently
while development stays fine.

**`APP_DEBUG=false`.** With it on, any error shows a full stack trace to
whoever hit it — including, a few frames down, the database credentials. It is
the one setting that turns a broken page into a disclosed password, so check
it on the live host rather than trusting that the example file was copied
unedited:

```bash
php artisan tinker --execute="echo config('app.debug') ? 'ON — fix this' : 'off';"
```

---

## Before the URL goes to a customer

Phase 1 is complete — billing, dunning, the legal documents, the widget, the
API and account deletion are all in. What is left is configuration, not code.

**Switch the demo login off.** The front page prints a working login to the
demo venue, which is right while you are showing it to people and wrong the
moment strangers can find it:

```
DEMO_LOGIN_SHOWN=false
```

With `APP_ENV=production` it is already off by default. Set it explicitly
anyway, so that nobody has to reason about the default.

**PDF vouchers need one package.** Without it the confirmation email still
goes out, with the QR code and the calendar file, but no attachment:

```bash
composer require dompdf/dompdf
```

**Each venue adds its own payment keys**, on its own *Payments* screen, with
its own webhook address shown there. Nothing is configured centrally and the
platform never holds the money.

**Error tracking is inert without a DSN.** The scrubber and its tests are in
place; add `SENTRY_LARAVEL_DSN` when you want the reports.

**Images.** `MEDIA_DISK=public` plus `storage:link` is fine to start. Moving
to R2 later is `MEDIA_DISK=media` and the four `MEDIA_*` values; the disk is
configured and tested, and nothing else changes.

**English only.** Greek is Phase 3; the translation files are the only work.

### Still to decide, not to build

- Platform name and domain (`APP_NAME`, `APP_URL` — nothing else reads it)
- Whether there is a free tier, and what the entry price is
- Who sets a venue up: self-service, or you, with a phone call
- Refund policy on a subscription, and the support channel to print on it
