<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use App\Modules\Teaching\Services\ReservedDemoAccountRoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;

class LaboratoryAccessCommandTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    private const DISABLE_CONFIRMATION = 'DISABLE-RESERVED-DEMO-ACCESS';

    private const ENABLE_CONFIRMATION = 'ENABLE-RESERVED-DEMO-ACCESS';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'session.driver' => 'database',
            'session.table' => 'sessions',
        ]);
    }

    public function test_laboratory_access_command_is_registered(): void
    {
        $exitCode = Artisan::call('list', ['--raw' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('simulation:lab-access', Artisan::output());
    }

    public function test_command_fails_closed_without_safe_runtime_and_exact_confirmation(): void
    {
        $this->seedReferenceOutpatient();
        $before = $this->reservedUsers()->pluck('status', 'email')->all();
        $protectedPassword = 'DO-NOT-ECHO-TEMPORARY-PASSWORD';
        $protectedConfirmation = 'DO-NOT-ECHO-CONFIRMATION';

        $this->app->detectEnvironment(fn (): string => 'production');
        config()->set([
            'simulation.mode' => 'CLINICAL',
            'simulation.synthetic_only' => false,
            'simulation.demo_seed_enabled' => false,
            'simulation.demo_account_password' => $protectedPassword,
            'session.driver' => 'file',
        ]);

        $exitCode = Artisan::call('simulation:lab-access', [
            'action' => 'enable',
            '--confirm' => $protectedConfirmation,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"status":"BLOCKED"', $output);

        foreach ([
            'environment.production',
            'simulation.mode',
            'simulation.synthetic_only',
            'simulation.demo_seed_enabled',
            'session.revocable_backend',
            'confirmation.required',
        ] as $blockerId) {
            $this->assertStringContainsString($blockerId, $output);
        }

        $this->assertStringNotContainsString($protectedPassword, $output);
        $this->assertStringNotContainsString($protectedConfirmation, $output);
        $this->assertSame($before, $this->reservedUsers()->pluck('status', 'email')->all());
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_disable_revokes_only_reserved_authentication_artifacts_and_minimizes_audit_identity(): void
    {
        $this->seedReferenceOutpatient();
        $reservedUsers = $this->reservedUsers();
        $target = $reservedUsers->firstOrFail();
        $unrelated = User::factory()->create([
            'email' => 'unrelated-laboratory-user@example.invalid',
            'status' => 'ACTIVE',
        ]);
        $target->forceFill([
            'remember_token' => 'reserved-remember-token',
            'two_factor_secret' => 'reserved-two-factor-secret',
            'two_factor_recovery_codes' => 'reserved-recovery-codes',
            'two_factor_confirmed_at' => now(),
        ])->save();
        $this->insertAuthenticationArtifacts($target, 'reserved');
        $this->insertAuthenticationArtifacts($unrelated, 'unrelated');

        $exitCode = Artisan::call('simulation:lab-access', [
            'action' => 'disable',
            '--confirm' => self::DISABLE_CONFIRMATION,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode, $output);
        $this->assertSame('DISABLED', $report['status']);
        $this->assertSame(10, $report['summary']['accountCount']);
        $this->assertSame(1, $report['summary']['sessionsRevoked']);
        $this->assertSame(1, $report['summary']['passkeysRemoved']);
        $this->assertSame(1, $report['summary']['resetTokensRemoved']);
        $this->assertFalse($report['summary']['passwordRotated']);

        foreach ($this->reservedUsers() as $user) {
            $this->assertSame('SUSPENDED', $user->status);
            $this->assertNull($user->remember_token);
            $this->assertNull($user->two_factor_secret);
            $this->assertNull($user->two_factor_recovery_codes);
            $this->assertNull($user->two_factor_confirmed_at);
        }

        $this->assertSame('ACTIVE', $unrelated->fresh()->status);
        $this->assertAuthenticationArtifactsExist($unrelated, 'unrelated');
        $this->assertReservedAuthenticationArtifactsAbsent();

        $event = AuditEvent::query()->where('action', 'lab_access.disabled')->sole();
        $this->assertNull($event->actor_user_id);
        $this->assertNull($event->assignment_id);
        $this->assertNull($event->session_id);
        $this->assertNull($event->patient_id);
        $this->assertNull($event->encounter_id);
        $this->assertNull($event->ip_hash);
        $this->assertNull($event->user_agent);
        $expectedMetadata = [
            'account_count' => 10,
            'sessions_revoked' => 1,
            'passkeys_removed' => 1,
            'reset_tokens_removed' => 1,
            'password_rotated' => false,
            'authentication_factors_cleared' => true,
        ];
        $actualMetadata = $event->metadata;
        ksort($expectedMetadata);
        ksort($actualMetadata);
        $this->assertSame($expectedMetadata, $actualMetadata);

        foreach ([
            ...app(ReservedDemoAccountRoster::class)->emails(),
            'local-demo-password-only',
            'reserved-remember-token',
            'reserved-two-factor-secret',
        ] as $protectedValue) {
            $this->assertStringNotContainsString($protectedValue, $output);
            $this->assertStringNotContainsString(
                $protectedValue,
                json_encode($event->metadata, JSON_THROW_ON_ERROR),
            );
        }

        $this->post('/login', [
            'email' => $target->email,
            'password' => 'local-demo-password-only',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_disable_is_idempotent_and_does_not_duplicate_the_audit_event(): void
    {
        $this->seedReferenceOutpatient();

        $this->artisan('simulation:lab-access', [
            'action' => 'disable',
            '--confirm' => self::DISABLE_CONFIRMATION,
            '--json' => true,
        ])->assertSuccessful();

        $exitCode = Artisan::call('simulation:lab-access', [
            'action' => 'disable',
            '--confirm' => self::DISABLE_CONFIRMATION,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('UNCHANGED', $report['status']);
        $this->assertFalse($report['summary']['changed']);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_enable_rotates_password_clears_stale_authentication_and_is_idempotent(): void
    {
        $this->seedReferenceOutpatient();

        $this->artisan('simulation:lab-access', [
            'action' => 'disable',
            '--confirm' => self::DISABLE_CONFIRMATION,
        ])->assertSuccessful();

        $target = $this->reservedUsers()->firstOrFail();
        $target->forceFill([
            'remember_token' => 'stale-remember-token',
            'two_factor_secret' => 'stale-two-factor-secret',
            'two_factor_recovery_codes' => 'stale-recovery-codes',
            'two_factor_confirmed_at' => now(),
        ])->save();
        $this->insertAuthenticationArtifacts($target, 'stale');
        $newPassword = 'fresh-temporary-lab-password';
        config(['simulation.demo_account_password' => $newPassword]);

        $exitCode = Artisan::call('simulation:lab-access', [
            'action' => 'enable',
            '--confirm' => self::ENABLE_CONFIRMATION,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode, $output);
        $this->assertSame('ENABLED', $report['status']);
        $this->assertTrue($report['summary']['passwordRotated']);
        $this->assertStringNotContainsString($newPassword, $output);
        $this->assertReservedAuthenticationArtifactsAbsent();

        foreach ($this->reservedUsers() as $user) {
            $this->assertSame('ACTIVE', $user->status);
            $this->assertNotNull($user->email_verified_at);
            $this->assertTrue(Hash::check($newPassword, $user->password));
            $this->assertFalse(Hash::check('local-demo-password-only', $user->password));
            $this->assertNull($user->remember_token);
            $this->assertNull($user->two_factor_secret);
            $this->assertNull($user->two_factor_recovery_codes);
            $this->assertNull($user->two_factor_confirmed_at);
        }

        $enabledEvent = AuditEvent::query()->where('action', 'lab_access.enabled')->sole();
        $this->assertTrue($enabledEvent->metadata['password_rotated']);
        $this->assertStringNotContainsString(
            $newPassword,
            json_encode($enabledEvent->metadata, JSON_THROW_ON_ERROR),
        );

        $this->artisan('simulation:lab-access', [
            'action' => 'enable',
            '--confirm' => self::ENABLE_CONFIRMATION,
            '--json' => true,
        ])->expectsOutputToContain('"status":"UNCHANGED"')
            ->assertSuccessful();
        $this->assertDatabaseCount('audit_events', 2);
    }

    public function test_command_refuses_an_incomplete_source_roster_without_mutation(): void
    {
        $this->seedReferenceOutpatient();
        $source = SimulationSession::query()->where('code', 'SIM-RJ-UEU-001')->sole();
        $assignment = Assignment::query()->where('session_id', $source->getKey())->firstOrFail();
        $before = $this->reservedUsers()->pluck('status', 'email')->all();

        $assignment->delete();

        $exitCode = Artisan::call('simulation:lab-access', [
            'action' => 'disable',
            '--confirm' => self::DISABLE_CONFIRMATION,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"status":"BLOCKED"', $output);
        $this->assertStringContainsString('access.transition', $output);
        $this->assertSame($before, $this->reservedUsers()->pluck('status', 'email')->all());
        $this->assertDatabaseCount('audit_events', 0);

        foreach (app(ReservedDemoAccountRoster::class)->emails() as $email) {
            $this->assertStringNotContainsString($email, $output);
        }
    }

    public function test_late_audit_failure_rolls_back_account_and_authentication_changes(): void
    {
        $this->seedReferenceOutpatient();
        $target = $this->reservedUsers()->firstOrFail();
        $target->forceFill([
            'remember_token' => 'rollback-remember-token',
            'two_factor_secret' => 'rollback-two-factor-secret',
            'two_factor_recovery_codes' => 'rollback-recovery-codes',
            'two_factor_confirmed_at' => now(),
        ])->save();
        $this->insertAuthenticationArtifacts($target, 'rollback');
        $auditCount = AuditEvent::query()->count();

        $this->mock(AuditRecorder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('record')
                ->once()
                ->andThrow(new RuntimeException('forced late audit failure'));
        });

        $this->artisan('simulation:lab-access', [
            'action' => 'disable',
            '--confirm' => self::DISABLE_CONFIRMATION,
            '--json' => true,
        ])->expectsOutputToContain('"status":"BLOCKED"')
            ->assertFailed();

        foreach ($this->reservedUsers() as $user) {
            $this->assertSame('ACTIVE', $user->status);
        }

        $rolledBackTarget = $target->fresh();
        $this->assertSame('rollback-remember-token', $rolledBackTarget->remember_token);
        $this->assertSame('rollback-two-factor-secret', $rolledBackTarget->two_factor_secret);
        $this->assertSame('rollback-recovery-codes', $rolledBackTarget->two_factor_recovery_codes);
        $this->assertNotNull($rolledBackTarget->two_factor_confirmed_at);
        $this->assertAuthenticationArtifactsExist($rolledBackTarget, 'rollback');
        $this->assertSame($auditCount, AuditEvent::query()->count());
    }

    /** @return Collection<int, User> */
    private function reservedUsers(): Collection
    {
        return User::query()
            ->whereIn('email', app(ReservedDemoAccountRoster::class)->emails())
            ->orderBy('id')
            ->get();
    }

    private function insertAuthenticationArtifacts(User $user, string $suffix): void
    {
        DB::table('sessions')->insert([
            'id' => 'session-'.$suffix,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Synthetic test agent',
            'payload' => 'synthetic-session-payload',
            'last_activity' => now()->getTimestamp(),
        ]);
        DB::table('passkeys')->insert([
            'user_id' => $user->id,
            'name' => 'Synthetic '.$suffix.' passkey',
            'credential_id' => 'credential-'.$suffix,
            'credential' => json_encode(['synthetic' => true], JSON_THROW_ON_ERROR),
            'last_used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => 'reset-token-'.$suffix,
            'created_at' => now(),
        ]);
    }

    private function assertAuthenticationArtifactsExist(User $user, string $suffix): void
    {
        $this->assertDatabaseHas('sessions', [
            'id' => 'session-'.$suffix,
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('passkeys', [
            'user_id' => $user->id,
            'credential_id' => 'credential-'.$suffix,
        ]);
        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => $user->email,
            'token' => 'reset-token-'.$suffix,
        ]);
    }

    private function assertReservedAuthenticationArtifactsAbsent(): void
    {
        $reservedUsers = $this->reservedUsers();

        $this->assertSame(0, DB::table('sessions')->whereIn('user_id', $reservedUsers->pluck('id'))->count());
        $this->assertSame(0, DB::table('passkeys')->whereIn('user_id', $reservedUsers->pluck('id'))->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->whereIn('email', $reservedUsers->pluck('email'))->count());
    }
}
