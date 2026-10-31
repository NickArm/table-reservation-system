# What the platform operator has to set up

> Everything that is **yours** rather than a venue's: the platform's own
> credentials, the texts every account agrees to, and the two jobs nobody
> watches. `DEPLOY.md` covers getting the code onto a server; this covers what
> has to be true before the first customer pays you.
>
> 🔒 **This file is updated in the same commit as any feature that adds a
> setting.** A configuration step that exists only in somebody's memory is a
> step that gets missed on the second install, at which point it is a bug
> report instead of a checklist line.

---

## The line that runs through all of it

There are two completely separate sets of payment credentials, and they must
never meet:

| | Whose | Where it lives | Charges |
|---|---|---|---|
| `BILLING_*` | **Yours** | `.env` / `config/billing.php` | Venues, for their subscription |
| Gateway settings | **Each venue's** | `payment_gateway_settings` table | That venue's own guests |

A guest's dinner charged to your account, or your subscription charged to
theirs, is the most serious bug this application could have. That is why they
live in different places under different names, and why yours are in config
rather than in the database — there is only ever one of you.

You never configure a venue's keys. They do, on their own *Payments* screen,
and nothing you can see shows them to you.

---

## 1. First install

In order. The middle two are easy to skip and both bite later.

```bash
php artisan migrate --force
php artisan db:seed --force    # plans + the platform's legal texts
php artisan admin:create       # your own login; db:seed makes none
php artisan optimize:clear && php artisan optimize
```

`db:seed` is not optional on a live install. `PlanSeeder` writes the
subscription packages and `PlatformDocumentSeeder` writes the terms, privacy,
DPA and cookie texts — without them signup has nothing to agree to and no plan
to put anybody on. The demo venue is refused in production, so nothing
fictional is created.

### The `.env` values that are yours

| | |
|---|---|
| `APP_ENV=production` | Not cosmetic. It is what refuses the demo seeder, hides the demo login, and switches off strict mode |
| `APP_DEBUG=false` | With it on, any error shows a stack trace — and a few frames down, the database password |
| `APP_URL` | Vouchers, QR codes and every webhook address are built from this. A wrong value prints QR codes that point nowhere |
| `DEMO_LOGIN_SHOWN=false` | The front page prints a working demo login. Off by default in production; set it anyway |

### The demo venue

`db:seed` never creates one on a live host, and must not: it runs on every
deploy, and a deploy that invents a venue with a season of fabricated bookings
is indefensible.

A preview install is exactly where a demo *is* wanted, though, and exactly
where it needs resetting after somebody has clicked through it. One command,
which asks first:

```bash
php artisan demo:refresh
```

It wipes the previous demo venue and writes a new one from a fixed seed, so
the result is the same believable place every time rather than a different one.
Nothing belonging to a real account is touched. It needs Faker, which
`--no-dev` leaves out:

```bash
composer require --dev fakerphp/faker
```

Remember `DEMO_LOGIN_SHOWN=false` once strangers can reach the front page: the
demo venue existing is fine, advertising its password is not.

---

## 2. Taking the subscription money

The platform charges venues through **one** provider, set by
`BILLING_PROVIDER`. Both can be configured; the other is then offered as an
alternative on the billing screen.

### `BILLING_MODE` is not `APP_ENV`

Deliberately separate. Staging against live keys charges real cards;
production against test keys takes no money at all while looking exactly like
it has.

### PayPal

It is a **subscription**, not a one-off payment: PayPal bills on its own
schedule and tells us.

1. PayPal dashboard → **Products & Plans** → one product, then one plan per
   package (monthly, annual). Keep the plan ids (`P-…`).
2. **Webhooks** → point one at `https://<your-domain>/webhooks/billing/paypal`
   with these events:
   - `PAYMENT.SALE.COMPLETED`
   - `PAYMENT.SALE.DENIED`
   - `BILLING.SUBSCRIPTION.PAYMENT.FAILED`
   - `BILLING.SUBSCRIPTION.CANCELLED`
   - `BILLING.SUBSCRIPTION.EXPIRED`
3. Keep the **webhook id**.

```
BILLING_PROVIDER=paypal
BILLING_MODE=live
BILLING_PAYPAL_CLIENT_ID=
BILLING_PAYPAL_CLIENT_SECRET=
BILLING_PAYPAL_WEBHOOK_ID=
```

Without the webhook id nothing can be verified, and an unverified webhook is a
stranger telling you somebody paid.

### Stripe

