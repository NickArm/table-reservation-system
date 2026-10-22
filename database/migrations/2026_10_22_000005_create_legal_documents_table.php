<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A venue's own terms and cancellation policy (SPEC §12.1).
 *
 * Separate from `platform_documents` on purpose: a venue edits its own and
 * must never be able to touch ours. Same versioning rule for the same reason
 * — a guest agreed to a specific version at checkout, and the booking points
 * at it, so the words behind that version can never change afterwards.
 *
 * The difference from the platform side is who the user is. There, the
 * version number is typed by somebody who cares about version numbers. Here
 * it is an integer the application increments silently, because a taverna
 * owner editing their cancellation policy should not have to think about
 * versioning at all (§1.1) — they press Save, and the old version quietly
 * stays behind for the bookings that agreed to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('type', 32);

            // An integer, incremented by the application. Nobody types it.
            $table->unsignedInteger('version');

            $table->text('content');

            $table->datetime('published_at');
            $table->boolean('is_current')->default(false);

            $table->timestamps();

            $table->unique(['tenant_id', 'type', 'version']);
            $table->index(['tenant_id', 'type', 'is_current']);
        });

        Schema::table('reservations', function (Blueprint $table): void {
            /*
             * Which version of the venue's terms the guest ticked the box
             * for. The column was in the schema from the first migration and
             * has been null ever since, because there was nothing to point
             * at — there is now (§12.1).
             *
             * Deliberately *not* a foreign key with a cascade: a booking's
             * record of what was agreed must survive the venue tidying up its
             * documents, and `nullOnDelete` would erase the evidence at
             * exactly the moment somebody wanted it.
             */
            $table->index(['terms_version_id']);

            /*
             * The cancellation policy as the guest was shown it.
             *
             * `Voucher::cancellationPolicy()` has been reading the booking
             * type's live text while its own docblock claimed the opposite —
             * so a venue that edited its policy in August silently rewrote
             * every confirmation it had sent in July. The spec is explicit
             * that the voucher embeds the policy that applied (§12), and a
             * snapshot is the only way that holds: the same reasoning as
             * `deposit_label_snapshot` and `resource_snapshot` next to it.
             */
            $table->string('cancellation_policy_snapshot', 1000)
                ->nullable()
                ->after('deposit_label_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropIndex(['terms_version_id']);
            $table->dropColumn('cancellation_policy_snapshot');
        });

        Schema::dropIfExists('legal_documents');
    }
};
