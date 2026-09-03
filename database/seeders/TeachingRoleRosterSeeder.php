<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\TeachingRoleAccessManager;
use App\Support\Database\SchemaQualifier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

final class TeachingRoleRosterSeeder extends Seeder
{
    /** @var array<string, string> */
    private const NAMES = [
        'registrar.demo@example.invalid' => 'Demo Registrar',
        'nurse.demo@example.invalid' => 'Demo Nurse',
        'physician.demo@example.invalid' => 'Demo Physician',
        'rmik.demo@example.invalid' => 'Demo RMIK',
        'admin.demo@example.invalid' => 'Demo Administrator',
        'radiology.technologist.demo@example.invalid' => 'Demo Radiology Technologist',
        'radiologist.demo@example.invalid' => 'Demo Radiologist',
        'laboratory.technologist.demo@example.invalid' => 'Demo Laboratory Technologist',
        'laboratory.verifier.demo@example.invalid' => 'Demo Laboratory Verifier',
        'pharmacist.demo@example.invalid' => 'Demo Pharmacist',
        'pharmacy.technician.demo@example.invalid' => 'Demo Pharmacy Technician',
        'pharmacy.inventory.demo@example.invalid' => 'Demo Pharmacy Inventory Controller',
        'cashier.demo@example.invalid' => 'Demo Kasir',
        'cashier.supervisor.demo@example.invalid' => 'Demo Supervisor Kasir',
        'finance.steward.demo@example.invalid' => 'Demo Pengelola Tarif',
        'procurement.officer.demo@example.invalid' => 'Demo Petugas Pengadaan',
        'procurement.approver.demo@example.invalid' => 'Demo Penyetuju Pengadaan',
        'warehouse.receiver.demo@example.invalid' => 'Demo Penerima Gudang Farmasi',
        'warehouse.inventory.controller.demo@example.invalid' => 'Demo Pengelola Persediaan Gudang',
        'warehouse.inventory.supervisor.demo@example.invalid' => 'Demo Supervisor Persediaan Gudang',
    ];

