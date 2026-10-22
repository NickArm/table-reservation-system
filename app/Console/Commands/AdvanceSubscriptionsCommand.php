<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Subscriptions\AdvanceLifecycle;
use App\Actions\Subscriptions\SendDunningNotices;
use App\Actions\Subscriptions\SendTrialReminders;
use App\Console\Concerns\RecordsItsRun;
use Illuminate\Console\Command;

/**
 * The command that makes the subscription model real (SPEC §4.3).
 *
 * Without it a trial never ends and /register is a way to get the product for
 * nothing. It is on the deployment checklist for that reason, and the report
 * it prints is what tells somebody it is actually running.
 */
final class AdvanceSubscriptionsCommand extends Command
{
    use RecordsItsRun;

    protected $signature = 'subscriptions:advance {--no-reminders : Move statuses without emailing anybody}';

    protected $description = 'Move every account to the subscription state it should be in today';

    public function handle(
        AdvanceLifecycle $lifecycle,
        SendTrialReminders $reminders,
        SendDunningNotices $dunning,
    ): int {
        $moved = $lifecycle->handle();

        $lines = [sprintf(
            'Checked %d, changed %d — %d trials ended, %d expired.',
            $moved['checked'],
            $moved['changed'],
            $moved['trials_ended'],
            $moved['expired'],
        )];

        if ($this->option('no-reminders')) {
            return $this->report(implode(' ', $lines));
        }

        $sent = $reminders->handle();

        $lines[] = sprintf('Trial reminders: sent %d, failed %d.', $sent['sent'], $sent['failed']);

        /*
         * The statuses move first, the emails go second, and that order
         * matters: a notice has to be able to say whether the booking page
         * has already stopped, and it reads that from the state this run has
         * just settled.
         */
        $chased = $dunning->handle();

        $lines[] = sprintf(
            'Payment failure notices: sent %d, failed %d.',
            $chased['sent'],
            $chased['failed'],
        );

        /*
         * One line, recorded as well as printed. Nobody reads the output of a
         * cron; the System screen reads this (§13.2), and without it a
         * missing cron is indistinguishable from a quiet week.
         */
        return $this->report(
            implode(' ', $lines),
            $sent['failed'] === 0 && $chased['failed'] === 0,
        );
    }
}
