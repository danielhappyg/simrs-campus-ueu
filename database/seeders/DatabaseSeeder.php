<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (! config('simulation.demo_seed_enabled')) {
            return;
        }

        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Demo fixtures require SIMULATION mode with synthetic-only data enforced.');
        }

        $password = config('simulation.demo_account_password');

        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('DEMO_ACCOUNT_PASSWORD must be set to at least 12 characters when seeding.');
        }

        User::query()->updateOrCreate(
            ['email' => config('simulation.rebuild_admin_email')],
            [
                'name' => 'Rebuild Admin',
                'password' => $password,
                'status' => 'ACTIVE',
                'is_system_administrator' => true,
                'email_verified_at' => now(),
            ],
        );
    }
}
