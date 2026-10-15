<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Guests\RecountGuest;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Rebuilds every guest's counters from their reservations (SPEC §28.2).
 *
 * The reason this exists is not that the counters are expected to be wrong. It
 * is that without it, a bug in the observer would leave them permanently wrong
 * with no way to put them right — and an owner who stops trusting the numbers
 * on their own screen stops trusting the system.
 */
final class RecountGuestsCommand extends Command
{
    protected $signature = 'guests:recount';

    protected $description = 'Rebuild guest counters from the reservations themselves';

    public function handle(RecountGuest $recount, TenantContext $context): int
    {
        // Across every venue: this is maintenance, not a tenant's request.
        $counted = $context->withoutScoping(fn (): int => $recount->all());

        $this->info(sprintf('Recounted %d %s.', $counted, str('guest')->plural($counted)));

        return self::SUCCESS;
    }
}
