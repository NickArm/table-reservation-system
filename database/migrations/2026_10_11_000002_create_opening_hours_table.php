<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opening hours, at two scopes (SPEC §33.1).
 *
 * venue        — when the place is actually open. Feeds the public page and
 *                its structured data.
 * booking_type — when it takes bookings, which is usually narrower: a kitchen
 *                open until 01:00 may stop seating at 23:00.
 *
 * Confusing the two puts the wrong hours on Google, so they are kept apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_hours', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('scope');          // venue | booking_type
            $table->unsignedBigInteger('scope_id');

            $table->unsignedTinyInteger('day_of_week');   // 0 = Sunday
            $table->time('opens_at');
            $table->time('closes_at');

            // A bar closing at 02:00 belongs to the previous business day.
            $table->boolean('closes_next_day')->default(false);

            $table->timestamps();

            $table->index(['scope', 'scope_id', 'day_of_week'], 'opening_hours_scope_day_index');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_hours');
    }
};
