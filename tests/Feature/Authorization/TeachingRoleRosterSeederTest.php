<?php

namespace Tests\Feature\Authorization;

use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Authorization\TeachingRoleAccessManager;
use App\Support\Database\SchemaQualifier;
use Database\Seeders\RbacSeeder;
use Database\Seeders\TeachingRoleRosterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

final class TeachingRoleRosterSeederTest extends TestCase
{
    use RefreshDatabase;

    private const DEMO_PASSWORD = 'must-not-be-used-by-roster-reconciliation';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'simulation.warehouse_capability_enabled' => false,
            'simulation.mode' => 'SIMULATION',
            'simulation.synthetic_only' => true,
            'simulation.demo_account_password' => self::DEMO_PASSWORD,
            'simulation.rebuild_admin_email' => 'rebuild.admin@example.invalid',
        ]);
        $this->seed(RbacSeeder::class);
    }

    public function test_false_gate_creates_only_the_fifteen_exact_closed_non_warehouse_identities(): void
    {
        $this->seed(TeachingRoleRosterSeeder::class);

        $this->assertDatabaseCount('users', 15);
        $hashes = [];
        foreach (TeachingRoleAccessManager::NON_WAREHOUSE_ROSTER as $email => $role) {
            $user = User::query()->where('email', $email)->sole();
            $this->assertExactClosedIdentity($user, $role);
            $this->assertFalse(Hash::check(self::DEMO_PASSWORD, (string) $user->password));
            $hashes[] = (string) $user->password;
        }
        $this->assertCount(15, array_unique($hashes));

        foreach (array_keys(TeachingRoleAccessManager::WAREHOUSE_ROSTER) as $email) {
            $this->assertDatabaseMissing('users', ['email' => $email]);
        }
        $this->assertDatabaseMissing('users', ['email' => 'mahasiswa.rmik@example.invalid']);
        $this->assertDatabaseMissing('users', ['email' => 'rebuild.admin@example.invalid']);
    }

    public function test_reconciliation_requires_both_simulation_safety_boundaries(): void
    {
        foreach ([
            ['simulation.mode' => 'PRODUCTION', 'simulation.synthetic_only' => true],
            ['simulation.mode' => 'SIMULATION', 'simulation.synthetic_only' => false],
        ] as $unsafe) {
            config($unsafe);

            try {
                $this->seed(TeachingRoleRosterSeeder::class);
                $this->fail('Unsafe runtime configuration must refuse roster reconciliation.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('synthetic-only SIMULATION mode', $exception->getMessage());
            }

            $this->assertDatabaseCount('users', 0);
        }
    }

    public function test_reconciliation_is_idempotent_and_retains_existing_closed_credentials(): void
    {
        $this->seed(TeachingRoleRosterSeeder::class);
        $before = User::query()
            ->whereIn('email', array_keys(TeachingRoleAccessManager::effectiveRoster()))
            ->orderBy('email')
            ->get()
            ->mapWithKeys(static fn (User $user): array => [
                $user->email => ['id' => $user->id, 'password' => (string) $user->password],
            ])
            ->all();

        $this->seed(TeachingRoleRosterSeeder::class);

        $after = User::query()
            ->whereIn('email', array_keys(TeachingRoleAccessManager::effectiveRoster()))
            ->orderBy('email')
            ->get()
            ->mapWithKeys(static fn (User $user): array => [
                $user->email => ['id' => $user->id, 'password' => (string) $user->password],
            ])
            ->all();
        $this->assertSame($before, $after);
        $this->assertDatabaseCount('users', 15);
    }

    public function test_true_gate_creates_the_complete_twenty_identity_roster(): void
    {
        config(['simulation.warehouse_capability_enabled' => true]);

        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The complete warehouse roster seeder requires the intentionally unrun warehouse roster migration.');
        }

        $this->seed(TeachingRoleRosterSeeder::class);

        $this->assertDatabaseCount('users', 20);
        foreach (TeachingRoleAccessManager::ROSTER as $email => $role) {
            $this->assertExactClosedIdentity(User::query()->where('email', $email)->sole(), $role);
        }
    }

    public function test_existing_drift_is_preflighted_before_any_missing_identity_is_created(): void
    {
        $email = 'finance.steward.demo@example.invalid';
        $role = Role::query()->where('slug', RoleCapabilityMatrix::ROLE_FINANCE_STEWARD)->sole();
        $user = User::factory()->create([
            'email' => $email,
            'status' => 'ACTIVE',
            'is_system_administrator' => false,
            'teaching_access_roster_key' => RoleCapabilityMatrix::ROLE_FINANCE_STEWARD,
        ]);
        $user->roles()->sync([$role->id]);

        try {
            $this->seed(TeachingRoleRosterSeeder::class);
            $this->fail('Existing roster drift must refuse the complete reconciliation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('drift or retained access artifacts', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseMissing('users', ['email' => 'registrar.demo@example.invalid']);
    }

    public function test_case_variant_identity_is_detected_before_any_canonical_identity_is_created(): void
    {
        $user = User::factory()->create([
            'email' => 'Registrar.Demo@Example.Invalid',
            'status' => 'DISABLED',
            'is_system_administrator' => false,
            'email_verified_at' => null,
            'remember_token' => null,
            'teaching_access_roster_key' => null,
            'teaching_access_epoch' => 0,
            'teaching_access_mutex' => 0,
        ]);
        try {
            $this->seed(TeachingRoleRosterSeeder::class);
            $this->fail('A case-variant retained identity must block canonical roster creation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('drift or retained access artifacts', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['email' => 'Registrar.Demo@Example.Invalid']);
        $this->assertDatabaseMissing('users', ['email' => 'registrar.demo@example.invalid']);
        $this->assertDatabaseMissing('users', ['email' => 'nurse.demo@example.invalid']);
    }

    public function test_case_variant_reset_record_blocks_all_roster_creation(): void
    {
        DB::table(SchemaQualifier::table('password_reset_tokens'))->insert([
            'email' => 'Nurse.Demo@Example.Invalid',
            'token' => hash('sha256', 'case-variant-reset-evidence'),
            'created_at' => now(),
        ]);

        try {
            $this->seed(TeachingRoleRosterSeeder::class);
            $this->fail('A case-variant reset record must block canonical roster creation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('missing identity retains authentication artifacts', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_role_and_identity_collisions_fail_before_any_roster_mutation(): void
    {
        Role::query()->where('slug', RoleCapabilityMatrix::ROLE_FINANCE_STEWARD)->delete();
        try {
            $this->seed(TeachingRoleRosterSeeder::class);
            $this->fail('A missing RBAC role must fail before creating a roster identity.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('run RbacSeeder first', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 0);

        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $this->seed(RbacSeeder::class);
        $nurseRole = Role::query()->where('slug', RoleCapabilityMatrix::ROLE_NURSE)->sole();
        $collision = User::factory()->create([
            'email' => 'registrar.demo@example.invalid',
            'status' => 'DISABLED',
            'is_system_administrator' => false,
            'email_verified_at' => null,
            'remember_token' => null,
            'teaching_access_roster_key' => RoleCapabilityMatrix::ROLE_NURSE,
            'teaching_access_epoch' => 0,
            'teaching_access_mutex' => 0,
        ]);
        $collision->roles()->sync([$nurseRole->id]);

        try {
            $this->seed(TeachingRoleRosterSeeder::class);
            $this->fail('A cross-roster email and marker collision must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('collides with multiple roster identities', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 1);
    }

    public function test_reconciliation_refuses_authentication_or_lease_evidence_on_an_existing_identity(): void
    {
        $this->seed(TeachingRoleRosterSeeder::class);
        DB::table(SchemaQualifier::table('password_reset_tokens'))->insert([
            'email' => 'registrar.demo@example.invalid',
            'token' => hash('sha256', 'retained-reset-evidence'),
            'created_at' => now(),
        ]);

        try {
            $this->seed(TeachingRoleRosterSeeder::class);
            $this->fail('Retained authentication evidence must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('drift or retained access artifacts', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 15);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    private function assertExactClosedIdentity(User $user, string $role): void
    {
        $this->assertSame('DISABLED', $user->status);
        $this->assertNull($user->email_verified_at);
        $this->assertFalse($user->is_system_administrator);
        $this->assertNull($user->remember_token);
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertSame(0, $user->teaching_access_epoch);
        $this->assertSame(0, $user->teaching_access_mutex);
        $this->assertSame($role, $user->teaching_access_roster_key);
        $this->assertNull($user->teaching_access_lease_public_id);
        $this->assertNull($user->teaching_access_expires_at_epoch);
        $this->assertSame([$role], $user->roleSlugs());
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('passkeys', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseMissing('teaching_role_access_leases', ['user_id' => $user->id]);
    }
}
