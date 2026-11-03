<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            /*
             * The first question on a support call is "have you ever managed
             * to get in?", and until now nothing in the system could answer
             * it. Written on sign-in and read on the account screen.
             */
            $table->datetime('last_login_at')->nullable();

            /*
             * An account opened by hand has an owner who has never chosen a
             * password, so there is nothing to send them except a link that
             * lets them choose one.
             *
             * Deliberately not the password-reset token: that one is minted
             * by a broker that expires it in an hour, which is right for "I
             * forgot" and useless for an invitation that gets read the next
             * morning. This one is single-use, cleared the moment it is
             * spent, and its own lifetime is `tenancy.invitation_days`.
             */
            $table->string('invitation_token', 64)->nullable()->unique();
            $table->datetime('invitation_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['last_login_at', 'invitation_token', 'invitation_sent_at']);
        });
    }
};
