<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which dunning email has already gone out (SPEC §4.3).
 *
 * The day mark, not a date: 1, 3 or 7 days after the payment failed. Stored
 * this way for the same reason as `trial_reminder_sent_for` — a nightly sweep
 * that compares dates sends nothing at all when it misses a day, while a mark
 * simply catches up on the next run.
 *
 * Cleared the moment a payment goes through, so a card that fails again next
 * year is chased again from the beginning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->unsignedTinyInteger('dunning_sent_for')
                ->nullable()
                ->after('payment_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('dunning_sent_for');
        });
    }
};
