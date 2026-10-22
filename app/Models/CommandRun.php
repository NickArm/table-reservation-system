<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The last run of one scheduled command (SPEC §13.2).
 *
 * One row per command rather than a history — see the migration. What the
 * System screen asks is only ever "when did this last run, and did it work".
 */
class CommandRun extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'ran_at' => 'immutable_datetime',
        ];
    }

    /**
     * Writes the run, and never gets in the way of the command reporting it.
     *
     * A command whose own bookkeeping threw would have already done its work,
     * and the System screen would then show a failure that did not happen —
     * which is worse than showing nothing.
     */
    public static function record(string $command, string $summary = '', bool $ok = true): void
    {
        try {
            /*
             * Found by hand, then `forceFill`. Nothing on this model is
             * fillable, on purpose: a row here is written by one trait and
             * must never arrive from a request.
             *
             * Which rules out `updateOrCreate` **and** `firstOrNew`: both
             * mass-assign the attributes they search on, and strict mode
             * refuses to drop attributes quietly — so each throws rather
             * than writing, and with the catch below that meant every run
             * recorded nothing at all while every command reported success.
             * `ReservedSlug` has the same note; this is the third time the
             * trap has been sprung in this codebase, and the second time the
             * catch is what hid it.
             */
            $run = self::query()->where('command', $command)->first() ?? new self;

            $run
                ->forceFill([
                    'command' => $command,
                    'ok' => $ok,
                    'summary' => $summary === '' ? null : mb_substr($summary, 0, 500),
                    'ran_at' => now(),
                ])
                ->save();
        } catch (Throwable $exception) {
            /*
             * Never thrown onwards: the command has already done its work,
             * and failing it over its own bookkeeping would show a failure
             * that did not happen. Logged rather than swallowed, because a
             * silent catch here is what hid the bug above.
             */
            Log::warning('Could not record a command run.', [
                'command' => $command,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<int, string>  $commands
     * @return array<string, CommandRun|null>
     */
    public static function latestOf(array $commands): array
    {
        $runs = self::query()->whereIn('command', $commands)->get()->keyBy('command');

        $out = [];

        foreach ($commands as $command) {
            $out[$command] = $runs->get($command);
        }

        return $out;
    }

    /**
     * Late enough to worry about?
     *
     * Twice the interval, because one missed night is a busy host and two is
     * a cron that is not there.
     */
    public function isOverdue(int $everyHours = 24): bool
    {
        return $this->ran_at->addHours(max(1, $everyHours) * 2)->isPast();
    }

    public function ranAt(): CarbonImmutable
    {
        return $this->ran_at;
    }
}
