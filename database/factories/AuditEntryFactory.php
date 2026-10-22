<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AuditAction;
use App\Models\AuditEntry;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditEntry>
 */
class AuditEntryFactory extends Factory
{
    protected $model = AuditEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => null,
            'actor_name' => 'Platform Admin',
            'affected_tenant_id' => null,
            'affected_tenant_name' => null,
            'action' => AuditAction::TenantSuspended,
            'summary' => 'Account suspended.',
            'reason' => 'Chargeback dispute.',
            'context' => null,
            'ip' => '127.0.0.1',
            'occurred_at' => now(),
        ];
    }

    public function by(User $actor): static
    {
        return $this->state(fn (): array => [
            'actor_id' => $actor->getKey(),
            'actor_name' => $actor->name,
        ]);
    }

    public function about(Tenant $tenant): static
    {
        return $this->state(fn (): array => [
            'affected_tenant_id' => $tenant->getKey(),
            'affected_tenant_name' => $tenant->name,
        ]);
    }
}
