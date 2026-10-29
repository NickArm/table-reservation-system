<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The first platform account on a live install (SPEC §2.1, §13.2).
 *
 * This account reads every venue on the platform. `db:seed` used to create it
 * with a fixed address and the password "password" — correct on a laptop, and
 * on a live host a published set of credentials to everything.
 */
final class CreateSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function seeding_a_live_install_does_not_hand_out_an_administrator(): void
    {
        /*
         * A live install still has to seed: the plans and the platform's legal
         * documents come from there. Before the guard, that same command also
         * created admin@tablereserved.test with a password published in the
         * repository.
         */
        $this->app['env'] = 'production';

        // Both halves of what production is: the environment, and the strict
        // mode that AppServiceProvider switches off there. Setting only the
        // first leaves a laptop's strictness on top of a live code path and
        // fails for a reason that cannot happen on the host.
        \Illuminate\Database\Eloquent\Model::shouldBeStrict(false);

        // Run the seeder itself rather than through the console, which would
        // stop on its own "this is production" confirmation and prove nothing
        // about what the seeder does once somebody answers yes.
        $this->app->make(\Database\Seeders\DatabaseSeeder::class)->run();

        \Illuminate\Database\Eloquent\Model::shouldBeStrict(true);

        $this->assertDatabaseMissing('users', ['email' => 'admin@tablereserved.test']);

        // And the things a live install does need are still there.
        $this->assertDatabaseCount('plans', 2);
    }

    #[Test]
    public function the_command_creates_one_with_a_password_somebody_chose(): void
    {
        $this->artisan('admin:create', ['--email' => 'nick@example.com', '--name' => 'Nick'])
            ->expectsQuestion('Password', 'k9Twilight-Harbour42')
            ->expectsQuestion('And again', 'k9Twilight-Harbour42')
            ->assertSuccessful();

        $admin = User::query()->where('email', 'nick@example.com')->firstOrFail();

        $this->assertTrue($admin->is_super_admin);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertNotSame('k9Twilight-Harbour42', $admin->password);
    }

    #[Test]
    public function a_weak_password_is_refused_rather_than_warned_about(): void
    {
        $this->artisan('admin:create', ['--email' => 'nick@example.com', '--name' => 'Nick'])
            ->expectsQuestion('Password', 'password')
            ->expectsQuestion('And again', 'password')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'nick@example.com']);
    }

    #[Test]
    public function a_typo_in_the_second_one_creates_nothing(): void
    {
        $this->artisan('admin:create', ['--email' => 'nick@example.com', '--name' => 'Nick'])
            ->expectsQuestion('Password', 'k9Twilight-Harbour42')
            ->expectsQuestion('And again', 'k9Twilight-Harbour43')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'nick@example.com']);
    }

    #[Test]
    public function an_existing_account_is_promoted_rather_than_refused(): void
    {
        // The common case: the owner account that should also run the
        // platform.
        User::forceCreate([
            'name' => 'Nick',
            'email' => 'nick@example.com',
            'password' => 'whatever-it-was',
            'email_verified_at' => now(),
        ]);

        $this->artisan('admin:create', ['--email' => 'nick@example.com', '--name' => 'Nick'])
            ->expectsQuestion('Password', 'k9Twilight-Harbour42')
            ->expectsQuestion('And again', 'k9Twilight-Harbour42')
            ->expectsConfirmation(
                'nick@example.com already exists. Make it an administrator and set this password?',
                'yes',
            )
            ->assertSuccessful();

        $this->assertTrue(User::query()->where('email', 'nick@example.com')->firstOrFail()->is_super_admin);
    }
}
