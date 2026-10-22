<?php

declare(strict_types=1);

namespace App\Actions\Legal;

use App\Enums\VenueDocumentType;
use App\Models\LegalDocument;
use Illuminate\Support\Facades\DB;

/**
 * A venue saves its terms or its cancellation policy (SPEC §12.1).
 *
 * Saving publishes. There is no draft and no publish button, which is the
 * opposite of the platform side and right for the opposite reason: there, the
 * author is somebody waiting on a lawyer and reading the text twice; here it
 * is a taverna owner who has just corrected a sentence and expects it to be
 * corrected (§1.1). A two-step flow would leave venues with an unpublished
 * policy they believed was live.
 *
 * What it does keep from the platform side is the thing that matters: **a
 * version is never edited**. Saving writes a new row and retires the previous
 * one, because a booking points at the version its guest agreed to, and
 * rewriting that version would quietly change what was agreed. The owner
 * never sees this — they press Save, and a version number they never asked
 * for goes up by one.
 *
 * Saving the same text twice writes nothing. Somebody who opens the screen,
 * reads it and presses Save should not generate a version.
 */
final readonly class SaveVenueDocument
{
    public function handle(VenueDocumentType $type, string $content): LegalDocument
    {
        return DB::transaction(function () use ($type, $content): LegalDocument {
            $current = LegalDocument::current($type);

            if ($current instanceof LegalDocument && $current->content === trim($content)) {
                return $current;
            }

            $next = 1 + (int) LegalDocument::query()
                ->where('type', $type->value)
                ->max('version');

            LegalDocument::query()
                ->where('type', $type->value)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $document = new LegalDocument;

            $document->forceFill([
                'type' => $type,
                'version' => $next,
                'content' => trim($content),
                'published_at' => now(),
                'is_current' => true,
            ]);

            // Saved through the model rather than the query builder so that
            // `BelongsToTenant` stamps the account. A direct insert here
            // would write a row belonging to nobody.
            $document->save();

            return $document;
        });
    }
}