```
BILLING_STRIPE_SECRET=
BILLING_STRIPE_WEBHOOK_SECRET=
```

Webhook at `https://<your-domain>/webhooks/billing/stripe`, event
`checkout.session.completed`.

### Then, in `/admin` → Plans

Paste each provider's own id into the matching package: **PayPal plan id**
and/or **Stripe price id**. Without it the pay button refuses with "this plan
is not set up" — correctly, but only at the moment a customer presses it.

### What happens by itself afterwards

- The owner pays; **the return URL is not believed**. The application asks the
  provider what happened, because anybody can type a return URL.
- Every renewal arrives as a webhook and extends the subscription.
- A failed charge starts dunning: email on day 1, 3 and 7, then the account
  locks.
- A cancellation at the provider is reflected here.

### By hand

`/admin` → the account → **Extend**, with a date and a reason. It is written
to the audit log with who did it. Use it for a bank transfer, a goodwill
month, or a provider outage.

---

## 3. Email

```
MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_USERNAME=              # the SMTP login, not the API key
MAIL_PASSWORD=              # the SMTP key (xsmtpsib-…), not the API key (xkeysib-…)
MAIL_FROM_ADDRESS=          # a domain you control, with SPF/DKIM/DMARC
MAIL_WEBHOOK_SECRET=        # yours: php artisan key:generate --show
```

Brevo's **API key and SMTP key are different credentials** on the same page.
The application uses SMTP only; the API key is not read anywhere.

`MAIL_FROM_ADDRESS` must be a sender the provider has verified, on a domain
with SPF, DKIM and DMARC. Otherwise messages are refused outright or land in
spam, and the only sign is a quiet failure.

**`MAIL_WEBHOOK_SECRET` is yours, not Brevo's.** It becomes part of the
callback address, which is the only thing stopping anyone from calling it.
Then in Brevo add the webhook:

```
https://<your-domain>/webhooks/mail/<MAIL_WEBHOOK_SECRET>
```

Events: `delivered`, `hard_bounce`, `soft_bounce`, `blocked`, `invalid_email`,
`spam`. Without it a venue never learns that a guest's confirmation bounced,
which is the one case where a booking needs a phone call.

### Checking it, rather than assuming

```bash
php artisan tinker --execute="echo config('mail.default');"
```

If that prints `log`, mail is being written to `storage/logs/laravel.log`
instead of sent — no errors, no failed jobs, and nothing delivered. It almost
always means a config cache built before the `MAIL_*` values were set.

---

## 4. The two cron jobs

Every minute, both:

```
* * * * * cd /path/to/app && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /path/to/app && /usr/local/bin/php artisan queue:work --stop-when-empty --max-time=55 --tries=3 >> /dev/null 2>&1
```

**Without these the system looks like it works and quietly does not.** No
confirmations, no reminders, no morning list, no renewals processed — and **no
trial ever ends**, so `/register` hands out the whole product for nothing, for
ever.

Check the PHP path: the binary in cron is not always the one your shell gets.

```bash
which php                      # compare against the cron line
php artisan subscriptions:advance   # run once by hand; it should print what it checked
```

A run that errors means the nightly cron has been failing silently.

`/admin` → **System** shows when each scheduled job last ran, which is there
precisely because a missing cron does not throw, does not log and does not
email anybody.

If the host will not run a worker at all, set `QUEUE_CONNECTION=sync`. Nothing
is lost — the guest simply waits two or three seconds longer on the
confirmation screen.

---

## 5. The texts every account agrees to

`/admin` → **Legal**. Terms, privacy, DPA and cookie notice, editable without a
deploy, because the real wording arrives from a lawyer, arrives twice, and
usually after the first customer has signed up. The seeder writes placeholders
marked as drafts so nothing is ever blank.

Each venue writes its **own** booking terms and cancellation policy on its own
screen. Those are not yours and you do not edit them.

---

## 6. Optional, and safe to leave until needed

| | |
|---|---|
| `SENTRY_LARAVEL_DSN` | Error reports, scrubbed of keys and guest details. Inert without a DSN — the scrubber is already in place |
| `MEDIA_DISK=media` + `MEDIA_*` | Object storage for venue logos and hero images. `MEDIA_DISK=public` with `storage:link` is fine to start |

---

## When you add a feature

If it introduces a setting, a credential, a webhook or a scheduled job, it
belongs in this file, in the same commit. The test of whether the line is good
enough: somebody standing up a second install, six months from now, who was
not in the room.
