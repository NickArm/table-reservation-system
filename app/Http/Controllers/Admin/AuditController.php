<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Who did what, and why (SPEC §13.2).
 *
 * Read-only, and there is no screen anywhere that edits or deletes one of
 * these. A log with a delete button is a log nobody can rely on, including
 * the person who would have pressed it.
 */
final class AuditController extends Controller
{
    public function __invoke(Request $request): View
    {
        $action = AuditAction::tryFrom((string) $request->string('action'));
        $tenantId = $request->integer('tenant') ?: null;

        return view('admin.audit', [
            'entries' => AuditEntry::query()
                ->when($action !== null, fn ($query) => $query->where('action', $action->value))
                ->when($tenantId !== null, fn ($query) => $query->where('affected_tenant_id', $tenantId))
                ->newestFirst()
                ->paginate(60)
                ->withQueryString(),
            'action' => $action,
            'tenantId' => $tenantId,
            'tenants' => Tenant::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
