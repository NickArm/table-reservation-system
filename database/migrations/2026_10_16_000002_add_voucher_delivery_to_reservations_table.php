<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the confirmation actually arrived (SPEC §10.3).
 *
 * A booking whose email bounced is the one case where the venue has to pick up
 * the phone, and they can only do that if somebody tells them. The message id
 * is what lets a bounce notice from the mail provider find its way back to the
 * booking it belongs to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->timestamp('voucher_sent_at')->nullable()->after('terms_accepted_ip');
            $table->string('voucher_message_id')->nullable()->after('voucher_sent_at');
            $table->string('voucher_delivery', 20)->nullable()->after('voucher_message_id');

            $table->timestamp('reminder_sent_at')->nullable()->after('voucher_delivery');

            // The provider knows nothing about tenants, so a bounce arrives
            // with a message id and nothing else to go on.
            $table->index('voucher_message_id');

            // The reminder sweep runs every few minutes and asks exactly this.
            $table->index(['starts_at', 'reminder_sent_at']);
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropIndex(['voucher_message_id']);
            $table->dropIndex(['starts_at', 'reminder_sent_at']);

            $table->dropColumn([
                'voucher_sent_at',
                'voucher_message_id',
                'voucher_delivery',
                'reminder_sent_at',
            ]);
        });
    }
};
