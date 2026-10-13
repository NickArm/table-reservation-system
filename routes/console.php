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
