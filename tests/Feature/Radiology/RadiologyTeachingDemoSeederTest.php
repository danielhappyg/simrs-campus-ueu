<?php

namespace Tests\Feature\Radiology;

use App\Models\RadiologyExaminationMaster;
use App\Models\RadiologyExaminationMasterVersion;
use App\Models\RadiologyOperationReceipt;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Authorization\TeachingRoleAccessManager;
use Database\Seeders\DemoActorsSeeder;
use Database\Seeders\RadiologyMastersSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class RadiologyTeachingDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private string $configuredPassword;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configuredPassword = Str::random(32);

        config([
            'simulation.demo_seed_enabled' => true,
            'simulation.mode' => 'SIMULATION',
            'simulation.synthetic_only' => true,
            'simulation.demo_account_password' => $this->configuredPassword,
            'simulation.teaching_role_access_password' => Str::random(32),
            'simulation.teaching_role_access_commitment_key' => Str::random(48),
            'simulation.teaching_role_access_environment' => 'test-simulation',
            'simulation.teaching_role_access_release_sha' => str_repeat('a', 40),
            'simulation.teaching_role_access_deployment_url' => 'test-deployment.vercel.app',
            'simulation.teaching_role_access_canonical_host' => 'localhost',
            'simulation.teaching_role_access_max_ttl_minutes' => 30,
            'session.driver' => 'database',
            'session.table' => 'sessions',
            'session.connection' => config('database.default'),
        ]);

        $this->seed(RbacSeeder::class);
        $this->seed(DemoActorsSeeder::class);
    }

    public function test_demo_actor_seeder_creates_the_exact_governed_radiology_roster_once(): void
    {
        $expected = [
            'admin.demo@example.invalid' => RoleCapabilityMatrix::ROLE_ADMIN,
            'radiology.technologist.demo@example.invalid' => RoleCapabilityMatrix::ROLE_RADIOLOGY_TECHNOLOGIST,
            'radiologist.demo@example.invalid' => RoleCapabilityMatrix::ROLE_RADIOLOGIST,
        ];

        $this->assertSame($expected, array_intersect_key(TeachingRoleAccessManager::ROSTER, $expected));

        foreach (TeachingRoleAccessManager::effectiveRoster() as $email => $role) {
            $this->assertSame(1, User::query()->where('email', $email)->count());
            $user = User::query()->where('email', $email)->sole();
            $this->assertSame([$role], $user->roleSlugs());
            $this->assertSame($role, $user->teaching_access_roster_key);
            $this->assertSame('DISABLED', $user->status);
            $this->assertNull($user->email_verified_at);
            $this->assertFalse($user->is_system_administrator);
            $this->assertTrue(Hash::check($this->configuredPassword, $user->password));
        }

        $this->seed(DemoActorsSeeder::class);

        foreach (array_keys(TeachingRoleAccessManager::effectiveRoster()) as $email) {
            $this->assertSame(1, User::query()->where('email', $email)->count());
        }
    }

    public function test_fresh_seeded_radiology_roles_can_be_activated_and_revoked_one_at_a_time(): void
    {
        foreach ([
            'admin.demo@example.invalid',
            'radiology.technologist.demo@example.invalid',
            'radiologist.demo@example.invalid',
        ] as $email) {
            config(['simulation.teaching_role_access_password' => Str::random(32)]);
            $result = Artisan::call('teaching:role-access', $this->accessArguments('activate', $email));
            $this->assertSame(0, $result, Artisan::output());
            $this->assertDatabaseHas('users', ['email' => $email, 'status' => 'TEACHING_ACTIVE']);

            $this->assertSame(0, Artisan::call('teaching:role-access', $this->accessArguments('revoke', $email)));
            $this->assertDatabaseHas('users', [
                'email' => $email,
                'status' => 'DISABLED',
                'email_verified_at' => null,
            ]);
        }
    }

    public function test_demo_actor_reseed_refuses_an_active_managed_identity_without_mutating_it(): void
    {
        $email = 'radiology.technologist.demo@example.invalid';
        $result = Artisan::call('teaching:role-access', $this->accessArguments('activate', $email));
        $this->assertSame(0, $result, Artisan::output());
        $before = User::query()->where('email', $email)->sole();
        $passwordHash = (string) $before->password;
        $leaseId = $before->teaching_access_lease_public_id;

        try {
            $this->seed(DemoActorsSeeder::class);
            $this->fail('Seeder should reject a managed identity with an active access lease.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('pristine closed state', $exception->getMessage());
        }

        $after = User::query()->where('email', $email)->sole();
        $this->assertSame('TEACHING_ACTIVE', $after->status);
        $this->assertSame($passwordHash, (string) $after->password);
        $this->assertSame($leaseId, $after->teaching_access_lease_public_id);
    }

    public function test_demo_actor_reseed_rolls_back_earlier_recreation_when_a_later_identity_is_drifted(): void
    {
        User::query()->where('email', 'registrar.demo@example.invalid')->sole()->delete();
        User::query()->where('email', 'radiologist.demo@example.invalid')->sole()
            ->forceFill(['status' => 'TEACHING_ACTIVE', 'email_verified_at' => now()])
            ->save();

        try {
            $this->seed(DemoActorsSeeder::class);
            $this->fail('Seeder should reject a later drifted managed identity.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('pristine closed state', $exception->getMessage());
        }

        $this->assertDatabaseMissing('users', ['email' => 'registrar.demo@example.invalid']);
        $this->assertDatabaseHas('users', [
            'email' => 'radiologist.demo@example.invalid',
            'status' => 'TEACHING_ACTIVE',
        ]);
    }

    public function test_radiology_master_seeder_uses_the_governed_service_and_is_idempotent(): void
    {
        $this->seed(RadiologyMastersSeeder::class);

        $actor = User::query()->where('email', RadiologyMastersSeeder::ACTOR_EMAIL)->sole();
        $catalogue = RadiologyMastersSeeder::catalogue();
        $this->assertCount(3, $catalogue);
        $this->assertDatabaseCount('radiology_examination_masters', count($catalogue));
        $this->assertDatabaseCount('radiology_examination_master_versions', count($catalogue));
        $this->assertDatabaseCount('radiology_master_code_reservations', count($catalogue));
        $this->assertDatabaseCount('radiology_operation_receipts', count($catalogue));

        foreach ($catalogue as $examination) {
            $master = RadiologyExaminationMaster::query()
                ->where('examination_code', $examination['code'])
                ->sole();
            $this->assertSame($examination['name'], $master->display_name);
            $this->assertSame($examination['preparation'], $master->preparation_instruction);
            $this->assertSame(RadiologyExaminationMaster::ACTIVE, $master->state);
            $this->assertSame(1, $master->version);

            $version = RadiologyExaminationMasterVersion::query()
                ->where('radiology_examination_master_id', $master->id)
                ->sole();
            $this->assertSame($actor->id, $version->actor_user_id);
            $this->assertSame(RadiologyExaminationMaster::ACTIVE, $version->state);
        }

        $this->assertSame(
            count($catalogue),
            AuditEvent::query()
                ->where('action', 'radiology.workflow.mutate')
                ->where('outcome', 'SUCCESS')
                ->count(),
        );
        $this->assertSame(
            count($catalogue),
            RadiologyOperationReceipt::query()
                ->where('actor_user_id', $actor->id)
                ->where('operation', 'RADIOLOGY_MASTER_CREATE')
                ->count(),
        );
        $this->assertDatabaseCount('radiology_orders', 0);
        $this->assertDatabaseCount('radiology_report_versions', 0);

        $this->seed(RadiologyMastersSeeder::class);

        $this->assertDatabaseCount('radiology_examination_masters', count($catalogue));
        $this->assertDatabaseCount('radiology_examination_master_versions', count($catalogue));
        $this->assertDatabaseCount('radiology_operation_receipts', count($catalogue));
        $this->assertSame(
            count($catalogue),
            AuditEvent::query()->where('action', 'radiology.workflow.mutate')->count(),
        );
    }

    public function test_radiology_master_seeder_refuses_an_unsafe_mode_before_writing(): void
    {
        config(['simulation.mode' => 'PRODUCTION']);

        try {
            $this->seed(RadiologyMastersSeeder::class);
            $this->fail('Seeder should reject a non-simulation environment.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('synthetic simulation boundary', $exception->getMessage());
        }

        $this->assertDatabaseCount('radiology_examination_masters', 0);
        $this->assertDatabaseCount('radiology_examination_master_versions', 0);
        $this->assertDatabaseCount('radiology_operation_receipts', 0);
    }

    /** @return array<string, mixed> */
    private function accessArguments(string $action, string $email): array
    {
        return [
            'action' => $action,
            'email' => $email,
            '--confirm' => TeachingRoleAccessManager::confirmationPhrase($action, $email),
            '--operator' => 'SIMRS Campus Test Operator',
            '--reason' => 'Temporary radiology teaching workflow verification',
            '--expected-environment' => 'test-simulation',
            '--expected-release-sha' => str_repeat('a', 40),
            '--expected-deployment-url' => 'test-deployment.vercel.app',
            '--expected-canonical-host' => 'localhost',
            '--ttl-minutes' => 15,
        ];
    }
}
