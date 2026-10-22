<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Schedule
|--------------------------------------------------------------------------
|
| Every minute on a server we control. If the host only allows cron every
| five minutes it still works, because availability ignores expired holds on
| its own — this only keeps the table tidy (SPEC §21.2).
*/

Schedule::command('holds:release')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * The safety net under the webhooks (SPEC §24.2). A guest who paid and has no
 * booking is the worst failure this system can have, and webhooks get lost.
 */
Schedule::command('payments:reconcile')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Reminders (SPEC §10.1). Every quarter hour rather than hourly, so that a
 * venue reminding guests an hour ahead is accurate to the quarter instead of
 * to the hour.
 */
Schedule::command('reservations:remind')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * The morning list (SPEC §10.2). Hourly on purpose: the command itself decides
 * which venues it is the right hour for, in their own local time, so one
 * schedule serves every timezone and survives the clock change.
 */
Schedule::command('venues:daily-summary')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * The subscription lifecycle (SPEC §4.3).
 *
 * Daily, early, in a quiet hour. This is what ends a trial and locks an
 * account that has not paid, so without it running there is no subscription
 * model — only a signup form that gives the product away. It is on the
 * deployment checklist for exactly that reason.
 *
 * Deliberately not hourly. Nothing here is urgent to the hour, and an account
 * that expires at 03:40 rather than 03:00 has lost nothing; running it once a
 * day keeps the trial-reminder emails to one a day per account by
 * construction rather than by a lock.
 */
Schedule::command('subscriptions:advance')
    ->dailyAt('03:40')
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Accounts whose thirty days are up (SPEC §29.3).
 *
 * An hour after the subscription sweep, and separately from it, because this
 * one destroys data irreversibly and the other one sends emails. A bug in the
 * cheap half must not be able to reach the expensive one.
 *
 * Once a day is often enough: the delay is thirty days, so being a few hours
 * late is not a thing anybody can notice.
 */
Schedule::command('tenants:purge')
    ->dailyAt('04:40')
    ->withoutOverlapping()
    ->runInBackground();
