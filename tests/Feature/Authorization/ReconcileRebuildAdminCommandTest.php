<?php

namespace Tests\Feature\Authorization;

use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\CompositeExpectation;
use Tests\TestCase;

class ReconcileRebuildAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'simulation.mode' => 'SIMULATION',
            'simulation.synthetic_only' => true,
            'simulation.rebuild_admin_email' => 'admin.rebuild@example.invalid',
        ]);

        $this->seed(RbacSeeder::class);
    }

    public function test_default_dry_run_reports_known_drift_without_mutating_any_state(): void
    {
        $target = $this->createTarget($this->knownDriftRoles());
        $this->createSession($target, 'dry-run-session');
        $password = $target->password;

        $exitCode = Artisan::call('rebuild:admin-reconcile');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Mode: DRY-RUN', $output);
        $this->assertStringContainsString('Dry run only', $output);
        $this->assertSame($this->knownDriftRoles(), $this->freshRoleSlugs($target));
        $this->assertSame('ACTIVE', $target->fresh()->status);
        $this->assertSame($password, $target->fresh()->password);
        $this->assertDatabaseHas('sessions', ['id' => 'dry-run-session', 'user_id' => $target->id]);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_apply_reconciles_to_admin_only_revokes_sessions_and_records_complete_audit_metadata(): void
    {
        $target = $this->createTarget($this->knownDriftRoles());
        $this->createSession($target, 'session-one');
        $this->createSession($target, 'session-two');
        $password = $target->password;

        $exitCode = Artisan::call('rebuild:admin-reconcile', [
            '--apply' => true,
            '--operator' => 'Daniel Happy Gunawan',
            '--reason' => 'Resolve privileged role drift before the wider teaching pilot',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Mode: APPLY', $output);
        $this->assertStringContainsString('Sessions revoked: 2', $output);
        $this->assertSame([RoleCapabilityMatrix::ROLE_ADMIN], $this->freshRoleSlugs($target));
        $this->assertSame('ACTIVE', $target->fresh()->status);
        $this->assertTrue($target->fresh()->is_system_administrator);
        $this->assertSame($password, $target->fresh()->password);
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);

        $event = AuditEvent::query()
            ->where('action', 'authorization.rebuild_admin.reconciled')
            ->sole();

        $this->assertSame('user', $event->resource_type);
        $this->assertSame($target->public_id, $event->resource_id);
        $this->assertSame('SUCCESS', $event->outcome);
        $this->assertSame('Resolve privileged role drift before the wider teaching pilot', $event->reason);
        $this->assertSame([
            'roles' => $this->knownDriftRoles(),
            'status' => 'ACTIVE',
        ], $event->metadata['before']);
        $this->assertSame([
            'roles' => [RoleCapabilityMatrix::ROLE_ADMIN],
            'status' => 'ACTIVE',
        ], $event->metadata['after']);
        $this->assertSame(2, $event->metadata['sessions_revoked']);
        $this->assertSame('Daniel Happy Gunawan', $event->metadata['operator']);
        $this->assertSame('Resolve privileged role drift before the wider teaching pilot', $event->metadata['reason']);
        $this->assertFalse($event->metadata['disable_requested']);
        $this->assertTrue($event->metadata['roles_changed']);
        $this->assertFalse($event->metadata['status_changed']);
        $this->assertTrue($event->metadata['mutated']);
        $this->assertTrue($event->metadata['system_admin_bypass_remains']);
        $this->assertStringContainsString('bypass remains active', $event->metadata['note']);
    }

    public function test_disable_applies_admin_only_roles_and_contains_the_account_without_clearing_system_admin_flag(): void
    {
        $target = $this->createTarget($this->knownDriftRoles());

        $exitCode = Artisan::call('rebuild:admin-reconcile', [
            '--apply' => true,
            '--disable' => true,
            '--operator' => 'Security Operations',
            '--reason' => 'Contain bootstrap account while administrative access is reviewed',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame([RoleCapabilityMatrix::ROLE_ADMIN], $this->freshRoleSlugs($target));
        $this->assertSame('DISABLED', $target->fresh()->status);
        $this->assertTrue($target->fresh()->is_system_administrator);

        $event = AuditEvent::query()->sole();
        $this->assertSame('DISABLED', $event->metadata['after']['status']);
        $this->assertTrue($event->metadata['disable_requested']);
        $this->assertTrue($event->metadata['status_changed']);
        $this->assertTrue($event->metadata['system_admin_bypass_remains']);
    }

    public function test_reconciliation_refuses_non_simulation_or_non_synthetic_modes(): void
    {
        $target = $this->createTarget($this->knownDriftRoles());

        foreach ([
            ['mode' => 'PRODUCTION', 'synthetic_only' => true],
            ['mode' => 'SIMULATION', 'synthetic_only' => false],
        ] as $unsafeConfig) {
            config([
                'simulation.mode' => $unsafeConfig['mode'],
                'simulation.synthetic_only' => $unsafeConfig['synthetic_only'],
            ]);

            $exitCode = Artisan::call('rebuild:admin-reconcile', [
                '--apply' => true,
                '--operator' => 'Security Operations',
                '--reason' => 'Attempted reconciliation in an unsafe application mode',
            ]);

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('requires APP_MODE=SIMULATION', Artisan::output());
        }

        $this->assertSame($this->knownDriftRoles(), $this->freshRoleSlugs($target));
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_reconciliation_fails_closed_for_an_unexpected_role_set(): void
    {
        $target = $this->createTarget([
            RoleCapabilityMatrix::ROLE_ADMIN,
            RoleCapabilityMatrix::ROLE_REGISTRAR,
        ]);

        $exitCode = Artisan::call('rebuild:admin-reconcile', [
            '--apply' => true,
            '--operator' => 'Security Operations',
            '--reason' => 'Attempt to reconcile an unrecognized privileged role combination',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('neither canonical nor the known drift set', Artisan::output());
        $this->assertSame([
            RoleCapabilityMatrix::ROLE_ADMIN,
            RoleCapabilityMatrix::ROLE_REGISTRAR,
        ], $this->freshRoleSlugs($target));
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_reconciliation_refuses_a_target_without_system_administrator_flag_or_admin_role(): void
    {
        $target = $this->createTarget([RoleCapabilityMatrix::ROLE_ADMIN], isSystemAdministrator: false);

        $this->assertSame(1, Artisan::call('rebuild:admin-reconcile'));
        $this->assertStringContainsString('is not a system administrator', Artisan::output());

        $target->forceFill(['is_system_administrator' => true])->save();
        $target->roles()->sync([
            Role::query()->where('slug', RoleCapabilityMatrix::ROLE_REGISTRAR)->firstOrFail()->id,
        ]);

        $this->assertSame(1, Artisan::call('rebuild:admin-reconcile'));
        $this->assertStringContainsString('lacks the admin role', Artisan::output());
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_reconciliation_refuses_when_the_configured_target_is_missing(): void
    {
        $this->assertSame(1, Artisan::call('rebuild:admin-reconcile'));
        $this->assertStringContainsString('configured target user does not exist', Artisan::output());
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_apply_requires_non_placeholder_operator_and_reason(): void
    {
        $target = $this->createTarget($this->knownDriftRoles());

        $invalidInputs = [
            [
                'arguments' => ['--apply' => true, '--reason' => 'A sufficiently specific operational reason'],
                'message' => '--operator must be a non-placeholder',
            ],
            [
                'arguments' => [
                    '--apply' => true,
                    '--operator' => 'unknown',
                    '--reason' => 'A sufficiently specific operational reason',
                ],
                'message' => '--operator must be a non-placeholder',
            ],
            [
                'arguments' => ['--apply' => true, '--operator' => 'Security Operations'],
                'message' => '--reason must be a non-placeholder',
            ],
            [
                'arguments' => [
                    '--apply' => true,
                    '--operator' => 'Security Operations',
                    '--reason' => 'todo',
                ],
                'message' => '--reason must be a non-placeholder',
            ],
        ];

        foreach ($invalidInputs as $input) {
            $this->assertSame(1, Artisan::call('rebuild:admin-reconcile', $input['arguments']));
            $this->assertStringContainsString($input['message'], Artisan::output());
        }

        $this->assertSame($this->knownDriftRoles(), $this->freshRoleSlugs($target));
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_apply_accepts_255_character_operator_and_reason_attribution(): void
    {
        $this->createTarget($this->knownDriftRoles());
        $operator = str_repeat('o', 255);
        $reason = str_repeat('r', 255);

        $this->assertSame(0, Artisan::call('rebuild:admin-reconcile', [
            '--apply' => true,
            '--operator' => $operator,
            '--reason' => $reason,
        ]));

        $event = AuditEvent::query()->sole();
        $this->assertSame($operator, $event->metadata['operator']);
        $this->assertSame($reason, $event->reason);
    }

    public function test_apply_rejects_operator_or_reason_longer_than_255_characters(): void
    {
        $target = $this->createTarget($this->knownDriftRoles());

        foreach (['operator', 'reason'] as $field) {
            $arguments = [
                '--apply' => true,
                '--operator' => 'Security Operations',
                '--reason' => 'Specific synthetic containment reason',
            ];
            $arguments['--'.$field] = str_repeat('x', 256);

            $this->assertSame(1, Artisan::call('rebuild:admin-reconcile', $arguments));
            $this->assertStringContainsString('255 characters', Artisan::output());
        }

        $this->assertSame($this->knownDriftRoles(), $this->freshRoleSlugs($target));
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_admin_only_state_is_idempotent_and_reported_without_domain_mutation(): void
    {
        $target = $this->createTarget([RoleCapabilityMatrix::ROLE_ADMIN]);
        $password = $target->password;
        $updatedAt = $target->updated_at;

        $exitCode = Artisan::call('rebuild:admin-reconcile', [
            '--apply' => true,
            '--operator' => 'Security Operations',
            '--reason' => 'Record verified canonical state before the teaching pilot',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Already canonical', Artisan::output());
        $this->assertSame([RoleCapabilityMatrix::ROLE_ADMIN], $this->freshRoleSlugs($target));
        $this->assertSame($password, $target->fresh()->password);
        $this->assertTrue($updatedAt->equalTo($target->fresh()->updated_at));

        $event = AuditEvent::query()->sole();
        $this->assertFalse($event->metadata['roles_changed']);
        $this->assertFalse($event->metadata['status_changed']);
        $this->assertFalse($event->metadata['mutated']);
        $this->assertSame(0, $event->metadata['sessions_revoked']);
    }

    public function test_audit_failure_rolls_back_roles_status_and_session_revocation(): void
    {
        $target = $this->createTarget($this->knownDriftRoles());
        $this->createSession($target, 'rollback-session');

        $recorder = Mockery::mock(AuditRecorder::class);
        $expectation = $recorder->shouldReceive('record');
        $this->assertInstanceOf(CompositeExpectation::class, $expectation);
        $expectation->andReturn(null);
        $this->app->instance(AuditRecorder::class, $recorder);

        $exitCode = Artisan::call('rebuild:admin-reconcile', [
            '--apply' => true,
            '--disable' => true,
            '--operator' => 'Security Operations',
            '--reason' => 'Exercise transactional rollback when audit persistence fails',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('rolled back because its audit event could not be recorded', Artisan::output());
        $this->assertSame($this->knownDriftRoles(), $this->freshRoleSlugs($target));
        $this->assertSame('ACTIVE', $target->fresh()->status);
        $this->assertDatabaseHas('sessions', ['id' => 'rollback-session', 'user_id' => $target->id]);
        $this->assertDatabaseCount('audit_events', 0);
    }

    /**
     * @param  list<string>  $roles
     */
    private function createTarget(array $roles, bool $isSystemAdministrator = true): User
    {
        $target = User::factory()->create([
            'email' => config('simulation.rebuild_admin_email'),
            'status' => 'ACTIVE',
            'is_system_administrator' => $isSystemAdministrator,
        ]);

        $roleIds = Role::query()->whereIn('slug', $roles)->pluck('id')->all();
        $target->roles()->sync($roleIds);

        return $target;
    }

    private function createSession(User $target, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'reconciliation-test',
            'payload' => 'synthetic-session-payload',
            'last_activity' => now()->timestamp,
        ]);
    }

    /** @return list<string> */
    private function freshRoleSlugs(User $target): array
    {
        /** @var list<string> $roles */
        $roles = $target->fresh()
            ->roles()
            ->pluck('slug')
            ->map(static fn (mixed $slug): string => (string) $slug)
            ->sort()
            ->values()
            ->all();

        return $roles;
    }

    /** @return list<string> */
    private function knownDriftRoles(): array
    {
        return [
            RoleCapabilityMatrix::ROLE_ADMIN,
            RoleCapabilityMatrix::ROLE_NURSE,
            RoleCapabilityMatrix::ROLE_PHYSICIAN,
            RoleCapabilityMatrix::ROLE_REGISTRAR,
            RoleCapabilityMatrix::ROLE_RMIK,
        ];
    }
}
