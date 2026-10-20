<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened to a subscription, and who did it (SPEC §4.1, §4.3).
 *
 * Two readers, which is why it is one table rather than two. The owner sees
 * it as their billing history — renewed, plan changed, payment failed. The
 * platform needs it as an audit trail, because the super admin can push a
 * period end, grant free months, or mark a subscription paid from a bank
 * deposit, and every one of those has to be answerable later with a reason
 * and a name against it.
 *
 * Append-only by intent: nothing here is ever edited or deleted. A history
 * that can be tidied up is not a history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_events', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();

            // Kept even when the plan row is gone, so an old line still reads
            // correctly rather than showing a blank.
            $table->string('plan_name')->nullable();

            $table->string('type', 40);

            // What the owner is shown. Written at the time, because next
            // year's wording for this event is not what happened this year.
            $table->string('summary');

            // Only for something a person did by hand. Required there: an
            // unexplained free month is indistinguishable from a mistake.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();

            $table->integer('amount_cents')->nullable();
            $table->string('currency', 3)->nullable();

            $table->datetime('occurred_at');

            $table->index(['tenant_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_events');
    }
};
