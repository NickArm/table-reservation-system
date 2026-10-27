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

### Subscription billing — our own keys

These charge venues their subscription into **our** account. They are not the
keys in the dashboard's *Payments* screen, which belong to each venue and
charge that venue's own guests into that venue's own account. Keeping the two
apart is the whole commercial position of the product, so they live in
different places and are never interchangeable.

Leave them unset and everything still works: the Billing page says online
payment is not switched on and tells owners to write to us. Filling them in is
what turns the buttons on.

- `BILLING_PROVIDER` — `stripe` or `paypal`. Whichever is configured is used;
  this only decides the order when both are.
- `BILLING_MODE` — `test` or `live`. **Deliberately separate from `APP_ENV`.**
  Staging against live keys charges real cards; production against test keys
  takes no money at all while looking like it has. In test mode the Billing
  page says so.
- `BILLING_STRIPE_SECRET` — the platform Stripe account's secret key
- `BILLING_STRIPE_WEBHOOK_SECRET` — from Stripe → *Developers → Webhooks*,
  after adding the endpoint below
- `BILLING_PAYPAL_CLIENT_ID`, `BILLING_PAYPAL_CLIENT_SECRET` — from the PayPal
  app (sandbox credentials while `BILLING_MODE=test`)
- `BILLING_PAYPAL_WEBHOOK_ID` — from the webhook you create in the PayPal
  dashboard. Without it a PayPal webhook **cannot be verified and is ignored**,
  which looks exactly like silence until a paying account lapses.

Then two things outside this server:

1. **Register the webhook** at each provider you use:
   - Stripe → `https://table.armenisnick.gr/webhooks/billing/stripe`,
     events `invoice.paid`, `invoice.payment_failed`,
     `customer.subscription.deleted`
   - PayPal → `https://table.armenisnick.gr/webhooks/billing/paypal`,
     events `PAYMENT.SALE.COMPLETED`,
     `BILLING.SUBSCRIPTION.PAYMENT.FAILED`, `BILLING.SUBSCRIPTION.CANCELLED`

   This is where a renewal eleven months from now arrives, and nowhere else —
   nobody is sitting in a browser when a card is charged for the second time.

2. **Create each plan at the provider** and put its id on the plan row:
   `plans.stripe_price_id` (a `price_…` id, recurring) and
   `plans.paypal_plan_id` (a `P-…` id). A plan with neither gets no button, on
   purpose: a button that dies on the other side is worse than no button.

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

It prints three lines: how many accounts it checked and moved, how many trial
reminders went out, and how many payment-failure notices. A run that reports
`Checked 0` means no accounts exist yet; a run that errors means the cron would
have been failing silently every night.

The payment-failure notices are the ones that matter commercially once real
money is involved: a card that expires is chased on days one, three and seven,
and without this cron the owner's first and only warning is their booking page
going quiet.

### The purge, and seeing what it would do first

`schedule:run` also fires `tenants:purge` nightly, which permanently deletes
accounts whose thirty-day wait is up (SPEC §29.3). It is the only command in
the application that destroys data, so before trusting it on a live database,
run it once with the flag that touches nothing:

```bash
php artisan tenants:purge --dry-run
```

It lists what it would delete and exits. A purged account cannot be restored,
and the address it used — `/reservation/<slug>` — is reserved for ever
afterwards, because a freed slug is a QR code on a sign pointing a tourist at
somebody else's booking page.

If this cron never runs, nothing breaks and nobody complains: deletion
requests simply sit there with their date passed, which is the safe direction
to fail in.

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

### The platform admin

Separate from any venue account, and there is no screen that creates one —
`is_super_admin` is not fillable anywhere, on purpose. Make it from the
terminal:

```bash
php artisan tinker
>>> \App\Models\User::forceCreate([
...     'name' => 'Nick',
...     'email' => 'you@yourdomain.gr',
...     'password' => 'something long',
...     'is_super_admin' => true,
...     'email_verified_at' => now(),
... ]);
```

Signing in with that account lands on `/admin` rather than a dashboard — a
platform admin belongs to no venue, so the dashboard has nothing to show them.
From there: every account with its status and usage, plans, suspend and
restore, extending a subscription by hand, signing in as a venue for support,
and the log of all of it.

Two things worth knowing before using it on a live account:

- **Suspending is not the same as expiring.** Expiry is what the nightly sweep
  does when a subscription runs out, and a payment undoes it. A suspension is
  a decision a person made, and **nothing automatic reverses it** — only the
  Restore button. Use it for a chargeback or an abuse report, not for an unpaid
  invoice.
- **Signing in as a venue is logged at both ends, with the reason you type.**
  Everything done while in there looks, in every other table, exactly like the
  owner doing it; the log is the only thing that can say otherwise afterwards.

## 6b. The legal pages

`/terms`, `/privacy`, `/dpa` and `/cookies` are live from the first deploy,
carrying **placeholder text that says so in as many words** — the public page
prints *"Draft — pending legal review"* above it. That is deliberate: a
half-finished legal page that looks finished is worse than an obviously
unfinished one.

The real wording goes in from *Admin → Legal*, **without a deploy**. Paste it
as Markdown, give it a version number, and press Publish.

Two things to know before doing that:

- **A published version cannot be edited.** Accounts agree to a version by
  name, so changing the words afterwards would quietly rewrite an agreement
  somebody already made. Every revision gets a new version number; the old
  one is kept and still readable in the editor's history.
