<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Reservations\ReleaseExpiredHolds;
use Illuminate\Console\Command;

final class ReleaseExpiredHoldsCommand extends Command
{
    protected $signature = 'holds:release';

    protected $description = 'Delete reservation holds whose fifteen minutes are up';

    public function handle(ReleaseExpiredHolds $action): int
    {
        $released = $action->handle();

        $this->info($released === 0
            ? 'No expired holds.'
            : "Released {$released} expired ".str('hold')->plural($released).'.');

        return self::SUCCESS;
    }
}
