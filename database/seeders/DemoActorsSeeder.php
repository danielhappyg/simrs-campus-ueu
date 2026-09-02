<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Authorization\TeachingRoleAccessManager;
use App\Support\Database\SchemaQualifier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoActorsSeeder extends Seeder
{
    public function run(): void
    {
        if (! config('simulation.demo_seed_enabled')
            || config('simulation.mode') !== 'SIMULATION'
            || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Demo actors require enabled demo seeding in synthetic-only SIMULATION mode.');
        }

        $password = config('simulation.demo_account_password');

        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('DEMO_ACCOUNT_PASSWORD must be set to at least 12 characters when seeding.');
        }

        $actors = [
            [
                'email' => 'registrar.demo@example.invalid',
                'name' => 'Demo Registrar',
                'roles' => [RoleCapabilityMatrix::ROLE_REGISTRAR],
                'is_system_administrator' => false,
                'status' => 'DISABLED',
            ],
            [
                'email' => 'nurse.demo@example.invalid',
                'name' => 'Demo Nurse',
                'roles' => [RoleCapabilityMatrix::ROLE_NURSE],
                'is_system_administrator' => false,
                'status' => 'DISABLED',
            ],
            [
                'email' => 'physician.demo@example.invalid',
                'name' => 'Demo Physician',
                'roles' => [RoleCapabilityMatrix::ROLE_PHYSICIAN],
                'is_system_administrator' => false,
                'status' => 'DISABLED',
            ],
            [
                'email' => 'rmik.demo@example.invalid',
                'name' => 'Demo RMIK',
                'roles' => [RoleCapabilityMatrix::ROLE_RMIK],
                'is_system_administrator' => false,
                'status' => 'DISABLED',
            ],
            [
                'email' => 'admin.demo@example.invalid',
                'name' => 'Demo Administrator',
                'roles' => [RoleCapabilityMatrix::ROLE_ADMIN],
                'is_system_administrator' => false,
                'status' => 'DISABLED',
            ],
            [
                'email' => 'radiology.technologist.demo@example.invalid',
                'name' => 'Demo Radiology Technologist',
                'roles' => [RoleCapabilityMatrix::ROLE_RADIOLOGY_TECHNOLOGIST],
                'is_system_administrator' => false,
                'status' => 'DISABLED',
            ],
            [
                'email' => 'radiologist.demo@example.invalid',
                'name' => 'Demo Radiologist',
                'roles' => [RoleCapabilityMatrix::ROLE_RADIOLOGIST],
                'is_system_administrator' => false,
                'status' => 'DISABLED',
            ],
            [
                'email' => 'laboratory.technologist.demo@example.invalid',
                'name' => 'Demo Laboratory Technologist',
                'roles' => [RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST],
                'is_system_administrator' => false,
                'status' => 'DISABLED',
            ],
            [
                'email' => 'laboratory.verifier.demo@example.invalid',
                'name' => 'Demo Laboratory Verifier',
                'roles' => [RoleCapabilityMatrix::ROLE_LABORATORY_VERIFIER],
                'is_system_administrator' => false,
                'status' => 'DISABLED',
            ],
            [
                'email' => 'pharmacist.demo@example.invalid',
                'name' => 'Demo Pharmacist',
                'roles' => [RoleCapabilityMatrix::ROLE_PHARMACIST],
                'is_system_administrator' => false,
                'status' => 'DISABLED',
            ],
            [
                'email' => 'pharmacy.technician.demo@example.invalid',
                'name' => 'Demo Pharmacy Technician',
                'roles' => [RoleCapabilityMatrix::ROLE_PHARMACY_TECHNICIAN],
                'is_system_administrator' => false,
                'status' => 'DISABLED',
            ],
            [
                'email' => 'pharmacy.inventory.demo@example.invalid',
                'name' => 'Demo Pharmacy Inventory Controller',
                'roles' => [RoleCapabilityMatrix::ROLE_PHARMACY_INVENTORY_CONTROLLER],
                'is_system_administrator' => false,
                'status' => 'DISABLED',
            ],
            [
                'email' => 'cashier.demo@example.invalid',
                'name' => 'Demo Kasir',
                'roles' => [RoleCapabilityMatrix::ROLE_CASHIER],
                'is_system_administrator' => false,
                'status' => 'DISABLED',
            ],
            [
                'email' => 'cashier.supervisor.demo@example.invalid',
                'name' => 'Demo Supervisor Kasir',
                'roles' => [RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR],
                'is_system_administrator' => false,
                'status' => 'DISABLED',
            ],
            [
                'email' => 'finance.steward.demo@example.invalid',
                'name' => 'Demo Pengelola Tarif',
                'roles' => [RoleCapabilityMatrix::ROLE_FINANCE_STEWARD],
                'is_system_administrator' => false,
                'status' => 'DISABLED',
            ],
            [
                'email' => 'procurement.officer.demo@example.invalid',
                'name' => 'Demo Petugas Pengadaan',
                'roles' => [RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER],
                'is_system_administrator' => false,
                'status' => 'DISABLED',
            ],
            [
                'email' => 'procurement.approver.demo@example.invalid',
                'name' => 'Demo Penyetuju Pengadaan',
                'roles' => [RoleCapabilityMatrix::ROLE_PROCUREMENT_APPROVER],
                'is_system_administrator' => false,
                'status' => 'DISABLED',
            ],
            [
                'email' => 'warehouse.receiver.demo@example.invalid',
                'name' => 'Demo Penerima Gudang Farmasi',
                'roles' => [RoleCapabilityMatrix::ROLE_WAREHOUSE_RECEIVER],
                'is_system_administrator' => false,
                'status' => 'DISABLED',
            ],
            [
                'email' => 'warehouse.inventory.controller.demo@example.invalid',
                'name' => 'Demo Pengelola Persediaan Gudang',
                'roles' => [RoleCapabilityMatrix::ROLE_WAREHOUSE_INVENTORY_CONTROLLER],
                'is_system_administrator' => false,
                'status' => 'DISABLED',
            ],
            [
                'email' => 'warehouse.inventory.supervisor.demo@example.invalid',
                'name' => 'Demo Supervisor Persediaan Gudang',
                'roles' => [RoleCapabilityMatrix::ROLE_WAREHOUSE_INVENTORY_SUPERVISOR],
                'is_system_administrator' => false,
                'status' => 'DISABLED',
            ],
            // Campus teaching walkthrough: one account can register → document → close RM.
            [
                'email' => 'mahasiswa.rmik@example.invalid',
                'name' => 'Mahasiswa RMIK Demo',
                'roles' => [
                    RoleCapabilityMatrix::ROLE_REGISTRAR,
                    RoleCapabilityMatrix::ROLE_NURSE,
                    RoleCapabilityMatrix::ROLE_PHYSICIAN,
                    RoleCapabilityMatrix::ROLE_RMIK,
                ],
                'is_system_administrator' => false,
            ],
            [
                'email' => config('simulation.rebuild_admin_email'),
                'name' => 'Rebuild Admin',
                'roles' => [RoleCapabilityMatrix::ROLE_ADMIN],
                'is_system_administrator' => true,
            ],
        ];

        DB::transaction(function () use ($actors, $password): void {
            foreach ($actors as $actor) {
                $role = count($actor['roles']) === 1 ? $actor['roles'][0] : null;
                $rosterKey = array_key_exists($actor['email'], TeachingRoleAccessManager::ROSTER)
                    ? $role
                    : null;
                $status = $actor['status'] ?? 'ACTIVE';

                $user = User::query()->where('email', $actor['email'])->first();

                if ($rosterKey !== null && $user instanceof User) {
                    $this->assertManagedRosterRerunIsSafe($user, $rosterKey);

                    continue;
                }

                $user ??= new User;
                $user->forceFill([
                    'email' => $actor['email'],
                    'name' => $actor['name'],
                    'password' => $password,
                    'status' => $status,
                    'is_system_administrator' => $actor['is_system_administrator'],
                    'email_verified_at' => $status === 'DISABLED' ? null : now(),
                    'teaching_access_roster_key' => $rosterKey,
                ])->save();

                $roleIds = Role::query()
                    ->whereIn('slug', $actor['roles'])
                    ->pluck('id')
                    ->all();

                $user->roles()->sync($roleIds);
            }
        }, attempts: 1);
    }

    private function assertManagedRosterRerunIsSafe(User $user, string $expectedRole): void
    {
        $hasAuthenticationArtifacts = DB::table(SchemaQualifier::table('sessions'))
            ->where('user_id', $user->getKey())
            ->exists()
            || DB::table(SchemaQualifier::table('passkeys'))
                ->where('user_id', $user->getKey())
                ->exists()
            || DB::table(SchemaQualifier::table('password_reset_tokens'))
                ->where('email', $user->email)
                ->exists();
        $hasLeaseEvidence = DB::table(SchemaQualifier::table('teaching_role_access_leases'))
            ->where('user_id', $user->getKey())
            ->exists();
        $isClosed = $user->status === 'DISABLED'
            && $user->email_verified_at === null
            && $user->remember_token === null
            && $user->two_factor_secret === null
            && $user->two_factor_recovery_codes === null
            && $user->two_factor_confirmed_at === null
            && $user->teaching_access_epoch === 0
            && $user->teaching_access_mutex === 0
            && $user->teaching_access_lease_public_id === null
            && $user->teaching_access_expires_at_epoch === null
            && ! $hasAuthenticationArtifacts
            && ! $hasLeaseEvidence;
        $hasExactIdentity = ! $user->is_system_administrator
            && $user->teaching_access_roster_key === $expectedRole
            && $user->roleSlugs() === [$expectedRole];

        if (! $isClosed || ! $hasExactIdentity) {
            throw new RuntimeException(
                'Demo actor seeding refused: a governed teaching-role identity is not in its pristine closed state.',
            );
        }
    }
}
