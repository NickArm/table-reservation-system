<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stops one email reserving the whole front row and sitting on it
 * (SPEC §24.6).
 *
 * Configurable because the right number differs: a beach bar may happily let
 * a family take four sets, while a small taverna wants one table per person
 * per night. Two is the default — enough for a couple booking a table and
 * sunbeds, not enough to corner a beach.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_types', function (Blueprint $table): void {
            $table->unsignedSmallInteger('max_per_email_24h')->default(2)->after('max_advance_days');
        });
    }

    public function down(): void
    {
        Schema::table('booking_types', function (Blueprint $table): void {
            $table->dropColumn('max_per_email_24h');
        });
    }
};