- **"Venues have to agree again" puts a banner on every dashboard** until each
  one agrees. Tick it for a real change only. A typo fix should not interrupt
  four hundred venues, and a banner that appears every time is one nobody
  reads by the third time.

The placeholder text is not filler — it states the three things that matter
commercially and are easy to get wrong, so a lawyer has something to correct
rather than a blank page: the venue is the seller to the guest, tax compliance
is the venue's, and we are the processor rather than the controller of a
venue's guest list.

⚠️ **The sign-up form links to `/terms` and `/privacy` and requires the box to
be ticked.** Until the real text is published, anybody registering is agreeing
to a page that says it is a draft. Fine while this is only us looking at it;
not fine for a paying customer.

### A venue's own terms

Separate from the above, and the venue writes them: *Dashboard → Legal*, two
boxes pre-filled with suggested wording. Their guests agree to these at
checkout, and the cancellation policy is printed on every booking
confirmation.

Worth telling a new venue, because it is the one thing in that text only they
can decide: **how long before a booking a guest gets their money back.** The
suggested wording says 24 hours and says, in the text itself, to change it.

Nothing is published until they press Save. A venue that never opens the
screen has no documents, the checkout shows no links, and the confirmation
falls back to "to change or cancel, please call us on …" — which is true
either way.

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

## 8. Images, when the local disk stops being enough

`MEDIA_DISK=public` serves logos and hero photographs off this server, which
works from the first deploy and is the wrong answer once there are real
venues. Those images are fetched on **every** visit a tourist makes to a
booking page: shared hosting has little disk, and the nightly backups
multiply whatever is on it.

Moving to object storage changes one setting, because nothing anywhere stores
a URL — only a path. `Venue::logoUrl()` builds the address from whichever disk
is configured when the page renders.

Cloudflare R2 is what the spec names, for one reason: egress is the dominant
cost of this disk and R2 charges nothing for it (SPEC §30.1). Hetzner Object
Storage is as good if you would rather keep everything with one provider.

1. Create a bucket, and an API token scoped to it.
2. **Make the bucket publicly readable** and note its public address — either
   the r2.dev one or, better, a custom domain pointed at it.
3. Fill in the `MEDIA_*` block in `.env` (it is in
   `.env.production.example`) and set `MEDIA_DISK=media`.
4. Copy whatever is already on the local disk across, keeping the paths:
   everything lives under `tenants/<uuid>/venue/...`, so `rclone copy` or the
   Cloudflare dashboard both do it. Existing rows keep working untouched.

Three settings are each a trap worth knowing before you hit it:

- **`MEDIA_URL` is not `MEDIA_ENDPOINT`.** The endpoint is the S3 API and
  needs credentials; `MEDIA_URL` is where a browser fetches a file from.
  Leave it empty and every image on every booking page answers 401.
- **`MEDIA_REGION` is `auto`.** R2 has one region and that is its name.
  Anything else is accepted and then quietly ignored, and the first sign of
  trouble is a signature mismatch rather than a useful error.
- **No per-object ACLs.** R2 does not implement them, so nothing in the
  application asks for one — the bucket is made readable once, in step 2.
  That is why the `media` disk has no `visibility` key, and why changing it
  would break every upload.

The `media` disk also has `throw` on, unlike Laravel's stock `s3`. A failed
upload becomes an exception the owner is told about rather than a quiet
`false` that stores a path with no file behind it.

Vouchers are not affected: the PDF is rendered when the email is sent and
never stored, so there is nothing on this disk that needs a signed URL.

## 9. Error tracking

`sentry/sentry-laravel` is installed. With `SENTRY_LARAVEL_DSN` empty nothing
is sent and nothing breaks, which is how every developer machine and the test
suite run — so this step is optional until there are real venues on the system,
and worth doing before there are. Hearing about a broken checkout from a
customer's telephone call is a bad place to be.

Create a project at sentry.io, paste its DSN into `.env`, and set
`SENTRY_RELEASE` from the deploy script:

```bash
SENTRY_RELEASE=$(git rev-parse --short HEAD)
```

`config/sentry.php` is written by hand rather than published by the package,
because two of its defaults work against us (SPEC §30.2) and the reasons are
in the comments there:

- **`send_default_pii` is hard-coded off**, not read from the environment.
  Left on, Sentry attaches the request body, headers, cookies and IP of every
  failed request by itself — and the request body of the page that matters
  most here is a booking form with a tourist's name, email and telephone in
  it. That contradicts our own privacy page.
- **Every event passes through `App\Observability\SentryScrubber`** before it
  leaves. This is the one that is easy to miss: a stack trace carries the
  local variables of every frame, so a failure inside the payment flow
  carries a venue's **live gateway secret** without anybody having sent it.
  The scrubber redacts secrets and contact details by key name and by shape,
  including inside stack frames and exception messages.

Both are covered by tests, including one asserting the scrubber is not a
closure — a closure in a config file cannot be serialised, so `config:cache`
would fail on every deploy.

In Sentry itself, set alerts for **a new kind of error** and **a sudden
increase**, not for every event. Alerts on everything are alerts you stop
reading inside a week.

### What to watch

Payments, webhooks and queue jobs. That is where money breaks.

Deliberately **not** reported, because none of it means anything is wrong:
404s, validation errors, expired holds, failed logins, CSRF mismatches. One
exception is never ignored — `TenantNotResolved`, which means a query was
about to cross between two venues.

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
