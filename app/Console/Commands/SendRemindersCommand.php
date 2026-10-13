<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Notifications\SendDueReminders;
use Illuminate\Console\Command;

final class SendRemindersCommand extends Command
{
    protected $signature = 'reservations:remind';

    protected $description = 'Email guests whose booking is coming up';

    public function handle(SendDueReminders $reminders): int
    {
        $result = $reminders->handle();

        $this->info(sprintf('Reminded %d, failed %d.', $result['sent'], $result['failed']));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
