<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Tenants\PurgeTenant;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deletes the accounts whose thirty days are up (SPEC §29.3).
 *
 * Separate from `subscriptions:advance` on purpose, and that separation is
 * deliberate rather than tidiness. One command moves statuses and sends
 * emails; this one destroys data irreversibly. Sharing a command would mean a
 * bug in the cheap half could reach the expensive one, and a `--dry-run` on
 * the whole thing would be meaningless.
 *
 * It only ever touches accounts that asked. A tenant with no
 * `deletion_requested_at` is never a candidate however old or expired it is —
 * the twelve-month retention sweep is a different thing that does not exist
 * yet and will not live here either (§4.4).
 */
final class PurgeDeletedAccountsCommand extends Command
{
    protected $signature = 'tenants:purge
        {--dry-run : List what would go, and delete nothing}';

    protected $description = 'Permanently delete accounts whose deletion wait has run out';

    public function handle(TenantContext $context, PurgeTenant $purge): int
    {
        $due = $context->withoutScoping(static fn () => Tenant::query()
            ->whereNotNull('deletion_requested_at')
            ->whereNotNull('purge_at')
            ->where('purge_at', '<=', now())
            ->get());

        if ($due->isEmpty()) {
            $this->info('Nothing to purge.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($due as $tenant) {
            $this->line(sprintf(
                '%s (/reservation/%s) — requested %s',
                $tenant->name,
                $tenant->slug,
                $tenant->deletion_requested_at?->format('j M Y'),
            ));

            if ($this->option('dry-run')) {
                continue;
            }

            try {
                $context->withoutScoping(static fn () => $purge->handle($tenant));
            } catch (Throwable $exception) {
                $failed++;

                // Logged rather than thrown: one account that will not go
                // must not stop the others, and the next run tries again.
                Log::error('Could not purge an account.', [
                    'tenant' => $tenant->slug,
                    'error' => $exception->getMessage(),
                ]);

                $this->error('  failed: '.$exception->getMessage());
            }
        }

        $this->info(sprintf(
            '%s %d %s.',
            $this->option('dry-run') ? 'Would purge' : 'Purged',
            $due->count() - $failed,
            str('account')->plural($due->count() - $failed)->toString(),
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
