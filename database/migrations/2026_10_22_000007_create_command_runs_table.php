<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When each scheduled command last ran, and what it did (SPEC §13.2).
 *
 * The one thing this application cannot find out any other way. A missing
 * cron does not throw, does not log and does not alert: trials never end,
 * nobody is chased for a failed payment, and accounts that asked to be
 * deleted sit there for ever. All of it looks exactly like a quiet week.
 *
 * A table rather than the cache, which was the first instinct: `cache:clear`
 * runs on most deploys, and a System page that reads "never ran" after every
 * deploy is an alarm that gets ignored inside a fortnight.
 *
 * **One row per command**, not a log. `holds:release` runs every minute, so a
 * history would be fourteen hundred rows a day of something nobody will ever
 * read — and the question this answers is only ever "when did it last run".
 *
 * Not tenant-scoped: these are the platform's own commands, and the sweeps
 * that report here walk every account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('command_runs', function (Blueprint $table): void {
            $table->id();

            // Unique, because the row *is* the latest run.
            $table->string('command', 120)->unique();

            // Whether it got to the end. A command that threw halfway is the
            // case worth seeing, and it is not the same as not having run.
            $table->boolean('ok')->default(true);

            // One line of what it did — "Checked 12, changed 2" — because a
            // timestamp alone says the cron fired, not that it worked.
            $table->string('summary', 500)->nullable();

            $table->datetime('ran_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('command_runs');
    }
};
