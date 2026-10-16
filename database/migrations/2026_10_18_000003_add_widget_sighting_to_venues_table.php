<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the widget was last seen working (SPEC §9.4).
 *
 * The diagnostic that settles the only argument this feature ever causes.
 * Somebody pastes the snippet, nothing appears, and the question is whether
 * it reached the page at all. Two columns answer it: the widget calls our own
 * endpoints from the visitor's browser, so those calls arrive with the
 * customer's domain in the Origin header, and the builder page can say
 * "working on example.gr, twenty minutes ago" instead of leaving an owner to
 * forward screenshots.
 *
 * Not personal data: the site's own domain, which is public, and a time. No
 * visitor, no address, no path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            $table->string('widget_last_origin')->nullable()->after('accent_color');
            $table->datetime('widget_last_seen_at')->nullable()->after('widget_last_origin');
        });
    }

    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            $table->dropColumn(['widget_last_origin', 'widget_last_seen_at']);
        });
    }
};
