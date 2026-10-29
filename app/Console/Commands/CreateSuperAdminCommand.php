<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * The first platform account on a live install (SPEC §2.1, §13.2).
 *
 * `db:seed` used to make this one, with a fixed address and the password
 * "password". That is right on a laptop and indefensible in production: the
 * account it creates reads every venue on the platform, every guest list and
 * every booking, and the credentials are in a public repository. The seeder
 * now refuses, and this is what replaces it.
 *
 * Asked for rather than passed on the command line by default, because a
 * password in an argument is a password in the shell history and in the
 * process list of a shared host.
 */
final class CreateSuperAdminCommand extends Command
{
    protected $signature = 'admin:create
                            {--email= : The address to sign in with}
                            {--name= : A display name}';

    protected $description = 'Create a platform administrator';

    public function handle(): int
    {
        $email = (string) ($this->option('email') ?: $this->ask('Email'));
        $name = (string) ($this->option('name') ?: $this->ask('Name', 'Platform Admin'));
        $password = (string) $this->secret('Password');
        $again = (string) $this->secret('And again');

        if ($password !== $again) {
            $this->error('Those did not match.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password],
            [
                'email' => ['required', 'email', 'max:255'],
                'name' => ['required', 'string', 'max:255'],
                // Laravel's defaults, plus the breach list: this account is
                // worth more than any single venue's.
                'password' => ['required', Password::min(12)->letters()->numbers()->uncompromised()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            // Promoting rather than refusing: the common case is an owner
            // account that should also run the platform.
            if (! $this->confirm("{$email} already exists. Make it an administrator and set this password?")) {
                return self::FAILURE;
            }

            $existing->forceFill([
                'password' => $password,
                'is_super_admin' => true,
                'email_verified_at' => $existing->email_verified_at ?? now(),
            ])->save();

            $this->info("{$email} is now a platform administrator.");

            return self::SUCCESS;
        }

        // forceCreate: is_super_admin and email_verified_at are not fillable
        // on purpose, and strict mode refuses to drop them silently.
        User::forceCreate([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_super_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->info("Created {$email}. Sign in at /admin.");

        return self::SUCCESS;
    }
}
