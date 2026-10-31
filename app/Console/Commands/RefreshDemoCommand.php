<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\DemoRestaurantSeeder;
use Illuminate\Console\Command;

/**
 * Rebuild the demo venue, on purpose (SPEC §22.2).
 *
 * `DemoRestaurantSeeder` refuses to run in production, and must: `db:seed`
 * runs on every deploy, and a deploy that invents a venue with a season of
 * fabricated bookings on a live host is indefensible.
 *
 * Somebody who has typed this command's name and answered its question is not
 * that case. Without it there is no way to put a demo on a preview install at
 * all — which is the install most likely to be shown to somebody, and the one
 * whose demo most needs resetting after a visitor has clicked through it.
 *
 * The seeder is idempotent: it wipes the previous demo venue and writes a new
 * one from a fixed seed, so the result is the same believable place every
 * time rather than a different one.
 */
final class RefreshDemoCommand extends Command
{
    protected $signature = 'demo:refresh {--force : Skip the question, for a script}';

    protected $description = 'Rebuild the demo venue and its season of bookings';

    public function handle(): int
    {
        if ($this->getLaravel()->isProduction() && ! $this->option('force')) {
            $this->warn('This install is in production.');
            $this->line('The demo venue and every booking in it will be deleted and written again.');
            $this->line('Nothing belonging to a real account is touched.');

            if (! $this->confirm('Rebuild the demo venue?')) {
                return self::SUCCESS;
            }
        }

        $seeder = $this->getLaravel()->make(DemoRestaurantSeeder::class);
        $seeder->evenInProduction = true;
        $seeder->setCommand($this);
        $seeder->run();

        $this->newLine();
        $this->info('Done. Sign in as the demo owner to see it.');

        return self::SUCCESS;
    }
}
