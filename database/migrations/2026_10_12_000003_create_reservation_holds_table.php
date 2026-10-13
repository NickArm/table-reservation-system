<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A spot kept aside while someone is at the payment screen (SPEC §6.4, §24.1).
 *
 * Fifteen minutes, the more.com model: long enough to type a card number,
 * short enough that a sunbed is not lost for an afternoon because a tab was
 * left open.
 *
 * Deliberately its own table rather than a reservation status. A hold has no
 * customer and no money yet, it is deleted rather than kept for history, and
 * the cleanup job should never be one `WHERE status` typo away from touching
 * real bookings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_holds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();

            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedSmallInteger('party_size')->default(1);

            // Identifies the browser session that owns this hold, so the same
            // guest stepping back and forth keeps their own spot instead of
            // competing with themselves.
            $table->string('session_token', 64)->index();

            $table->timestamp('expires_at');

            $table->timestamps();

            $table->index(['resource_id', 'starts_at', 'ends_at']);

            // A second line of defence under the row lock: even if two
            // requests somehow both believed a spot was free, the database
            // refuses the second. Expired holds are deleted inside the same
            // transaction that takes a new one, so this never blocks a
            // legitimate retry.
            $table->unique(['resource_id', 'starts_at']);

            // The cleanup job's only query, and the lazy filter every
            // availability check adds (SPEC §21.2).
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_holds');
    }
};
