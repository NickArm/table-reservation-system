<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "A table on Saturday costs more than a table on Tuesday afternoon"
 * (SPEC §7.2).
 *
 * Entirely optional. A venue with one price never creates a row here, and the
 * dashboard keeps the whole idea hidden until somebody asks for it (§1.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('scope');
            $table->unsignedBigInteger('scope_id');

            $table->string('name')->nullable();

            // Null means every day. Stored as a list of 0–6, Sunday first,
            // the same numbering the opening hours use.
            $table->json('days_of_week')->nullable();

            // A season: July and August, or the week of a festival.
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();

            // Part of a day: "Tuesday afternoon" is a time range, not a day.
            $table->time('time_from')->nullable();
            $table->time('time_to')->nullable();

            $table->string('price_type')->default('fixed');

            // Two columns rather than one `value`, because cents and percent
            // are different units and a single column invites the bug where
            // 25 means a quarter in one row and 25 cents in the next.
            $table->unsignedInteger('amount_cents')->nullable();
            $table->smallInteger('percent')->nullable();

            // Highest wins. Ties break towards the narrower scope, so a rule
            // on one sunbed beats a rule on the whole beach.
            $table->unsignedSmallInteger('priority')->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['tenant_id', 'scope', 'scope_id', 'is_active']);
            $table->index(['scope', 'scope_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_rules');
    }
};
