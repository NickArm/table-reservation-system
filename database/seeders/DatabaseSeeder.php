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
        if (User::where('email', 'admin@tablereserved.test')->doesntExist()) {
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

        $this->call(DemoRestaurantSeeder::class);
    }
}
