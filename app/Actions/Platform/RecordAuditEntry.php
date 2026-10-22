<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Enums\AuditAction;
use App\Models\AuditEntry;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The only writer of the platform's audit log (SPEC §13.2).
 *
 * One door in, for the same reason as `RecordSubscriptionEvent`: the rule
 * about reasons is enforced here rather than remembered at nine call sites.
 * An unexplained suspension is indistinguishable from a mistake, and the
 * person who has to answer for it is whoever is reading the log afterwards.
 *
 * Names are copied in alongside the ids. A trail that forgets who did
 * something the moment their account is deleted, or what the venue was called
 * once it has been purged, is not a trail.
 */
final readonly class RecordAuditEntry
{
    public function __construct(private Request $request) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function handle(
        AuditAction $action,
        string $summary,
        ?User $actor = null,
        ?Tenant $tenant = null,
        ?string $reason = null,
        array $context = [],
    ): AuditEntry {
        if ($action->needsAReason() && trim((string) $reason) === '') {
            throw new InvalidArgumentException(
                'This action has to be recorded with a reason.'
            );
        }

        return AuditEntry::query()->forceCreate([
            'actor_id' => $actor?->getKey(),
            'actor_name' => $actor?->name,
            'affected_tenant_id' => $tenant?->getKey(),
            'affected_tenant_name' => $tenant?->name,
            'action' => $action,
            'summary' => $summary,
            'reason' => $reason,
            'context' => $context === [] ? null : $context,

            // The address the action came from, which is the only thing that
            // distinguishes two sessions of the same admin.
            'ip' => $this->request->ip(),
            'occurred_at' => now(),
        ]);
    }
}
