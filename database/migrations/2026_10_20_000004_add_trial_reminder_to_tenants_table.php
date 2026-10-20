<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which trial reminder an account has already had (SPEC §4.2).
 *
 * Holds the days-remaining figure the last reminder was sent for, rather than
 * a boolean or a timestamp. A boolean cannot tell the three-days-left email
 * from the one-day-left one, and a timestamp means working out which of them
 * it was from the date — which goes wrong the moment a trial is extended by
 * hand. The number is the thing we actually want to know.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->unsignedSmallInteger('trial_reminder_sent_for')
                ->nullable()
                ->after('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('trial_reminder_sent_for');
        });
    }
};
