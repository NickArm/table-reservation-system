<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\User;

/**
 * Signs in as a platform super admin.
 *
 * Deliberately separate from `ActsAsVenueOwner`: a super admin belongs to no
 * account at all, and a test that gave them one would be testing a state the
 * application does not have.
 */
trait ActsAsPlatformAdmin
{
    protected User $admin;

    protected function signInAsPlatformAdmin(string $name = 'Platform Admin'): User
    {
        // forceCreate: `is_super_admin` and `email_verified_at` are not
        // fillable on purpose, and strict mode refuses to drop them.
        $admin = User::forceCreate([
            'name' => $name,
            'email' => 'admin+'.uniqid().'@tablereserved.test',
            'password' => 'correct-horse-battery',
            'is_super_admin' => true,
            'email_verified_at' => now(),
        ]);

        /*
         * Read back, not used as created. A model that came out of
         * `forceCreate` holds only the attributes that were passed, and
         * strict mode throws on anything else — so the first call to
         * `auth()->logout()`, which touches `remember_token`, fails on a
         * model that has never seen that column.
         */
        $this->admin = $admin->refresh();

        $this->actingAs($this->admin);

        return $this->admin;
    }
}
