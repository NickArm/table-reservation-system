<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Notifications\SendDailySummaries;
use Illuminate\Console\Command;

final class SendDailySummariesCommand extends Command
{
    protected $signature = 'venues:daily-summary {--hour= : Send as though it were this local hour}';

    protected $description = 'Email each venue the day ahead, at the hour it asked for';

    public function handle(SendDailySummaries $summaries): int
    {
        $hour = $this->option('hour');

        $result = $summaries->handle($hour === null ? null : (int) $hour);

        $this->info(sprintf('Sent %d, failed %d.', $result['sent'], $result['failed']));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