    public function run(): void
    {
        if (config('simulation.mode') !== 'SIMULATION'
            || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException(
                'Teaching-role roster reconciliation requires synthetic-only SIMULATION mode.',
            );
        }

        $roster = TeachingRoleAccessManager::effectiveRoster();
        if ($roster === [] || array_diff_key($roster, self::NAMES) !== []) {
            throw new RuntimeException('Teaching-role roster reconciliation has no complete registered identity map.');
        }

        DB::transaction(function () use ($roster): void {
            $rosterEmails = array_keys($roster);
            $emailBindings = implode(', ', array_fill(0, count($rosterEmails), '?'));
            $roles = Role::query()
                ->whereIn('slug', array_values($roster))
                ->orderBy('slug')
                ->lockForUpdate()
                ->get()
                ->keyBy('slug');
            $this->assertEveryRoleExists($roster, $roles);

            $users = User::query()
                ->where(function ($query) use ($emailBindings, $roster, $rosterEmails): void {
                    $query->whereRaw("LOWER(email) IN ({$emailBindings})", $rosterEmails)
                        ->orWhereIn('teaching_access_roster_key', array_values($roster));
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $userIds = $users->pluck('id')->all();
            $roleAssignments = DB::table(SchemaQualifier::table('role_user'))
                ->whereIn('user_id', $userIds)
                ->orderBy('user_id')
                ->orderBy('role_id')
                ->lockForUpdate()
                ->get();
            $sessions = DB::table(SchemaQualifier::table('sessions'))
                ->whereIn('user_id', $userIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $passkeys = DB::table(SchemaQualifier::table('passkeys'))
                ->whereIn('user_id', $userIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $resetRecords = DB::table(SchemaQualifier::table('password_reset_tokens'))
                ->whereRaw("LOWER(email) IN ({$emailBindings})", $rosterEmails)
                ->orderBy('email')
                ->lockForUpdate()
                ->get();
            $leases = DB::table(SchemaQualifier::table('teaching_role_access_leases'))
                ->where(function ($query) use ($userIds, $roster): void {
                    $query->whereIn('user_id', $userIds)
                        ->orWhereIn('expected_role', array_values($roster));
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $existingByEmail = $this->preflightUsers(
                $roster,
                $users,
                $roles,
                $roleAssignments,
                $sessions,
                $passkeys,
                $resetRecords,
                $leases,
            );

            foreach ($roster as $email => $expectedRole) {
                if ($existingByEmail->has($email)) {
                    continue;
                }

                /** @var Role $role */
                $role = $roles->get($expectedRole);
                $user = new User;
                $user->forceFill([
                    'email' => $email,
                    'name' => self::NAMES[$email],
                    'password' => Hash::make(base64_encode(random_bytes(48))),
                    'status' => 'DISABLED',
                    'is_system_administrator' => false,
                    'email_verified_at' => null,
                    'remember_token' => null,
                    'two_factor_secret' => null,
                    'two_factor_recovery_codes' => null,
                    'two_factor_confirmed_at' => null,
                    'teaching_access_epoch' => 0,
                    'teaching_access_mutex' => 0,
                    'teaching_access_roster_key' => $expectedRole,
                    'teaching_access_lease_public_id' => null,
                    'teaching_access_expires_at_epoch' => null,
                ])->save();
                $user->roles()->attach($role->getKey());
            }
        }, attempts: 1);
    }

    /**
     * @param  array<string, string>  $roster
     * @param  Collection<string, Role>  $roles
     */
    private function assertEveryRoleExists(array $roster, Collection $roles): void
    {
        if ($roles->count() !== count(array_unique(array_values($roster)))) {
            throw new RuntimeException(
                'Teaching-role roster reconciliation refused: run RbacSeeder first and verify every exact role.',
            );
        }

        foreach (array_values($roster) as $role) {
            if (! $roles->get($role) instanceof Role) {
                throw new RuntimeException(
                    'Teaching-role roster reconciliation refused: run RbacSeeder first and verify every exact role.',
                );
            }
        }
    }

    /**
     * @param  array<string, string>  $roster
     * @param  Collection<int, User>  $users
     * @param  Collection<string, Role>  $roles
     * @param  Collection<int, \stdClass>  $roleAssignments
     * @param  Collection<int, \stdClass>  $sessions
     * @param  Collection<int, \stdClass>  $passkeys
     * @param  Collection<int, \stdClass>  $resetRecords
     * @param  Collection<int, \stdClass>  $leases
     * @return Collection<string, User>
     */
    private function preflightUsers(
        array $roster,
        Collection $users,
        Collection $roles,
        Collection $roleAssignments,
        Collection $sessions,
        Collection $passkeys,
        Collection $resetRecords,
        Collection $leases,
    ): Collection {
        /** @var Collection<string, User> $existingByEmail */
        $existingByEmail = collect();
        $claimedUserIds = [];

        foreach ($users as $user) {
            $matchingIdentities = collect($roster)->filter(
                static fn (string $role, string $email): bool => strtolower((string) $user->email) === $email
                    || $user->teaching_access_roster_key === $role,
            );
            if ($matchingIdentities->count() > 1) {
                throw new RuntimeException(
                    'Teaching-role roster reconciliation refused: one account collides with multiple roster identities.',
                );
            }
        }

        foreach ($roster as $email => $expectedRole) {
            $candidates = $users->filter(
                static fn (User $user): bool => strtolower((string) $user->email) === $email
                    || $user->teaching_access_roster_key === $expectedRole,
            )->values();
            if ($candidates->count() > 1) {
                throw new RuntimeException(
                    'Teaching-role roster reconciliation refused: an email or roster marker collision exists.',
                );
            }
            if ($candidates->isEmpty()) {
                if ($resetRecords->contains(
                    static fn (object $record): bool => strtolower((string) data_get($record, 'email')) === $email,
                )) {
                    throw new RuntimeException(
                        'Teaching-role roster reconciliation refused: a missing identity retains authentication artifacts.',
                    );
                }

                continue;
            }

            /** @var User $user */
            $user = $candidates->first();
            $userId = (int) $user->getKey();
            if (array_key_exists($userId, $claimedUserIds)) {
                throw new RuntimeException(
                    'Teaching-role roster reconciliation refused: one account collides with multiple roster identities.',
                );
            }
            $claimedUserIds[$userId] = true;

            /** @var Role $expectedRoleModel */
            $expectedRoleModel = $roles->get($expectedRole);
            $assignedRoleIds = $roleAssignments
                ->filter(static fn (object $assignment): bool => (int) data_get($assignment, 'user_id') === $userId)
                ->pluck('role_id')
                ->map(static fn (mixed $roleId): int => (int) $roleId)
                ->unique()
                ->sort()
                ->values()
                ->all();

            $hasArtifacts = $sessions->contains(
                static fn (object $session): bool => (int) data_get($session, 'user_id') === $userId,
            ) || $passkeys->contains(
                static fn (object $passkey): bool => (int) data_get($passkey, 'user_id') === $userId,
            ) || $resetRecords->contains(
                static fn (object $record): bool => strtolower((string) data_get($record, 'email')) === $email,
            ) || $leases->contains(
                static fn (object $lease): bool => (int) data_get($lease, 'user_id') === $userId
                    || data_get($lease, 'expected_role') === $expectedRole,
            );
            $exact = (string) $user->email === $email
                && $user->teaching_access_roster_key === $expectedRole
                && $user->status === 'DISABLED'
                && $user->is_system_administrator === false
                && $user->email_verified_at === null
                && $user->remember_token === null
                && $user->two_factor_secret === null
                && $user->two_factor_recovery_codes === null
                && $user->two_factor_confirmed_at === null
                && $user->teaching_access_epoch === 0
                && $user->teaching_access_mutex === 0
                && $user->teaching_access_lease_public_id === null
                && $user->teaching_access_expires_at_epoch === null
                && $assignedRoleIds === [(int) $expectedRoleModel->getKey()]
                && ! $hasArtifacts;
            if (! $exact) {
                throw new RuntimeException(
                    'Teaching-role roster reconciliation refused: an existing managed identity has drift or retained access artifacts.',
                );
            }

            $existingByEmail->put($email, $user);
        }

        return $existingByEmail;
    }
}
