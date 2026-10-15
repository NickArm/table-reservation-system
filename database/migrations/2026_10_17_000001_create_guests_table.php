<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The venue's own list of everyone who ever booked (SPEC §28).
 *
 * The unique key is the whole design: `(venue_id, email)`, never
 * `(tenant_id, email)`. The same person booking at a beach bar one summer and
 * at a restaurant the next is two records, even when both belong to the same
 * account. Each venue sees only its own relationship with that person, and
 * there is no platform-wide customer database — the list belongs to the venue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();

            // Nullable because anonymising a guest clears it, which also
            // releases the unique key below — both MySQL and SQLite allow
            // repeated NULLs in a unique index (SPEC §28.5).
            $table->string('email')->nullable();

            // The most recent they gave. People change phones and spell their
            // own names differently on different days.
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('locale', 5)->default('en');

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            // Denormalised, and recomputed from the reservations rather than
            // incremented — see RecountGuest. A counter that is only ever
            // added to drifts, and nobody notices until an owner argues with
            // it (SPEC §28.2).
            $table->unsignedInteger('bookings_count')->default(0);
            $table->unsignedInteger('completed_count')->default(0);
            $table->unsignedInteger('cancelled_count')->default(0);

            // The number an owner looks at hardest. Somebody who has stood the
            // venue up twice deserves a thought before the front row on a full
            // Saturday.
            $table->unsignedInteger('no_show_count')->default(0);

            $table->unsignedBigInteger('total_spent_cents')->default(0);

            // Only ever set from the separate, unticked box at checkout
            // (SPEC §28.4). The list is for serving guests, not mailing them.
            $table->boolean('marketing_consent')->default(false);
            $table->timestamp('marketing_consent_at')->nullable();

            // The venue's private notes. Never shown to the guest, never in
            // any email, never returned by the public API.
            $table->text('internal_notes')->nullable();
            $table->json('tags')->nullable();

            $table->timestamp('anonymized_at')->nullable();

            $table->timestamps();

            $table->unique(['venue_id', 'email']);

            // The guests screen sorts by last visit and filters on no-shows.
            $table->index(['venue_id', 'last_seen_at']);
            $table->index(['venue_id', 'no_show_count']);
            $table->index('phone');
        });

        Schema::table('reservations', function (Blueprint $table): void {
            // Nullable: a booking exists on its own, and an anonymised guest
            // leaves its reservations behind with the personal fields cleared.
            $table->foreignId('guest_id')->nullable()->after('venue_id')
                ->constrained()->nullOnDelete();

            $table->index(['guest_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropIndex(['guest_id', 'starts_at']);
            $table->dropConstrainedForeignId('guest_id');
        });

        Schema::dropIfExists('guests');
    }
};
