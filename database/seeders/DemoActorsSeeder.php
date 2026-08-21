<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Database\Seeder;
use RuntimeException;

class DemoActorsSeeder extends Seeder
{
    public function run(): void
    {
        $password = config('simulation.demo_account_password');

        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('DEMO_ACCOUNT_PASSWORD must be set to at least 12 characters when seeding.');
        }

        $actors = [
            [
                'email' => 'registrar.demo@example.invalid',
                'name' => 'Demo Registrar',
                'role' => RoleCapabilityMatrix::ROLE_REGISTRAR,
                'is_system_administrator' => false,
            ],
            [
                'email' => 'nurse.demo@example.invalid',
                'name' => 'Demo Nurse',
                'role' => RoleCapabilityMatrix::ROLE_NURSE,
                'is_system_administrator' => false,
            ],
            [
                'email' => 'physician.demo@example.invalid',
                'name' => 'Demo Physician',
                'role' => RoleCapabilityMatrix::ROLE_PHYSICIAN,
                'is_system_administrator' => false,
            ],
            [
                'email' => 'rmik.demo@example.invalid',
                'name' => 'Demo RMIK',
                'role' => RoleCapabilityMatrix::ROLE_RMIK,
                'is_system_administrator' => false,
            ],
            [
                'email' => config('simulation.rebuild_admin_email'),
                'name' => 'Rebuild Admin',
                'role' => RoleCapabilityMatrix::ROLE_ADMIN,
                'is_system_administrator' => true,
            ],
        ];

        foreach ($actors as $actor) {
            $user = User::query()->updateOrCreate(
                ['email' => $actor['email']],
                [
                    'name' => $actor['name'],
                    'password' => $password,
                    'status' => 'ACTIVE',
                    'is_system_administrator' => $actor['is_system_administrator'],
                    'email_verified_at' => now(),
                ],
            );

            $role = Role::query()->where('slug', $actor['role'])->firstOrFail();
            $user->roles()->sync([$role->id]);
        }
    }
}
