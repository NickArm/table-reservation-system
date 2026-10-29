<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Do not add WithoutModelEvents here.
 *
 * It switches off every model event for the whole seeding run, and this
 * application depends on two of them: the hook that generates a tenant's uuid,
 * and the one in BelongsToTenant that stamps tenant_id on new records. With
 * events off, seeding fails on a NOT NULL constraint — or worse, writes rows
 * with no tenant at all.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        /*
         * 🔒 Never in production.
         *
         * This account reads every venue on the platform, every guest list
         * and every booking — and its address and password are in a public
         * repository. A live install has to run `php artisan db:seed` to get
         * the plans and the platform documents, and before this guard that
         * same command handed out a super admin with the password
         * "password". Use `php artisan admin:create` instead.
         */
        if (! app()->isProduction() && User::where('email', 'admin@tablereserved.test')->doesntExist()) {
            // forceCreate: is_super_admin and email_verified_at are not
            // fillable on purpose, and strict mode refuses to drop them.
            User::forceCreate([
                'name' => 'Platform Admin',
                'email' => 'admin@tablereserved.test',
                'password' => 'password',
                'is_super_admin' => true,
                'email_verified_at' => now(),
            ]);
        }

        /*
         * The placeholder legal texts, before anything else that might want
         * to accept them (SPEC §12.2). The real wording arrives from a
         * lawyer through the admin screen, without a deploy.
         */
        $this->call(PlatformDocumentSeeder::class);

        // Before the demo account, which subscribes to one of them.
        $this->call(PlanSeeder::class);

        $this->call(DemoRestaurantSeeder::class);
    }
}
