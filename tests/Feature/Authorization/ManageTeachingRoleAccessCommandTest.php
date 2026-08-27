<?php

namespace Tests\Feature\Authorization;

use App\Models\Role;
use App\Models\TeachingRoleAccessLease;
use App\Models\User;
use App\Support\Audit\AuditActorAttribution;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Authorization\TeachingRoleAccessManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use Mockery\CompositeExpectation;
use Tests\TestCase;

class ManageTeachingRoleAccessCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TARGET = 'registrar.demo@example.invalid';

    private const DEMO_PASSWORD = 'hosted-demo-password-2026';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'simulation.mode' => 'SIMULATION',
            'simulation.synthetic_only' => true,
            'simulation.teaching_role_access_password' => self::DEMO_PASSWORD,
            'simulation.teaching_role_access_commitment_key' => 'test-only-teaching-access-commitment-key-2026',
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
    }

    public function test_activation_and_status_fail_closed_but_revocation_contains_mode_drift(): void
    {
        $this->createRoster();

        foreach ([
            ['mode' => 'PRODUCTION', 'synthetic_only' => true],
            ['mode' => 'SIMULATION', 'synthetic_only' => false],
        ] as $unsafe) {
            config([
                'simulation.mode' => $unsafe['mode'],
                'simulation.synthetic_only' => $unsafe['synthetic_only'],
            ]);

            foreach (['status', 'activate'] as $action) {
                $this->assertSame(1, Artisan::call('teaching:role-access', $this->arguments($action)));
                $this->assertStringContainsString('requires APP_MODE=SIMULATION', Artisan::output());
            }

            User::query()->where('email', self::TARGET)->sole()
                ->forceFill(['status' => 'TEACHING_ACTIVE', 'email_verified_at' => now()])
                ->save();
            $this->assertSame(0, Artisan::call('teaching:role-access', $this->arguments('revoke')));
            $this->assertSame('DISABLED', User::query()->where('email', self::TARGET)->sole()->status);
        }

        $this->assertDatabaseCount('audit_events', 2);
    }

    public function test_unknown_account_and_wrong_action_specific_confirmation_are_refused(): void
    {
        $this->createRoster();

        $this->assertSame(1, Artisan::call('teaching:role-access', [
            ...$this->arguments('revoke'),
            'email' => 'mahasiswa.rmik@example.invalid',
            '--confirm' => TeachingRoleAccessManager::confirmationPhrase('revoke', 'mahasiswa.rmik@example.invalid'),
        ]));
        $this->assertStringContainsString('not in the exact demo-account roster', Artisan::output());

        $this->assertSame(1, Artisan::call('teaching:role-access', [
            ...$this->arguments('activate'),
            '--confirm' => TeachingRoleAccessManager::confirmationPhrase('revoke', self::TARGET),
        ]));
        $this->assertStringContainsString('Confirmation refused', Artisan::output());
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_every_action_requires_bounded_non_placeholder_operator_and_reason(): void
    {
        $this->createRoster();

        foreach (['status', 'activate', 'revoke'] as $action) {
            $arguments = $this->arguments($action);
            $arguments['--operator'] = 'unknown';
            $this->assertSame(1, Artisan::call('teaching:role-access', $arguments));
            $this->assertStringContainsString('--operator must be a non-placeholder', Artisan::output());

            $arguments = $this->arguments($action);
            $arguments['--reason'] = 'todo';
            $this->assertSame(1, Artisan::call('teaching:role-access', $arguments));
            $this->assertStringContainsString('--reason must be a non-placeholder', Artisan::output());
        }
    }

    public function test_activation_refuses_role_or_administrator_drift_and_an_incomplete_roster(): void
    {
        $roster = $this->createRoster();
        $target = $roster[self::TARGET];
        $target->roles()->sync([
            Role::query()->where('slug', RoleCapabilityMatrix::ROLE_NURSE)->sole()->id,
        ]);

        $this->assertSame(1, Artisan::call('teaching:role-access', $this->arguments('activate')));
        $this->assertStringContainsString('exact expected role', Artisan::output());

        $target->roles()->sync([
            Role::query()->where('slug', RoleCapabilityMatrix::ROLE_REGISTRAR)->sole()->id,
        ]);
        $target->forceFill(['is_system_administrator' => true])->save();

        $this->assertSame(1, Artisan::call('teaching:role-access', $this->arguments('activate')));
        $this->assertStringContainsString('administrator drift', Artisan::output());

        $target->forceFill(['is_system_administrator' => false])->save();
        $roster['nurse.demo@example.invalid']->delete();

        $this->assertSame(1, Artisan::call('teaching:role-access', $this->arguments('activate')));
        $this->assertStringContainsString('four-account roster is incomplete', Artisan::output());
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_activation_refuses_when_any_other_exact_roster_account_is_active(): void
    {
        $roster = $this->createRoster();
        $roster['nurse.demo@example.invalid']->forceFill(['status' => 'TEACHING_ACTIVE'])->save();

        $this->assertSame(1, Artisan::call('teaching:role-access', $this->arguments('activate')));
        $this->assertStringContainsString('another roster account is active', Artisan::output());
        $this->assertSame('DISABLED', $roster[self::TARGET]->fresh()->status);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_activation_requires_every_non_target_roster_account_to_be_fully_closed(): void
    {
        $roster = $this->createRoster();
        $this->dirtyAuthenticationArtifacts($roster['nurse.demo@example.invalid']);

        $this->assertSame(1, Artisan::call('teaching:role-access', $this->arguments('activate')));
        $this->assertStringContainsString('non-target roster account is not fully closed', Artisan::output());
        $this->assertSame('DISABLED', $roster[self::TARGET]->fresh()->status);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_activation_refuses_a_disabled_target_with_dangling_active_lease_evidence(): void
    {
        $roster = $this->createRoster();
        $target = $roster[self::TARGET];
        $this->assertSame(0, Artisan::call('teaching:role-access', $this->arguments('revoke')));
        TeachingRoleAccessLease::query()->where('user_id', $target->id)->sole()->forceFill([
            'status' => 'ACTIVE',
            'active_slot' => null,
        ])->save();

        $this->assertSame(1, Artisan::call('teaching:role-access', $this->arguments('activate')));
        $this->assertStringContainsString('disabled target retains dangling lease evidence', Artisan::output());
        $this->assertSame('DISABLED', $target->fresh()->status);
    }

    public function test_database_rejects_a_second_active_slot_for_the_same_user(): void
    {
        $roster = $this->createRoster();
        $target = $roster[self::TARGET];
        $this->assertSame(0, Artisan::call('teaching:role-access', $this->arguments('activate')));
        $lease = TeachingRoleAccessLease::query()->where('user_id', $target->id)->sole();

        $this->expectException(QueryException::class);
        TeachingRoleAccessLease::query()->create([
            ...$lease->only([
                'user_id', 'expected_role', 'password_state_commitment', 'environment',
                'release_sha', 'deployment_url', 'canonical_host', 'status', 'active_slot',
                'activated_at_epoch', 'expires_at_epoch', 'operator', 'reason',
            ]),
            'public_id' => (string) Str::ulid(),
            'credential_commitment' => hash('sha256', 'synthetic-second-active-slot'),
        ]);
    }

    public function test_activation_requires_a_unique_environment_backed_access_value_of_at_least_twenty_four_bytes(): void
    {
        $this->createRoster();

        foreach ([null, 'too-short'] as $invalidPassword) {
            config(['simulation.teaching_role_access_password' => $invalidPassword]);
            $this->assertSame(1, Artisan::call('teaching:role-access', $this->arguments('activate')));
            $this->assertStringContainsString('TEACHING_ROLE_ACCESS_PASSWORD with at least 24 bytes', Artisan::output());
        }

        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_activation_and_status_require_database_sessions_but_revocation_still_contains(): void
    {
        $this->createRoster();
        config(['session.driver' => 'array']);

        foreach (['status', 'activate'] as $action) {
            $this->assertSame(1, Artisan::call('teaching:role-access', $this->arguments($action)));
            $this->assertStringContainsString('requires the database session driver', Artisan::output());
        }

        User::query()->where('email', self::TARGET)->sole()
            ->forceFill(['status' => 'TEACHING_ACTIVE', 'email_verified_at' => now()])
            ->save();
        $this->assertSame(0, Artisan::call('teaching:role-access', $this->arguments('revoke')));
        $this->assertSame('DISABLED', User::query()->where('email', self::TARGET)->sole()->status);
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertDatabaseCount('teaching_role_access_leases', 1);
    }

    public function test_runtime_bindings_and_activation_ttl_must_match_trusted_configuration(): void
    {
        $this->createRoster();
        $arguments = $this->arguments('activate');
        $arguments['--expected-environment'] = 'wrong-environment';
        $this->assertSame(1, Artisan::call('teaching:role-access', $arguments));
        $this->assertStringContainsString('does not match the trusted runtime binding', Artisan::output());

        $arguments = $this->arguments('activate');
        $arguments['--expected-release-sha'] = str_repeat('b', 40);
        $this->assertSame(1, Artisan::call('teaching:role-access', $arguments));
        $this->assertStringContainsString('does not match the trusted runtime binding', Artisan::output());

        foreach ([
            '--expected-deployment-url' => 'wrong-deployment.vercel.app',
            '--expected-canonical-host' => 'wrong-canonical.example.invalid',
        ] as $option => $value) {
            $arguments = $this->arguments('activate');
            $arguments[$option] = $value;
            $this->assertSame(1, Artisan::call('teaching:role-access', $arguments));
            $this->assertStringContainsString('does not match the trusted runtime binding', Artisan::output());

            $arguments[$option] = null;
            $this->assertSame(1, Artisan::call('teaching:role-access', $arguments));
            $this->assertStringContainsString('does not match the trusted runtime binding', Artisan::output());
        }

        foreach ([4, 31] as $ttl) {
            $arguments = $this->arguments('activate');
            $arguments['--ttl-minutes'] = $ttl;
            $this->assertSame(1, Artisan::call('teaching:role-access', $arguments));
            $this->assertStringContainsString('TTL must be between 5 minutes', Artisan::output());
        }

        $this->assertDatabaseCount('audit_events', 0);
        $this->assertDatabaseCount('teaching_role_access_leases', 0);
    }

    public function test_activation_refuses_a_dirty_target_without_silently_cleaning_it(): void
    {
        $roster = $this->createRoster();
        $target = $roster[self::TARGET];
        $target->forceFill(['status' => 'ACTIVE'])->save();
        $this->dirtyAuthenticationArtifacts($target);
        $beforePassword = $target->fresh()->password;

        $this->assertSame(1, Artisan::call('teaching:role-access', $this->arguments('activate')));
        $this->assertStringContainsString('target account is not in the exact fully closed state', Artisan::output());
        $this->assertSame('ACTIVE', $target->fresh()->status);
        $this->assertSame($beforePassword, $target->fresh()->password);
        $this->assertDatabaseHas('sessions', ['user_id' => $target->id]);
        $this->assertDatabaseHas('passkeys', ['user_id' => $target->id]);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $target->email]);
        $this->assertDatabaseCount('teaching_role_access_leases', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_activation_rotates_password_verifies_and_enables_only_an_exact_clean_target(): void
    {
        $roster = $this->createRoster();
        $target = $roster[self::TARGET];
        $originalHash = $target->password;

        $exitCode = Artisan::call('teaching:role-access', $this->arguments('activate'));
        $output = Artisan::output();
        $this->assertSame(0, $exitCode, $output);
        $fresh = $target->fresh();

        $this->assertSame('TEACHING_ACTIVE', $fresh->status);
        $this->assertNotNull($fresh->email_verified_at);
        $this->assertFalse($fresh->is_system_administrator);
        $this->assertSame([RoleCapabilityMatrix::ROLE_REGISTRAR], $this->roleSlugs($fresh));
        $this->assertNotSame($originalHash, $fresh->password);
        $this->assertTrue(Hash::check(self::DEMO_PASSWORD, $fresh->password));
        $this->assertClearedAuthenticationArtifacts($fresh);

        foreach ($roster as $email => $user) {
            if ($email !== self::TARGET) {
                $this->assertSame('DISABLED', $user->fresh()->status);
            }
        }

        $this->assertStringContainsString('Result: ACTIVATE completed (mutated=yes, idempotent=no, audit=recorded)', $output);
        $this->assertStringContainsString('Roster: active=1 disabled=3 missing=0 drifted=0', $output);
        $this->assertStringNotContainsString(self::DEMO_PASSWORD, $output);

        $event = AuditEvent::query()->where('action', TeachingRoleAccessManager::AUDIT_ACTIVATED)->sole();
        $this->assertSame(AuditActorAttribution::TYPE_SERVICE, $event->actor_type);
        $this->assertSame(AuditActorAttribution::TEACHING_ROLE_ACCESS_SERVICE, $event->actor_reference);
        $this->assertNull($event->actor_user_id);
        $this->assertSame($target->public_id, $event->resource_id);
        $this->assertSame('Security Operations', $event->metadata['operator']);
        $this->assertSame('Temporary hosted teaching laboratory access', $event->reason);
        $this->assertEquals([
            'sessions' => 0,
            'passkeys' => 0,
            'reset_records' => 0,
        ], $event->metadata['revoked']);
        $this->assertTrue($event->metadata['login_material_rotated']);
        $this->assertFalse($event->metadata['idempotent']);
        $this->assertFalse($event->metadata['after']['remember_present']);
        $this->assertFalse($event->metadata['after']['mfa_present']);
        $this->assertStringNotContainsString(self::DEMO_PASSWORD, json_encode($event->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_activation_rolls_back_user_and_artifact_changes_when_audit_persistence_fails(): void
    {
        $roster = $this->createRoster();
        $target = $roster[self::TARGET];
        $before = $target->fresh()->getAttributes();

        $recorder = Mockery::mock(AuditRecorder::class);
        $expectation = $recorder->shouldReceive('record');
        $this->assertInstanceOf(CompositeExpectation::class, $expectation);
        $expectation->andReturn(null);
        $this->app->instance(AuditRecorder::class, $recorder);

        $this->assertSame(1, Artisan::call('teaching:role-access', $this->arguments('activate')));
        $this->assertStringContainsString('rolled back because its audit event could not be recorded', Artisan::output());
        $fresh = $target->fresh();
        $this->assertSame($before['status'], $fresh->status);
        $this->assertSame($before['password'], $fresh->password);
        $this->assertSame($before['email_verified_at'], $fresh->getRawOriginal('email_verified_at'));
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_post_commit_activation_drift_is_compensated_and_audited(): void
    {
        $roster = $this->createRoster();
        $target = $roster[self::TARGET];
        $this->app->instance(AuditRecorder::class, $this->postCommitDriftRecorder($target->id));

        $this->assertSame(1, Artisan::call('teaching:role-access', $this->arguments('activate')));
        $this->assertStringContainsString('failed closed because final readback did not confirm', Artisan::output());

        $fresh = $target->fresh();
        $this->assertSame('DISABLED', $fresh->status);
        $this->assertNull($fresh->teaching_access_lease_public_id);
        $this->assertNull($fresh->teaching_access_expires_at_epoch);
        $this->assertFalse(Hash::check(self::DEMO_PASSWORD, $fresh->password));
        $this->assertClearedAuthenticationArtifacts($fresh);
        $this->assertDatabaseMissing('teaching_role_access_leases', [
            'user_id' => $target->id,
            'status' => 'ACTIVE',
        ]);
        $this->assertDatabaseHas('teaching_role_access_leases', [
            'user_id' => $target->id,
            'status' => 'COMPENSATED',
        ]);
        $this->assertSame(
            [TeachingRoleAccessManager::AUDIT_ACTIVATED, TeachingRoleAccessManager::AUDIT_COMPENSATED],
            AuditEvent::query()->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_compensation_audit_failure_preserves_containment_and_is_not_swallowed(): void
    {
        $roster = $this->createRoster();
        $target = $roster[self::TARGET];
        $this->app->instance(AuditRecorder::class, $this->postCommitDriftRecorder($target->id, true));

        $this->assertSame(1, Artisan::call('teaching:role-access', $this->arguments('activate')));
        $this->assertStringContainsString(
            'compensation completed containment but could not record its audit evidence',
            Artisan::output(),
        );

        $fresh = $target->fresh();
        $this->assertSame('DISABLED', $fresh->status);
        $this->assertNull($fresh->teaching_access_lease_public_id);
        $this->assertNull($fresh->teaching_access_expires_at_epoch);
        $this->assertFalse(Hash::check(self::DEMO_PASSWORD, $fresh->password));
        $this->assertClearedAuthenticationArtifacts($fresh);
        $this->assertDatabaseMissing('teaching_role_access_leases', [
            'user_id' => $target->id,
            'status' => 'ACTIVE',
        ]);
        $this->assertDatabaseHas('teaching_role_access_leases', [
            'user_id' => $target->id,
            'status' => 'COMPENSATED',
        ]);
        $this->assertSame(
            [TeachingRoleAccessManager::AUDIT_ACTIVATED],
            AuditEvent::query()->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_activation_is_idempotent_only_for_the_exact_clean_target_state_with_no_other_active_roster_account(): void
    {
        $roster = $this->createRoster();
        $target = $roster[self::TARGET];
        $this->assertSame(0, Artisan::call('teaching:role-access', $this->arguments('activate')));
        $beforeHash = $target->fresh()->password;
        $beforeUpdatedAt = $target->fresh()->updated_at;

        $this->assertSame(0, Artisan::call('teaching:role-access', $this->arguments('activate')));
        $this->assertStringContainsString(
            'Result: ACTIVATE completed (mutated=no, idempotent=yes, audit=recorded)',
            Artisan::output(),
        );
        $this->assertSame($beforeHash, $target->fresh()->password);
        $this->assertTrue($beforeUpdatedAt->equalTo($target->fresh()->updated_at));

        $events = AuditEvent::query()->orderBy('id')->get();
        $this->assertCount(2, $events);
        $event = $events->last();
        $this->assertInstanceOf(AuditEvent::class, $event);
        $this->assertTrue($event->metadata['idempotent']);
        $this->assertFalse($event->metadata['login_material_rotated']);
        $this->assertSame($event->metadata['before'], $event->metadata['after']);
    }

    public function test_revoke_disables_and_rotates_to_unknown_password_then_repeated_revoke_is_idempotent(): void
    {
        $roster = $this->createRoster();
        $target = $roster[self::TARGET];
        $target->forceFill([
            'status' => 'ACTIVE',
            'email_verified_at' => now(),
            'password' => self::DEMO_PASSWORD,
        ])->save();
        $this->dirtyAuthenticationArtifacts($target);
        $knownHash = $target->fresh()->password;

        $this->assertSame(0, Artisan::call('teaching:role-access', $this->arguments('revoke')));
        $firstOutput = Artisan::output();
        $fresh = $target->fresh();
        $revokedHash = $fresh->password;

        $this->assertSame('DISABLED', $fresh->status);
        $this->assertNull($fresh->email_verified_at);
        $this->assertNotSame($knownHash, $revokedHash);
        $this->assertFalse(Hash::check(self::DEMO_PASSWORD, $revokedHash));
        $this->assertClearedAuthenticationArtifacts($fresh);
        $this->assertStringContainsString('Result: REVOKE completed (mutated=yes, idempotent=no, audit=recorded)', $firstOutput);

        $this->assertSame(0, Artisan::call('teaching:role-access', $this->arguments('revoke')));
        $this->assertStringContainsString('Result: REVOKE completed (mutated=no, idempotent=yes, audit=recorded)', Artisan::output());
        $this->assertSame($revokedHash, $target->fresh()->password);

        $events = AuditEvent::query()
            ->where('action', TeachingRoleAccessManager::AUDIT_REVOKED)
            ->orderBy('recorded_at')
            ->get();
        $this->assertCount(2, $events);
        $this->assertFalse($events[0]->metadata['idempotent']);
        $this->assertTrue($events[1]->metadata['idempotent']);
        $this->assertFalse($events[1]->metadata['login_material_rotated']);
        $this->assertSame($events[1]->metadata['before'], $events[1]->metadata['after']);
    }

    public function test_revoke_containment_commits_even_when_audit_persistence_fails(): void
    {
        $roster = $this->createRoster();
        $target = $roster[self::TARGET];
        $this->assertSame(0, Artisan::call('teaching:role-access', $this->arguments('activate')));
        $this->dirtyAuthenticationArtifacts($target);
        $beforeEpoch = (int) $target->fresh()->teaching_access_epoch;

        $recorder = Mockery::mock(AuditRecorder::class);
        $expectation = $recorder->shouldReceive('record');
        $this->assertInstanceOf(CompositeExpectation::class, $expectation);
        $expectation->andReturn(null);
        $this->app->instance(AuditRecorder::class, $recorder);

        $this->assertSame(1, Artisan::call('teaching:role-access', $this->arguments('revoke')));
        $this->assertStringContainsString('completed containment but could not record its audit evidence', Artisan::output());

        $fresh = $target->fresh();
        $this->assertSame('DISABLED', $fresh->status);
        $this->assertGreaterThan($beforeEpoch, $fresh->teaching_access_epoch);
        $this->assertFalse(Hash::check(self::DEMO_PASSWORD, $fresh->password));
        $this->assertClearedAuthenticationArtifacts($fresh);
        $this->assertDatabaseMissing('teaching_role_access_leases', [
            'user_id' => $target->id,
            'status' => 'ACTIVE',
        ]);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_revoke_audit_refuses_evidence_when_the_containment_version_drifts(): void
    {
        $roster = $this->createRoster();
        $target = $roster[self::TARGET];
        $this->assertSame(0, Artisan::call('teaching:role-access', $this->arguments('activate')));
        $driftInjected = false;
        User::retrieved(function (User $updated) use ($target, &$driftInjected): void {
            if (! $driftInjected
                && $updated->getKey() === $target->getKey()
                && $updated->status === 'DISABLED') {
                $driftInjected = true;
                DB::table('users')->where('id', $updated->getKey())->increment('teaching_access_mutex');
            }
        });

        $this->assertSame(1, Artisan::call('teaching:role-access', $this->arguments('revoke')));
        $this->assertStringContainsString(
            'completed containment but could not record its audit evidence',
            Artisan::output(),
        );
        $this->assertSame('DISABLED', $target->fresh()->status);
        $this->assertNull($target->fresh()->teaching_access_lease_public_id);
        $this->assertDatabaseMissing('teaching_role_access_leases', [
            'user_id' => $target->id,
            'status' => 'ACTIVE',
        ]);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_revoke_closes_every_dangling_active_lease_for_the_target(): void
    {
        $roster = $this->createRoster();
        $target = $roster[self::TARGET];
        $this->assertSame(0, Artisan::call('teaching:role-access', $this->arguments('activate')));
        $lease = TeachingRoleAccessLease::query()->where('user_id', $target->id)->sole();

        $duplicate = [
            ...$lease->only([
                'user_id', 'expected_role', 'password_state_commitment', 'environment',
                'release_sha', 'deployment_url', 'canonical_host', 'status',
                'activated_at_epoch', 'expires_at_epoch', 'operator', 'reason',
            ]),
            'public_id' => (string) Str::ulid(),
            'credential_commitment' => hash('sha256', 'synthetic-dangling-access-window'),
        ];
        if (DB::connection()->getDriverName() === 'pgsql') {
            try {
                DB::transaction(fn () => TeachingRoleAccessLease::query()->create($duplicate));
                $this->fail('PostgreSQL must reject a second ACTIVE lease for one user.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('tral_one_active_per_user_uq', $exception->getMessage());
            }

            $this->assertSame(0, Artisan::call('teaching:role-access', $this->arguments('revoke')));
            $this->assertDatabaseMissing('teaching_role_access_leases', [
                'user_id' => $target->id,
                'status' => 'ACTIVE',
            ]);

            return;
        }
        TeachingRoleAccessLease::query()->create($duplicate);

        $this->assertDatabaseCount('teaching_role_access_leases', 2);
        $this->assertSame(0, Artisan::call('teaching:role-access', $this->arguments('revoke')));
        $this->assertDatabaseMissing('teaching_role_access_leases', [
            'user_id' => $target->id,
            'status' => 'ACTIVE',
        ]);
        $this->assertSame(2, TeachingRoleAccessLease::query()
            ->where('user_id', $target->id)
            ->where('status', 'REVOKED')
            ->count());
    }

    public function test_revoke_contains_every_candidate_when_email_and_roster_marker_are_split(): void
    {
        $roster = $this->createRoster();
        $emailCandidate = $roster[self::TARGET];
        if (DB::connection()->getDriverName() === 'pgsql') {
            try {
                DB::transaction(function () use ($emailCandidate): void {
                    $emailCandidate->forceFill(['teaching_access_roster_key' => null])->save();
                });
                $this->fail('PostgreSQL must reject split teaching-roster identity drift.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('Teaching roster identity is immutable', $exception->getMessage());
            }

            $this->assertSame(RoleCapabilityMatrix::ROLE_REGISTRAR, $emailCandidate->fresh()->teaching_access_roster_key);

            return;
        }
        $emailCandidate->forceFill([
            'status' => 'TEACHING_ACTIVE',
            'teaching_access_roster_key' => null,
            'email_verified_at' => now(),
            'password' => self::DEMO_PASSWORD,
        ])->save();
        $markerCandidate = User::factory()->create([
            'email' => 'drifted-registrar@example.invalid',
            'status' => 'TEACHING_ACTIVE',
            'teaching_access_roster_key' => RoleCapabilityMatrix::ROLE_REGISTRAR,
            'email_verified_at' => now(),
            'password' => self::DEMO_PASSWORD,
        ]);
        $markerCandidate->roles()->sync([
            Role::query()->where('slug', RoleCapabilityMatrix::ROLE_REGISTRAR)->sole()->id,
        ]);

        $this->assertSame(1, Artisan::call('teaching:role-access', $this->arguments('revoke')));
        $this->assertStringContainsString('contained multiple matching roster identities', Artisan::output());

        foreach ([$emailCandidate, $markerCandidate] as $candidate) {
            $fresh = $candidate->fresh();
            $this->assertSame('DISABLED', $fresh->status);
            $this->assertFalse(Hash::check(self::DEMO_PASSWORD, $fresh->password));
            $this->assertNull($fresh->teaching_access_lease_public_id);
        }
        $this->assertDatabaseMissing('teaching_role_access_leases', ['status' => 'ACTIVE']);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_an_access_value_cannot_be_reused_after_its_window_is_revoked(): void
    {
        $this->createRoster();
        $this->assertSame(0, Artisan::call('teaching:role-access', $this->arguments('activate')));
        $this->assertSame(0, Artisan::call('teaching:role-access', $this->arguments('revoke')));

        $this->assertSame(1, Artisan::call('teaching:role-access', $this->arguments('activate')));
        $this->assertStringContainsString('already used for an earlier window', Artisan::output());
        $this->assertSame('DISABLED', User::query()->where('email', self::TARGET)->sole()->status);
        $this->assertDatabaseCount('teaching_role_access_leases', 1);
    }

    public function test_revoke_idempotency_is_bound_to_the_current_unknown_login_state(): void
    {
        $roster = $this->createRoster();
        $target = $roster[self::TARGET];
        $this->assertSame(0, Artisan::call('teaching:role-access', $this->arguments('revoke')));
        $firstRevokedHash = $target->fresh()->password;

        $target->forceFill(['password' => 'externally-known-login-value-2026'])->save();
        $this->assertSame(0, Artisan::call('teaching:role-access', $this->arguments('revoke')));

        $latest = $target->fresh();
        $this->assertNotSame($firstRevokedHash, $latest->password);
        $this->assertFalse(Hash::check('externally-known-login-value-2026', $latest->password));
        $event = AuditEvent::query()->where('action', TeachingRoleAccessManager::AUDIT_REVOKED)
            ->orderByDesc('id')->firstOrFail();
        $this->assertFalse($event->metadata['idempotent']);
        $this->assertTrue($event->metadata['login_material_rotated']);

        $latestHash = $latest->password;
        $this->assertSame(0, Artisan::call('teaching:role-access', $this->arguments('revoke')));
        $this->assertStringContainsString('idempotent=yes', Artisan::output());
        $this->assertSame($latestHash, $target->fresh()->password);
        $this->assertDatabaseCount('teaching_role_access_leases', 2);
    }

    public function test_status_is_secret_free_and_reports_exact_roster_state_and_artifact_counts(): void
    {
        $roster = $this->createRoster();
        $target = $roster[self::TARGET];
        $target->forceFill(['status' => 'TEACHING_ACTIVE'])->save();
        $this->dirtyAuthenticationArtifacts($target);

        $this->assertSame(0, Artisan::call('teaching:role-access', $this->arguments('status')));
        $output = Artisan::output();

        foreach (TeachingRoleAccessManager::ROSTER as $email => $role) {
            $this->assertStringContainsString("{$email} roles={$role} expected={$role}", $output);
        }
        $this->assertStringContainsString('status=TEACHING_ACTIVE', $output);
        $this->assertStringContainsString('sessions=1 passkeys=1 resets=1 lease=closed invariant=DRIFT', $output);
        $this->assertStringContainsString('Roster: active=1 disabled=3 missing=0 drifted=1 sessions=1 passkeys=1 resets=1', $output);
        $this->assertStringNotContainsString(self::DEMO_PASSWORD, $output);
        $this->assertStringNotContainsString('synthetic-two-factor-material', $output);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_status_classifies_split_roster_identity_as_ambiguous_without_first_match_selection(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->markTestSkipped('PostgreSQL prevents split roster identity before readback.');
        }

        $roster = $this->createRoster();
        $roster[self::TARGET]->forceFill(['teaching_access_roster_key' => null])->save();
        User::factory()->unverified()->create([
            'email' => 'split-registrar@example.invalid',
            'status' => 'DISABLED',
            'teaching_access_roster_key' => RoleCapabilityMatrix::ROLE_REGISTRAR,
            'remember_token' => null,
        ]);

        $this->assertSame(0, Artisan::call('teaching:role-access', $this->arguments('status')));
        $output = Artisan::output();
        $this->assertStringContainsString(
            self::TARGET.' roles=none expected=registrar status=AMBIGUOUS',
            $output,
        );
        $this->assertStringContainsString('Roster: active=0 disabled=3 missing=0 drifted=1', $output);
    }

    public function test_audit_attribution_is_whitespace_normalized_and_bounded_without_secret_or_hash_fields(): void
    {
        $this->createRoster();
        $arguments = $this->arguments('activate');
        $arguments['--operator'] = '  Security   Operations  ';
        $arguments['--reason'] = '  Time-boxed   teaching access for the hosted laboratory  ';

        $this->assertSame(0, Artisan::call('teaching:role-access', $arguments));
        $event = AuditEvent::query()->sole();
        $serialized = json_encode($event->metadata, JSON_THROW_ON_ERROR);

        $this->assertSame('Security Operations', $event->metadata['operator']);
        $this->assertSame('Time-boxed teaching access for the hosted laboratory', $event->metadata['reason']);
        $this->assertSame($event->reason, $event->metadata['reason']);
        $this->assertDoesNotMatchRegularExpression('/password|hash|secret|token|credential/i', $serialized);
    }

    /** @return array<string, mixed> */
    private function arguments(string $action): array
    {
        return [
            'action' => $action,
            'email' => self::TARGET,
            '--confirm' => TeachingRoleAccessManager::confirmationPhrase($action, self::TARGET),
            '--operator' => 'Security Operations',
            '--reason' => 'Temporary hosted teaching laboratory access',
            '--expected-environment' => 'test-simulation',
            '--expected-release-sha' => str_repeat('a', 40),
            '--expected-deployment-url' => 'test-deployment.vercel.app',
            '--expected-canonical-host' => 'localhost',
            '--ttl-minutes' => 15,
        ];
    }

    private function postCommitDriftRecorder(int $targetId, bool $failCompensationAudit = false): AuditRecorder
    {
        return new class($targetId, $failCompensationAudit) extends AuditRecorder
        {
            private bool $driftScheduled = false;

            public function __construct(
                private readonly int $targetId,
                private readonly bool $failCompensationAudit,
            ) {}

            /** @param array<string, mixed> $metadata */
            public function record(
                string $action,
                string $resourceType,
                ?string $resourceId = null,
                ?User $actor = null,
                string $outcome = 'SUCCESS',
                ?string $reason = null,
                array $metadata = [],
                ?Request $request = null,
                bool $includeRequestFingerprint = true,
            ): ?AuditEvent {
                if ($action === TeachingRoleAccessManager::AUDIT_COMPENSATED
                    && $this->failCompensationAudit) {
                    return null;
                }

                $event = parent::record(
                    action: $action,
                    resourceType: $resourceType,
                    resourceId: $resourceId,
                    actor: $actor,
                    outcome: $outcome,
                    reason: $reason,
                    metadata: $metadata,
                    request: $request,
                    includeRequestFingerprint: $includeRequestFingerprint,
                );

                if ($action === TeachingRoleAccessManager::AUDIT_ACTIVATED
                    && ! $this->driftScheduled) {
                    $this->driftScheduled = true;
                    DB::afterCommit(function (): void {
                        User::query()->whereKey($this->targetId)->update([
                            'teaching_access_lease_public_id' => (string) Str::ulid(),
                        ]);
                    });
                }

                return $event;
            }
        };
    }

    /** @return array<string, User> */
    private function createRoster(): array
    {
        $roster = [];

        foreach (TeachingRoleAccessManager::ROSTER as $email => $role) {
            $user = User::factory()->unverified()->create([
                'email' => $email,
                'status' => 'DISABLED',
                'is_system_administrator' => false,
                'teaching_access_roster_key' => $role,
                'password' => 'initial-known-password',
                'remember_token' => null,
            ]);
            $user->roles()->sync([
                Role::query()->where('slug', $role)->sole()->id,
            ]);
            $roster[$email] = $user;
        }

        return $roster;
    }

    private function dirtyAuthenticationArtifacts(User $target): void
    {
        $target->forceFill([
            'remember_token' => 'remember-me-material',
            'two_factor_secret' => 'synthetic-two-factor-material',
            'two_factor_recovery_codes' => 'synthetic-recovery-material',
            'two_factor_confirmed_at' => now(),
        ])->save();

        DB::table('sessions')->insert([
            'id' => 'teaching-role-session-'.$target->id,
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'teaching-role-test',
            'payload' => 'synthetic-session-payload',
            'last_activity' => now()->timestamp,
        ]);
        DB::table('passkeys')->insert([
            'user_id' => $target->id,
            'name' => 'Synthetic test passkey',
            'credential_id' => 'credential-'.$target->id,
            'credential' => json_encode(['synthetic' => true], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => $target->email,
            'token' => hash('sha256', 'synthetic-reset-material'),
            'created_at' => now(),
        ]);
    }

    private function assertClearedAuthenticationArtifacts(User $target): void
    {
        $this->assertNull($target->remember_token);
        $this->assertNull($target->two_factor_secret);
        $this->assertNull($target->two_factor_recovery_codes);
        $this->assertNull($target->two_factor_confirmed_at);
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
        $this->assertDatabaseMissing('passkeys', ['user_id' => $target->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $target->email]);
    }

    /** @return list<string> */
    private function roleSlugs(User $user): array
    {
        /** @var list<string> $roles */
        $roles = $user->roles()
            ->pluck('slug')
            ->map(static fn (mixed $role): string => (string) $role)
            ->sort()
            ->values()
            ->all();

        return $roles;
    }
}
