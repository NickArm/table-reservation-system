<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per public API call (SPEC §15.2.1).
 *
 * It answers the two questions an owner's developer actually asks when an
 * integration misbehaves: is it reaching us at all, and what are we saying
 * back. Without it the only available answer is "works here".
 *
 * Only calls whose token resolved to an account land here, the refused ones
 * included — a revoked token, a rate limit, a mode without the feature. A
 * bearer string matching nothing at all is answered 401 and written nowhere:
 * there is no account it could be shown to, and a table of anonymous
 * refusals collects junk rather than evidence. That also keeps the row
 * properly tenant-scoped, with no null-tenant special case for every later
 * query to remember.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_requests', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Null once a token is regenerated: the log of what that token
            // did stays, because the account's usage history should not have
            // a hole in it, but it no longer points at a row that is gone.
            $table->foreignId('api_token_id')->nullable()->constrained()->nullOnDelete();

            $table->string('method', 10);

            // The route pattern, not the filled-in path: 'api/v1/times'
            // rather than 'api/v1/times?date=…'. Grouping by endpoint is the
            // whole point, and a query string would also carry a party size
            // into a table that has no business holding anything but counts.
            $table->string('endpoint');

            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms')->default(0);

            $table->datetime('occurred_at');

            // Exactly the two shapes the Developer page reads: the account's
            // calls over a period, and the same split by endpoint.
            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['tenant_id', 'endpoint', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_requests');
    }
};
