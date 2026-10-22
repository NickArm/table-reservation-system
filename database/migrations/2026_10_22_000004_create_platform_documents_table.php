<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's own legal texts (SPEC §12.2).
 *
 * The whole point of this table is that the texts arrive **without a deploy**.
 * They are being written by a lawyer who will send a Word document, probably
 * twice, probably after the first customer has already signed up — so the
 * infrastructure is built now and the content is pasted in later.
 *
 * Not tenant-scoped: these are ours. A venue's own terms and cancellation
 * policy are a different table (§12.1), because a venue edits its own and
 * must never be able to edit ours.
 *
 * **Versions are rows, never edits.** A published version is a record of what
 * somebody agreed to, and `tenants.accepted_terms_version` points at it by
 * name. Editing it in place would silently rewrite the agreement for every
 * account that accepted the old wording, which is the one thing a legal page
 * must not be able to do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_documents', function (Blueprint $table): void {
            $table->id();

            $table->string('type', 32);

            /*
             * A name the owner chooses ("1.0", "2026-10", "2.1"), not an
             * auto-incrementing number. It appears in the agreement a venue
             * accepted and in any dispute, so it has to be the string a human
             * would quote, and it has to stay stable.
             */
            $table->string('version', 32);

            // Markdown. A lawyer sends prose, and prose with headings is what
            // a legal page is; anything that needs real layout is a PDF and
            // not this table's problem.
            $table->text('content');

            /*
             * What changed, shown on the re-acceptance banner. Nobody reads a
             * diff of a terms page, but "we added a clause about seasonal
             * suspension" is read, and the law in most of the EU expects a
             * material change to be pointed out rather than buried.
             */
            $table->string('change_summary')->nullable();

            // Whether accounts have to agree again. A typo fix does not need
            // it; a new clause does. Deciding per version rather than per
            // document keeps the banner meaningful.
            $table->boolean('needs_reacceptance')->default(false);

            // Null means a draft: written, not published, not served.
            $table->datetime('published_at')->nullable();

            /*
             * Exactly one current version per type, enforced by the action
             * that publishes rather than by the database — MySQL has no
             * partial unique index, and a unique on (type, is_current) would
             * allow only one non-current row per type, which is the opposite
             * of what is wanted.
             */
            $table->boolean('is_current')->default(false);

            $table->timestamps();

            $table->unique(['type', 'version']);
            $table->index(['type', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_documents');
    }
};
