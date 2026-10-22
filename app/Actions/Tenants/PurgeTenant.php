<?php

declare(strict_types=1);

namespace App\Actions\Tenants;

use App\Actions\Platform\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Models\ReservedSlug;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Thirty days are up: the account goes (SPEC §29.3).
 *
 * The order is the whole design.
 *
 * **The slug is reserved before the row is deleted.** Afterwards there is
 * nothing to read it from, and a slug that becomes available again is a QR
 * code on a sign pointing a tourist at a stranger's booking page. Its own
 * table, because `uniqueSlug()` can only see soft-deleted tenants and those
 * are gone by this point.
 *
 * **The audit line is written before the row goes too**, for the same reason:
 * it copies the account's name, and after the delete there is no name to
 * copy. The platform's record of having deleted something has to outlive the
 * thing.
 *
 * **Then the row, which takes everything with it.** Every `tenant_id` in the
 * schema is a foreign key with `cascadeOnDelete`, so one `forceDelete` on the
 * tenant removes bookings, guests, resources, documents and settings in one
 * statement that the database guarantees. Deleting table by table in PHP
 * would be a list to keep in step with every future migration.
 *
 * **Files last, and failures there do not stop it.** Object storage is a
 * different system that can be having a bad afternoon, and a tenant left
 * half-deleted in the database because a bucket timed out is worse than an
 * orphaned folder — which is recoverable, and logged.
 *
 * What is **not** deleted: our own subscription invoices. Those are our
 * accounting records, not the venue's data, and tax law says how long we keep
 * them (§29.3).
 */
final readonly class PurgeTenant
{
    public function __construct(private RecordAuditEntry $audit) {}

    public function handle(Tenant $tenant): void
    {
        $slug = (string) $tenant->slug;
        $name = (string) $tenant->name;
        $uuid = (string) $tenant->uuid;

        DB::transaction(function () use ($tenant, $slug, $name): void {
            ReservedSlug::reserve($slug, $name);

            $this->audit->handle(
                AuditAction::AccountPurged,
                $name.' was purged. The address /reservation/'.$slug
                    .' is reserved and will never be reused.',
                tenant: $tenant,
                context: ['slug' => $slug, 'uuid' => $tenant->uuid],
            );

            // One statement, and the database guarantees the rest.
            $tenant->forceDelete();
        });

        $this->deleteFiles($uuid);
    }

    /**
     * The account's folder in object storage (SPEC §30.1).
     */
    private function deleteFiles(string $uuid): void
    {
        if ($uuid === '') {
            return;
        }

        try {
            Storage::disk((string) config('media.disk'))
                ->deleteDirectory('tenants/'.$uuid);
        } catch (Throwable $exception) {
            Log::error('Could not delete a purged account\'s files.', [
                'tenant_uuid' => $uuid,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
