<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who did what, and why (SPEC §13.2, §2.1).
 *
 * The platform's own record, not a venue's. `subscription_events` is the
 * history an owner reads on their billing page; this is the one the platform
 * answers for — suspensions, impersonations, free months, plans edited — and
 * the two are deliberately separate. An owner should not have to read our
 * internal notes to understand their bill, and we should not lose our record
 * of a suspension when an account is deleted.
 *
 * Deliberately **not** `tenant_id`, for the same reason as `plans`: a column
 * by that name means "belongs to this account and no query crosses the line",
 * and these rows belong to the platform. `affected_tenant_id` says what it
 * actually is — which account the action was about — and keeps the isolation
 * test honest rather than requiring a trait that would hide every row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table): void {
            $table->id();

            /*
             * Nulled rather than deleted when the admin's user row goes, and
             * the name is kept alongside: a trail that forgets who did
             * something as soon as they leave is not a trail. The same
             * reasoning as `plan_name` on subscription_events.
             */
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();

            $table->foreignId('affected_tenant_id')
                ->nullable()
                ->constrained('tenants')
                ->nullOnDelete();

            // Kept as text alongside the id, so a line still reads after the
            // account it concerns has been purged (§29).
            $table->string('affected_tenant_name')->nullable();

            $table->string('action', 64);
            $table->string('summary');
            $table->text('reason')->nullable();

            // Whatever the action needs to be understood later: the old and
            // new period end, the plan it moved to, the fields that changed.
            $table->json('context')->nullable();

            $table->string('ip', 45)->nullable();

            $table->datetime('occurred_at');
            $table->timestamps();

            // The log is read newest first, and filtered by account or by
            // action. Nothing else.
            $table->index(['occurred_at']);
            $table->index(['affected_tenant_id', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
    }
};
