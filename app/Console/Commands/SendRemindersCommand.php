<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Notifications\SendDueReminders;
use App\Console\Concerns\RecordsItsRun;
use Illuminate\Console\Command;

final class SendRemindersCommand extends Command
{
    use RecordsItsRun;

    protected $signature = 'reservations:remind';

    protected $description = 'Email guests whose booking is coming up';

    public function handle(SendDueReminders $reminders): int
    {
        $result = $reminders->handle();

        return $this->report(
            sprintf('Reminded %d, failed %d.', $result['sent'], $result['failed']),
            $result['failed'] === 0,
        );
    }
}
