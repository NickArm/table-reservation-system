<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            /*
             * When the platform was last told this hand-billed account is
             * about to run out.
             *
             * A card renews itself and a failure announces itself; an invoice
             * sent by a person does neither, so the only thing standing
             * between a paid-up customer and a locked booking page is
             * somebody remembering. This column is what stops the reminder
             * going out every night for a week — it is compared against the
             * period start, so the next period warns again on its own.
             */
            $table->datetime('manual_notice_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('manual_notice_sent_at');
        });
    }
};
