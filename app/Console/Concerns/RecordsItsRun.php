<?php

declare(strict_types=1);

namespace App\Console\Concerns;

use App\Models\CommandRun;
use Illuminate\Console\Command;

/**
 * Leaves a note that the command ran (SPEC §13.2).
 *
 * Every scheduled command in this application does something nobody watches,
 * and a cron that is not there fails silently in every direction: trials
 * never end, nobody is chased for a failed payment, holds are never freed.
 * None of it throws. So each command says so when it finishes, and the System
 * screen reads the notes.
 *
 * The same sentence the command prints is the sentence that gets stored,
 * because a timestamp says the cron fired and not that it worked — "Checked
 * 12, changed 2" is the difference.
 *
 * @phpstan-require-extends Command
 */
trait RecordsItsRun
{
    /**
     * Prints the line, keeps it, and returns the exit code.
     *
     * Written as one call so that a command cannot print a report and forget
     * to record it, which is exactly the kind of omission nobody notices
     * until the System screen says a command has never run.
     */
    protected function report(string $summary, bool $ok = true): int
    {
        if ($ok) {
            $this->info($summary);
        } else {
            $this->warn($summary);
        }

        CommandRun::record((string) $this->getName(), $summary, $ok);

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
