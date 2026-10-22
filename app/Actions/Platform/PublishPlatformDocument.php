<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Enums\AuditAction;
use App\Enums\PlatformDocumentType;
use App\Models\PlatformDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Putting a legal text live (SPEC §12.2).
 *
 * Every publish writes a **new row**. The previous version is kept, not
 * overwritten, because `tenants.accepted_terms_version` points at it by name
 * and an account that agreed to version 1.0 agreed to the words that were in
 * version 1.0. Editing those words afterwards would quietly rewrite an
 * agreement somebody already made, which is the one thing this table exists
 * to prevent.
 *
 * A draft can be edited freely — nobody has agreed to it and nobody is served
 * it. Once published it is frozen.
 */
final readonly class PublishPlatformDocument
{
    public function __construct(private RecordAuditEntry $audit) {}

    public function handle(
        PlatformDocumentType $type,
        string $version,
        string $content,
        User $actor,
        ?string $changeSummary = null,
        bool $needsReacceptance = false,
        bool $publish = true,
    ): PlatformDocument {
        return DB::transaction(function () use (
            $type,
            $version,
            $content,
            $actor,
            $changeSummary,
            $needsReacceptance,
            $publish,
        ): PlatformDocument {
            $existing = PlatformDocument::query()
                ->where('type', $type->value)
                ->where('version', $version)
                ->first();

            /*
             * A published version is frozen. Saving the same version number
             * again after it went live would be the in-place edit this whole
             * design exists to avoid, so it is refused by keeping the stored
             * content and only moving the flags — the caller is told to pick
             * a new version number.
             */
            if ($existing instanceof PlatformDocument && ! $existing->isDraft()) {
                return $existing;
            }

            $document = $existing ?? new PlatformDocument;

            $document->forceFill([
                'type' => $type,
                'version' => $version,
                'content' => $content,
                'change_summary' => $changeSummary,
                'needs_reacceptance' => $needsReacceptance,
            ]);

            if (! $publish) {
                $document->forceFill([
                    'published_at' => null,
                    'is_current' => false,
                ])->save();

                return $document;
            }

            // Exactly one current version per type, cleared before the new
            // one is set rather than after: for the instant in between, a
            // reader should find nothing rather than two.
            PlatformDocument::query()
                ->where('type', $type->value)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $document->forceFill([
                'published_at' => now(),
                'is_current' => true,
            ])->save();

            $this->audit->handle(
                AuditAction::LegalDocumentPublished,
                $type->title().' '.$version.' published'
                    .($needsReacceptance ? ', and venues have to agree again.' : '.'),
                actor: $actor,
                context: [
                    'type' => $type->value,
                    'version' => $version,
                    'needs_reacceptance' => $needsReacceptance,
                ],
            );

            return $document;
        });
    }
}
