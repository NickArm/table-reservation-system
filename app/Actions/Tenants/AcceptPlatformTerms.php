<?php

declare(strict_types=1);

namespace App\Actions\Tenants;

use App\Enums\PlatformDocumentType;
use App\Models\PlatformDocument;
use App\Models\Tenant;

/**
 * Records that an account agreed to the current terms (SPEC §12.2).
 *
 * Three things are stored, and all three matter in a dispute: **which
 * version**, **when**, and **from where**. The version is the reason
 * `platform_documents` never edits a published row — pointing at "1.0" is
 * only meaningful while the words in 1.0 cannot change.
 *
 * With nothing published yet, nothing is recorded. An account cannot agree to
 * a document that does not exist, and writing a null version would be worse
 * than writing nothing: it would look like consent on every later report.
 */
final readonly class AcceptPlatformTerms
{
    public function handle(Tenant $tenant, ?string $ip = null): ?PlatformDocument
    {
        $terms = PlatformDocument::current(PlatformDocumentType::Terms);

        if (! $terms instanceof PlatformDocument) {
            return null;
        }

        $tenant->forceFill([
            'accepted_terms_version' => $terms->version,
            'accepted_terms_at' => now(),
            'accepted_terms_ip' => $ip,
        ])->save();

        return $terms;
    }

    /**
     * Does this account owe us an agreement to a newer version?
     *
     * Only when the newer version says so. A typo fix should not put a banner
     * in front of four hundred venues, and a banner that appears for every
     * edit is a banner nobody reads by the third time (§12.2).
     *
     * An account that has never accepted anything is **not** chased here: it
     * registered before anything was published, and the banner is for a
     * change, not for a gap. The gap closes on the next publish that asks for
     * re-acceptance.
     */
    public function outstandingFor(Tenant $tenant): ?PlatformDocument
    {
        $terms = PlatformDocument::current(PlatformDocumentType::Terms);

        if (! $terms instanceof PlatformDocument || ! $terms->needs_reacceptance) {
            return null;
        }

        $accepted = (string) $tenant->accepted_terms_version;

        if ($accepted === '' || $accepted === $terms->version) {
            return null;
        }

        return $terms;
    }
}
