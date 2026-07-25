<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Convenience only: promotes an already-existing, locally-configured user
 * to the Administrator role so a developer has something to sign in with
 * on a fresh local database. Never creates a user, never sets or invents
 * a password or credential of any kind - it only assigns a role to an
 * account that already exists.
 *
 * Restricted to local/testing by an explicit environment check that runs
 * before anything else, not by relying on this seeder simply not being
 * called in production - a production run of this class is a safe no-op.
 */
class LocalStaffSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->info('LocalStaffSeeder: skipped (environment is not local/testing).');

            return;
        }

        $email = config('staff.local_admin_email');

        if (! is_string($email) || trim($email) === '') {
            $this->command?->info('LocalStaffSeeder: skipped (LOCAL_STAFF_ADMIN_EMAIL is not set).');

            return;
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->command?->info("LocalStaffSeeder: skipped (no user found with email {$email}).");

            return;
        }

        if (! $user->hasRole('administrator')) {
            $user->assignRole('administrator');
        }

        $this->command?->info("LocalStaffSeeder: assigned administrator to {$email}.");
    }
}
