# Deploying to cPanel

> First target: `https://table.armenisnick.gr/` — cPanel, MySQL, no Node.
> This is a **preview** deployment. What is not finished yet is listed at the
> bottom; read that before the URL goes to anyone outside.

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
php artisan optimize        # config, routes and views cached in one go
php artisan up
```

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
confirmation emails, no reminders, no morning list, and payments whose
webhooks were missed are never reconciled.

cPanel → *Cron Jobs*, both every minute:

```
* * * * * cd /home/<user>/table-reservation && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

```
* * * * * cd /home/<user>/table-reservation && /usr/local/bin/php artisan queue:work --stop-when-empty --max-time=55 --tries=3 >> /dev/null 2>&1
```

Check the PHP binary path in cPanel → *MultiPHP Manager*; on some hosts it is
`/opt/cpanel/ea-php83/root/usr/bin/php`.

**If the host will not run a queue worker at all**, set `QUEUE_CONNECTION=sync`
in `.env`. Everything still works and nothing is lost — the guest simply waits
two or three seconds longer on the confirmation screen while the email and the
PDF are produced. That is the right trade if the alternative is silence.

## 6. First login

The demo seeder refuses to run in production, so there is no demo venue and no
`owner@demo.test`. Register the first account through the interface:

```
https://table.armenisnick.gr/register
```

That creates the account, its venue, and a 15-day trial. Then *Setup* → add a
booking type, an area and some tables or sunbeds, and the public page is live
at `/reservation/<your-slug>`.

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

**`APP_DEBUG=false`.** With it on, a stack trace on an error page shows the
database credentials.

---

## Not finished yet — read before sharing the URL

This deployment is for looking at, not for selling:

- **No subscriptions or billing** (M9). Every account gets a trial and nothing
  enforces payment. Accounts are created freely by anyone who finds `/register`.
- **No legal pages** (M10). Terms, privacy and the cancellation policy are
  infrastructure without text; a venue taking real money needs them.
- **No booking widget** (M8), so a venue cannot embed the form in its own site
  yet — only the hosted page at `/reservation/<slug>`.
- **English only.** Greek is Phase 3.
- **PDF vouchers need one package:** `composer require dompdf/dompdf`. Without
  it the confirmation email still goes out with the QR and the calendar file.

A sensible interim measure while it is public: a `.htaccess` password on
`/register` in cPanel → *Directory Privacy*, so that strangers cannot create
accounts on a system with no billing behind it.
