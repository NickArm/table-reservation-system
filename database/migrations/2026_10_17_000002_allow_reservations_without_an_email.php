<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A booking taken over the telephone may have no email address (SPEC §13.1).
 *
 * Online, the address is required — it is where the voucher goes. On the phone
 * it frequently does not exist: somebody rings, asks for a table at nine, and
 * has no intention of spelling an email down the line. Forcing one means staff
 * inventing `noemail@x.gr`, which is worse than a null: it pollutes the guest
 * list and bounces every confirmation.
 *
 * So the column becomes nullable, and everything that sends email already
 * checks for an address before trying.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->string('customer_email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->string('customer_email')->nullable(false)->change();
        });
    }
};
