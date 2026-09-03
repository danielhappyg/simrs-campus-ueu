<?php

namespace Tests\Feature\Authorization;

use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Authorization\TeachingRoleAccessLeaseGuard;
use App\Support\Authorization\TeachingRoleAccessManager;
use App\Support\Warehouse\WarehouseActorPolicy;
use Database\Seeders\DemoActorsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

final class TeachingRoleRosterGateTest extends TestCase
{
    use RefreshDatabase;

    private const DEFERRED_EMAIL = 'warehouse.receiver.demo@example.invalid';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'simulation.warehouse_capability_enabled' => false,
            'simulation.demo_seed_enabled' => true,
            'simulation.mode' => 'SIMULATION',
            'simulation.synthetic_only' => true,
            'simulation.demo_account_password' => Str::random(32),
            'simulation.teaching_role_access_password' => Str::random(32),
            'simulation.teaching_role_access_commitment_key' => Str::random(48),
            'simulation.teaching_role_access_environment' => 'test-simulation',
            'simulation.teaching_role_access_release_sha' => str_repeat('e', 40),
            'simulation.teaching_role_access_deployment_url' => 'test-deployment.vercel.app',
            'simulation.teaching_role_access_canonical_host' => 'localhost',
            'simulation.teaching_role_access_max_ttl_minutes' => 30,
            'simulation.rebuild_admin_email' => 'rebuild.admin@example.invalid',
            'session.driver' => 'database',
            'session.table' => 'sessions',
            'session.connection' => config('database.default'),
        ]);

        $this->seed(RbacSeeder::class);
    }

    public function test_false_gate_exposes_and_seeds_only_the_non_warehouse_roster(): void
    {
        $this->assertSame(
            TeachingRoleAccessManager::ROSTER,
            TeachingRoleAccessManager::NON_WAREHOUSE_ROSTER + TeachingRoleAccessManager::WAREHOUSE_ROSTER,
        );
        $this->assertSame(TeachingRoleAccessManager::NON_WAREHOUSE_ROSTER, TeachingRoleAccessManager::effectiveRoster());
        $this->assertCount(15, TeachingRoleAccessManager::effectiveRoster());

        $this->seed(DemoActorsSeeder::class);

        foreach (TeachingRoleAccessManager::NON_WAREHOUSE_ROSTER as $email => $role) {
            $user = User::query()->where('email', $email)->sole();
            $this->assertSame($role, $user->teaching_access_roster_key);
        }
        foreach (TeachingRoleAccessManager::WAREHOUSE_ROSTER as $email => $role) {
            $this->assertDatabaseMissing('users', [
                'email' => $email,
                'teaching_access_roster_key' => $role,
            ]);
        }
    }

    public function test_false_gate_rejects_deferred_status_and_activation_and_denies_a_stale_identity(): void
    {
        foreach (['status', 'activate'] as $action) {
            $this->assertSame(1, Artisan::call('teaching:role-access', $this->accessArguments($action)));
            $this->assertStringContainsString('not in the exact demo-account roster', Artisan::output());
        }

        $role = Role::query()->where('slug', RoleCapabilityMatrix::ROLE_WAREHOUSE_RECEIVER)->sole();
        $user = User::factory()->create([
            'email' => self::DEFERRED_EMAIL,
            'status' => 'ACTIVE',
            'is_system_administrator' => false,
            'teaching_access_roster_key' => RoleCapabilityMatrix::ROLE_WAREHOUSE_RECEIVER,
        ]);
        $user->roles()->sync([$role->id]);

        $guard = app(TeachingRoleAccessLeaseGuard::class);
        $this->assertTrue($guard->isRosterAccount($user));
        $this->assertFalse($guard->allows($user));
        $this->assertFalse(app(WarehouseActorPolicy::class)->canRecordReceipt($user));

        $exitCode = Artisan::call('teaching:role-access', $this->accessArguments('revoke'));
        $output = Artisan::output();
        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString(self::DEFERRED_EMAIL.' roles=', $output);
        $this->assertStringContainsString('Roster: active=0 disabled=1 missing=15 drifted=15', $output);
        $this->assertSame('DISABLED', $user->fresh()->status);
    }

    public function test_deferred_identity_compensation_uses_canonical_containment_and_audit_semantics(): void
    {
        $role = Role::query()->where('slug', RoleCapabilityMatrix::ROLE_WAREHOUSE_RECEIVER)->sole();
        $user = User::factory()->create([
            'email' => self::DEFERRED_EMAIL,
            'status' => 'TEACHING_ACTIVE',
            'is_system_administrator' => false,
            'teaching_access_roster_key' => RoleCapabilityMatrix::ROLE_WAREHOUSE_RECEIVER,
            'email_verified_at' => now(),
            'teaching_access_epoch' => 2,
            'teaching_access_mutex' => 2,
        ]);
        $user->roles()->sync([$role->id]);

        $method = new ReflectionMethod(TeachingRoleAccessManager::class, 'compensateToDisabled');
        $method->invoke(
            app(TeachingRoleAccessManager::class),
            self::DEFERRED_EMAIL,
            RoleCapabilityMatrix::ROLE_WAREHOUSE_RECEIVER,
            'SIMRS Campus Release Operator',
            'Contain deferred warehouse identity after failed verification',
            [
                'environment' => 'test-simulation',
                'release_sha' => str_repeat('e', 40),
                'deployment_url' => 'test-deployment.vercel.app',
                'canonical_host' => 'localhost',
            ],
        );

        $this->assertSame('DISABLED', $user->fresh()->status);
        $this->assertDatabaseHas('teaching_role_access_leases', [
            'user_id' => $user->id,
            'expected_role' => RoleCapabilityMatrix::ROLE_WAREHOUSE_RECEIVER,
            'status' => 'COMPENSATED',
        ]);
        $this->assertSame(
            TeachingRoleAccessManager::AUDIT_COMPENSATED,
            AuditEvent::query()->sole()->action,
        );
    }

    public function test_true_gate_restores_and_seeds_the_complete_canonical_roster(): void
    {
        config(['simulation.warehouse_capability_enabled' => true]);

        $this->assertSame(TeachingRoleAccessManager::ROSTER, TeachingRoleAccessManager::effectiveRoster());
        $this->assertCount(20, TeachingRoleAccessManager::effectiveRoster());

        $this->seed(DemoActorsSeeder::class);

        foreach (TeachingRoleAccessManager::ROSTER as $email => $role) {
            $user = User::query()->where('email', $email)->sole();
            $this->assertSame($role, $user->teaching_access_roster_key);
        }
    }

    public function test_schema_cutover_and_runtime_capability_switches_are_independent(): void
    {
        config([
            'database.warehouse_schema_migration_enabled' => true,
            'simulation.warehouse_capability_enabled' => false,
        ]);
        $this->assertSame(TeachingRoleAccessManager::NON_WAREHOUSE_ROSTER, TeachingRoleAccessManager::effectiveRoster());

        config([
            'database.warehouse_schema_migration_enabled' => false,
            'simulation.warehouse_capability_enabled' => true,
        ]);
        $this->assertSame(TeachingRoleAccessManager::ROSTER, TeachingRoleAccessManager::effectiveRoster());

        $originalDefault = config('database.default');
        try {
            config([
                'database.default' => 'release_default',
                'database.connections.release_default.driver' => 'pgsql',
            ]);
            $migration = require database_path('migrations/2026_09_03_000100_expand_warehouse_teaching_role_access_roster.php');
            $this->assertFalse($migration->shouldRun());
        } finally {
            config(['database.default' => $originalDefault]);
        }
    }

    /** @return array<string, mixed> */
    private function accessArguments(string $action): array
    {
        return [
            'action' => $action,
            'email' => self::DEFERRED_EMAIL,
            '--confirm' => TeachingRoleAccessManager::confirmationPhrase($action, self::DEFERRED_EMAIL),
            '--operator' => 'SIMRS Campus Release Operator',
            '--reason' => 'Verify deferred warehouse identity remains unavailable',
            '--expected-environment' => 'test-simulation',
            '--expected-release-sha' => str_repeat('e', 40),
            '--expected-deployment-url' => 'test-deployment.vercel.app',
            '--expected-canonical-host' => 'localhost',
            '--ttl-minutes' => 15,
        ];
    }
}
