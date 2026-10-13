<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Periods when something is not bookable: a public holiday, a private party,
 * wind, maintenance, or simply the end of the season (SPEC §6.2).
 *
 * A closure only ever looks forward (SPEC §24.5). It stops new bookings the
 * moment it is saved and never touches the ones already paid for — cancelling
 * those is a deliberate, human decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('closures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Polymorphic by hand rather than morphTo: the three scopes have
            // nothing else in common and an explicit column reads better in
            // the queries that matter.
            $table->string('scope');
            $table->unsignedBigInteger('scope_id');

            // Local dates, not timestamps. "Closed on Saturday" means the
            // venue's Saturday, whatever that is in UTC.
            $table->date('date_from');
            $table->date('date_to');

            // Null means the whole day. Set, and the closure covers only part
            // of it — "tonight only", "closed until 14:00".
            $table->time('time_from')->nullable();
            $table->time('time_to')->nullable();

            $table->string('reason')->nullable();

            // Set when the owner taps "stop bookings for today", so the
            // dashboard can offer to undo exactly that and nothing else.
            $table->boolean('is_quick_stop')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'scope', 'scope_id']);
            $table->index(['scope', 'scope_id', 'date_from', 'date_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('closures');
    }
};
