<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who hears about a booking, and when (SPEC §10.2).
 *
 * All of it optional with sensible defaults, because a venue that never opens
 * this screen should still be told about its bookings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            // Extra addresses beyond the account owner: a shared inbox, the
            // manager, the beach kiosk. Empty means the owner alone.
            $table->json('notification_emails')->nullable()->after('public_email');

            $table->boolean('notify_on_booking')->default(true)->after('notification_emails');

            // The hour, in the venue's own time, that the morning list arrives.
            // Null switches it off.
            $table->unsignedTinyInteger('daily_summary_hour')->nullable()->default(8)
                ->after('notify_on_booking');

            // Alerts that would land in the middle of the night wait until
            // morning instead. Nothing is dropped, only delayed.
            $table->time('quiet_hours_from')->nullable()->after('daily_summary_hour');
            $table->time('quiet_hours_to')->nullable()->after('quiet_hours_from');

            // How long before their table a guest is reminded. Null means no
            // reminder at all.
            $table->unsignedSmallInteger('reminder_hours_before')->nullable()->default(24)
                ->after('quiet_hours_to');
        });
    }

    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            $table->dropColumn([
                'notification_emails',
                'notify_on_booking',
                'daily_summary_hour',
                'quiet_hours_from',
                'quiet_hours_to',
                'reminder_hours_before',
            ]);
        });
    }
};
