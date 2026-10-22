<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Actions\Platform\RecordAuditEntry;
use App\Actions\Tenants\AcceptPlatformTerms;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\PlatformDocument;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Agreeing to a new version of the terms (SPEC §12.2).
 *
 * The banner stays until this is pressed, and pressing it records which
 * version, when and from where — the same three facts as the original
 * acceptance at sign-up, because a re-acceptance is an agreement in exactly
 * the same way.
 */
final class TermsAcceptanceController extends Controller
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        AcceptPlatformTerms $terms,
        RecordAuditEntry $audit,
    ): RedirectResponse {
        $tenant = $context->getOrFail();

        $accepted = $terms->handle($tenant, $request->ip());

        if ($accepted instanceof PlatformDocument) {
            $audit->handle(
                AuditAction::TermsAccepted,
                $tenant->name.' agreed to '.$accepted->type->title().' '.$accepted->version.'.',
                actor: $request->user(),
                tenant: $tenant,
                context: ['version' => $accepted->version, 'ip' => $request->ip()],
            );
        }

        return back()->with('status', 'Thank you. Nothing else changes.');
    }
}
