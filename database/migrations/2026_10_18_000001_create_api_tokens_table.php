<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One bearer token per account per mode (SPEC §15.2).
 *
 * Two columns hold the same secret for two different jobs. `token_hash` is a
 * plain SHA-256 of the value, which is what an incoming request is looked up
 * by: one indexed lookup, no decrypting, nothing to compare in a loop.
 * `token` is the same value encrypted, because the Developer page has to be
 * able to show it again — an owner who pasted it into their server last month
 * and is now setting up a staging copy should not have to regenerate and
 * break the live integration to read it (§15.2).
 *
 * `last_origin` and `exposed_at` are the leak check. A server-to-server call
 * has no Origin header; a browser always sends one. So a token that arrives
 * with an Origin has been put into someone's JavaScript, where anyone can
 * read it, and the dashboard says so by name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // 'test' or 'live'. A developer wiring up a form works against
            // test until it behaves, and never touches real bookings.
            $table->string('mode', 10)->default('live');

            // Encrypted. Never logged, never in a response body except the
            // Developer page's own reveal, scrubbed before Sentry.
            $table->text('token');

            // Indexed lookup for authentication.
            $table->string('token_hash', 64)->unique();

            // "tr_live_8f2a" — enough to tell two tokens apart in a list or
            // an audit line without printing the secret.
            $table->string('prefix', 20);

            $table->datetime('last_used_at')->nullable();

            // The last browser origin seen on a call, and when we first
            // decided the token was exposed. Cleared on regenerate.
            $table->string('last_origin')->nullable();
            $table->datetime('exposed_at')->nullable();

            $table->datetime('revoked_at')->nullable();

            $table->timestamps();

            // One live and one test token per account, and no more. Revoked
            // rows are deleted rather than kept, so this stays true: an old
            // token has no reason to exist once it stops working.
            $table->unique(['tenant_id', 'mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
