<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditAction;
use Database\Factories\AuditEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the platform's own record (SPEC §13.2).
 *
 * Append-only by design: there is no action anywhere in the application that
 * updates or deletes one of these, and nothing is `$fillable`, so a row can
 * only be written through `RecordAuditEntry`. A log that can be edited from a
 * screen is a log nobody can rely on.
 *
 * Not tenant-scoped — see the migration. `affected_tenant_id` records which
 * account an action was about, not who owns the row.
 */
class AuditEntry extends Model
{
    /** @use HasFactory<AuditEntryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'context' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function affectedTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'affected_tenant_id');
    }

    /**
     * @param  Builder<AuditEntry>  $query
     * @return Builder<AuditEntry>
     */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('occurred_at')->orderByDesc('id');
    }

    /**
     * Who did it, as the log should read it.
     *
     * The stored name rather than the relation, so a line still says who
     * after that account has gone.
     */
    public function who(): string
    {
        return (string) ($this->actor_name ?? 'The system');
    }
}
