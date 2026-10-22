<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Reservations\ReleaseExpiredHolds;
use App\Console\Concerns\RecordsItsRun;
use Illuminate\Console\Command;

final class ReleaseExpiredHoldsCommand extends Command
{
    use RecordsItsRun;

    protected $signature = 'holds:release';

    protected $description = 'Delete reservation holds whose fifteen minutes are up';

    public function handle(ReleaseExpiredHolds $action): int
    {
        $released = $action->handle();

        return $this->report($released === 0
            ? 'No expired holds.'
            : "Released {$released} expired ".str('hold')->plural($released).'.');
    }
}
