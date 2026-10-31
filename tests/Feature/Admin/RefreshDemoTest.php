<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rebuilding the demo venue on purpose (SPEC §22.2).
 *
 * The seeder refuses in production, and must: `db:seed` runs on every deploy
 * and cannot invent a venue on a live host. But a preview install is exactly
 * where a demo is wanted, and exactly where it needs resetting after somebody
 * has clicked through it — so there is one command that asks first.
 */
final class RefreshDemoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_deploy_path_still_refuses(): void
    {
        $this->app['env'] = 'production';
        Model::shouldBeStrict(false);

        $this->app->make(\Database\Seeders\DemoRestaurantSeeder::class)->run();

        Model::shouldBeStrict(true);

        $this->assertDatabaseCount('tenants', 0);
    }

    #[Test]
    public function the_command_asks_before_it_does_it_on_a_live_host(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('demo:refresh')
            ->expectsConfirmation('Rebuild the demo venue?', 'no')
            ->assertSuccessful();

        $this->assertDatabaseCount('tenants', 0);
    }

    #[Test]
    public function answering_yes_builds_it(): void
    {
        $this->app['env'] = 'production';
        Model::shouldBeStrict(false);

        $this->artisan('demo:refresh')
            ->expectsConfirmation('Rebuild the demo venue?', 'yes')
            ->assertSuccessful();

        Model::shouldBeStrict(true);

        $demo = Tenant::query()->where('slug', 'demo')->first();

        $this->assertNotNull($demo, 'The demo venue was not created.');
    }

    #[Test]
    public function the_demo_has_the_payments_its_reports_are_read_from(): void
    {
        /*
         * Reports counts what the gateway took, not what a booking asked for.
         * Without these rows the demo showed a full season and nothing
         * earned — which is the first thing anybody being shown the product
         * notices, and reads as the product not working.
         */
        Model::shouldBeStrict(false);

        $this->artisan('demo:refresh', ['--force' => true])->assertSuccessful();

        Model::shouldBeStrict(true);

        $this->assertGreaterThan(
            0,
            \Illuminate\Support\Facades\DB::table('payments')->where('status', 'paid')->count(),
            'The demo has bookings but no payments behind them.',
        );
    }
}
