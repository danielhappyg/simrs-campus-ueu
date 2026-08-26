<?php

namespace Tests\Feature\PrivilegedAccess;

use App\Models\BreakGlassActivation;
use App\Models\BreakGlassDecision;
use App\Models\BreakGlassRequest;
use App\Models\BreakGlassRevocation;
use App\Models\BreakGlassSessionBinding;
use App\Models\BreakGlassSubjectLease;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\PrivilegedAccess\PrivilegedAccessDatabaseClock;
use App\Support\PrivilegedAccess\PrivilegedAccessSessionReference;
use App\Support\PrivilegedAccess\PrivilegedAccessShadowResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PrivilegedAccessShadowResolverTest extends TestCase
{
    use RefreshDatabase;

    private const ENVIRONMENT = 'testing';

    private const RELEASE_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const SESSION_ID = '1111111111111111111111111111111111111111';

    private const SESSION_KEY = 'bg03-synthetic-session-hmac-key-00000000000000000000';

    public function test_off_mode_returns_the_legacy_result_without_query_or_telemetry(): void
    {
        config()->set('break_glass.mode', 'off');
        Log::spy();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $user = User::factory()->make();
        $resolver = app(PrivilegedAccessShadowResolver::class);

        $this->assertTrue($resolver->resolve($user, Capability::USER_MANAGE, true));
        $this->assertFalse($resolver->resolve($user, Capability::USER_MANAGE, false));
        $this->assertSame([], DB::getQueryLog());
        Log::shouldNotHaveReceived('notice');
    }

    public function test_gate_integrated_off_mode_preserves_a_database_free_legacy_admin_decision(): void
    {
        config()->set('break_glass.mode', 'off');
        $administrator = User::factory()->create(['is_system_administrator' => true]);
        $this->app->instance('request', $this->requestFor($administrator, self::SESSION_ID));
        Log::spy();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertTrue(Gate::forUser($administrator)->allows(Capability::USER_MANAGE));
        $this->assertSame([], DB::getQueryLog());
        Log::shouldNotHaveReceived('notice');
    }

    public function test_valid_shadow_facts_never_grant_a_capability_the_legacy_gate_denies(): void
    {
        $this->enableObserver('shadow');
        $graph = $this->createGraph();
        $request = $this->requestFor($graph['subject'], $graph['session_id']);
        $this->app->instance('request', $request);
        Log::spy();

        $this->assertSame($graph['subject']->getKey(), $request->user()?->getKey());
        $this->assertSame($graph['session_id'], $request->session()->getId());
        $this->assertSame(
            (new PrivilegedAccessSessionReference)->hmac($graph['session_id'], self::SESSION_KEY),
            $graph['activation']->sessionBinding()->value('session_reference_hmac'),
        );
        $this->assertFalse($graph['subject']->canCapability(Capability::USER_MANAGE));
        $this->assertFalse(Gate::forUser($graph['subject'])->allows(Capability::USER_MANAGE));

        Log::shouldHaveReceived('notice')->once()->with(
            PrivilegedAccessShadowResolver::TELEMETRY_MESSAGE,
            Mockery::on(function (array $context) use ($graph): bool {
                $this->assertFalse($context['authoritative']);
                $this->assertFalse($context['legacy_allowed']);
                $this->assertTrue($context['shadow_allowed'], json_encode($context, JSON_THROW_ON_ERROR));
                $this->assertTrue($context['divergence']);
                $this->assertSame('all_shadow_controls_satisfied', $context['reason_code']);
                $this->assertSame($graph['subject']->public_id, $context['subject_public_id']);
                $this->assertSame($graph['activation']->public_id, $context['activation_public_id']);

                return true;
            }),
        );
    }

    public function test_shadow_denial_never_removes_a_legacy_system_administrator_result(): void
    {
        $this->enableObserver('shadow');
        $administrator = User::factory()->create(['is_system_administrator' => true]);
        $request = $this->requestFor($administrator, self::SESSION_ID);
        $this->app->instance('request', $request);
        Log::spy();

        $this->assertTrue(Gate::forUser($administrator)->allows(Capability::USER_MANAGE));

        Log::shouldHaveReceived('notice')->once()->with(
            PrivilegedAccessShadowResolver::TELEMETRY_MESSAGE,
            Mockery::on(fn (array $context): bool => $context['legacy_allowed'] === true
                && $context['shadow_allowed'] === false
                && $context['reason_code'] === 'lease_count_invalid'
                && $context['divergence'] === true),
        );
    }

    public function test_accidental_enforce_mode_remains_comparison_only(): void
    {
        $this->enableObserver('enforce');
        $graph = $this->createGraph();
        $request = $this->requestFor($graph['subject'], $graph['session_id']);
        Log::spy();

        $result = app(PrivilegedAccessShadowResolver::class)->resolve(
            $graph['subject'],
            Capability::USER_MANAGE,
            false,
            $request,
        );

        $this->assertFalse($result);
        Log::shouldHaveReceived('notice')->once()->with(
            PrivilegedAccessShadowResolver::TELEMETRY_MESSAGE,
            Mockery::on(fn (array $context): bool => $context['mode'] === 'enforce'
                && $context['authoritative'] === false
                && $context['shadow_allowed'] === true),
        );
    }

    public function test_runtime_state_and_binding_failures_deny_only_the_shadow_comparison(): void
    {
        $this->enableObserver('shadow');
        Log::spy();

        $cases = [
            'expired' => 'activation_outside_database_time',
            'revoked' => 'activation_revoked',
            'wrong_environment' => 'runtime_binding_mismatch',
            'wrong_release' => 'runtime_binding_mismatch',
            'malformed_snapshot' => 'capability_snapshot_mismatch',
            'unbound_session' => 'session_binding_mismatch',
            'overlong_ttl' => 'activation_timing_invalid',
            'late_approval' => 'activation_timing_invalid',
            'weak_assurance' => 'approval_assurance_invalid',
            'preactivation_binding' => 'session_assurance_invalid',
            'future_binding' => 'session_assurance_invalid',
            'inactive_subject' => 'subject_inactive_or_unverified',
            'malformed_subject_ulid' => 'subject_inactive_or_unverified',
        ];

        foreach ($cases as $case => $expectedReason) {
            $graph = $this->createGraph($case);
            $request = $this->requestFor($graph['subject'], $graph['session_id']);

            $this->assertFalse(
                app(PrivilegedAccessShadowResolver::class)->resolve(
                    $graph['subject'],
                    Capability::USER_MANAGE,
                    false,
                    $request,
                ),
                $case,
            );
        }

        foreach ($cases as $expectedReason) {
            Log::shouldHaveReceived('notice')->with(
                PrivilegedAccessShadowResolver::TELEMETRY_MESSAGE,
                Mockery::on(fn (array $context): bool => $context['reason_code'] === $expectedReason
                    && $context['shadow_allowed'] === false
                    && $context['authoritative'] === false),
            );
        }
    }

    public function test_global_deny_and_unsafe_application_mode_fail_closed_for_the_comparison(): void
    {
        $this->enableObserver('shadow');
        $subject = User::factory()->create();
        $request = $this->requestFor($subject, self::SESSION_ID);
        Log::spy();

        config()->set('break_glass.global_disabled', true);
        $this->assertTrue(app(PrivilegedAccessShadowResolver::class)->resolve(
            $subject,
            Capability::USER_MANAGE,
            true,
            $request,
        ));

        config()->set('break_glass.global_disabled', false);
        config()->set('simulation.mode', 'PRODUCTION');
        $this->assertFalse(app(PrivilegedAccessShadowResolver::class)->resolve(
            $subject,
            Capability::USER_MANAGE,
            false,
            $request,
        ));

        Log::shouldHaveReceived('notice')->with(
            PrivilegedAccessShadowResolver::TELEMETRY_MESSAGE,
            Mockery::on(fn (array $context): bool => $context['reason_code'] === 'global_disabled'),
        );
        Log::shouldHaveReceived('notice')->with(
            PrivilegedAccessShadowResolver::TELEMETRY_MESSAGE,
            Mockery::on(fn (array $context): bool => $context['reason_code'] === 'unsafe_application_mode'),
        );
    }

    public function test_missing_session_key_and_session_subject_mismatch_preserve_the_legacy_result(): void
    {
        $this->enableObserver('shadow');
        $graph = $this->createGraph();
        $differentUser = User::factory()->create();
        Log::spy();

        config()->set('break_glass.session_hmac_key', 'too-short');
        $this->assertTrue(app(PrivilegedAccessShadowResolver::class)->resolve(
            $graph['subject'],
            Capability::USER_MANAGE,
            true,
            $this->requestFor($graph['subject'], $graph['session_id']),
        ));

        config()->set('break_glass.session_hmac_key', self::SESSION_KEY);
        $this->assertFalse(app(PrivilegedAccessShadowResolver::class)->resolve(
            $graph['subject'],
            Capability::USER_MANAGE,
            false,
            $this->requestFor($differentUser, $graph['session_id']),
        ));

        Log::shouldHaveReceived('notice')->with(
            PrivilegedAccessShadowResolver::TELEMETRY_MESSAGE,
            Mockery::on(fn (array $context): bool => $context['reason_code'] === 'session_key_invalid'),
        );
        Log::shouldHaveReceived('notice')->with(
            PrivilegedAccessShadowResolver::TELEMETRY_MESSAGE,
            Mockery::on(fn (array $context): bool => $context['reason_code'] === 'session_subject_mismatch'),
        );
    }

    public function test_database_and_logger_failures_preserve_the_legacy_result(): void
    {
        $this->enableObserver('shadow');
        $subject = User::factory()->create();
        $request = $this->requestFor($subject, self::SESSION_ID);
        Schema::drop('break_glass_subject_leases');
        Log::shouldReceive('notice')->twice()->andThrow(new RuntimeException('Synthetic logger failure.'));

        $this->assertTrue(app(PrivilegedAccessShadowResolver::class)->resolve(
            $subject,
            Capability::USER_MANAGE,
            true,
            $request,
        ));
        $this->assertFalse(app(PrivilegedAccessShadowResolver::class)->resolve(
            $subject,
            Capability::USER_MANAGE,
            false,
            $request,
        ));
    }

    public function test_valid_shadow_comparison_has_a_bounded_query_budget(): void
    {
        $this->enableObserver('shadow');
        $graph = $this->createGraph();
        $request = $this->requestFor($graph['subject'], $graph['session_id']);
        Log::spy();
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(PrivilegedAccessShadowResolver::class)->resolve(
            $graph['subject'],
            Capability::USER_MANAGE,
            false,
            $request,
        );

        $queryCount = count(DB::getQueryLog());
        $this->assertGreaterThan(0, $queryCount);
        $this->assertLessThanOrEqual(10, $queryCount);
    }

    public function test_sqlite_database_clock_returns_current_utc_time(): void
    {
        $before = now('UTC')->subSeconds(2);
        $databaseNow = app(PrivilegedAccessDatabaseClock::class)->now();
        $after = now('UTC')->addSeconds(2);

        $this->assertSame('UTC', $databaseNow->timezoneName);
        $this->assertTrue($databaseNow->betweenIncluded($before, $after));
    }

    public function test_telemetry_contains_no_session_key_identifier_or_user_credentials(): void
    {
        $this->enableObserver('shadow');
        $graph = $this->createGraph();
        $request = $this->requestFor($graph['subject'], $graph['session_id']);
        Log::spy();

        app(PrivilegedAccessShadowResolver::class)->resolve(
            $graph['subject'],
            Capability::USER_MANAGE,
            false,
            $request,
        );

        Log::shouldHaveReceived('notice')->once()->with(
            PrivilegedAccessShadowResolver::TELEMETRY_MESSAGE,
            Mockery::on(function (array $context) use ($graph): bool {
                $encoded = json_encode($context, JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString($graph['session_id'], $encoded);
                $this->assertStringNotContainsString(self::SESSION_KEY, $encoded);
                $this->assertStringNotContainsString($graph['subject']->email, $encoded);
                $this->assertStringNotContainsString($graph['subject']->name, $encoded);
                $this->assertArrayNotHasKey('session_id', $context);
                $this->assertArrayNotHasKey('session_reference_hmac', $context);

                return true;
            }),
        );
    }

    private function enableObserver(string $mode): void
    {
        config()->set('break_glass.mode', $mode);
        config()->set('break_glass.global_disabled', false);
        config()->set('break_glass.environment', self::ENVIRONMENT);
        config()->set('break_glass.release_sha', self::RELEASE_SHA);
        config()->set('break_glass.session_hmac_key', self::SESSION_KEY);
        config()->set('simulation.mode', 'SIMULATION');
        config()->set('simulation.synthetic_only', true);
    }

    private function requestFor(User $subject, string $sessionId): Request
    {
        $this->be($subject);
        $session = new Store('bg03-test', new ArraySessionHandler(120));
        $session->setId($sessionId);
        $request = Request::create('/_test/bg03-shadow', 'GET');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn (): User => $subject);

        return $request;
    }

    /**
     * @return array{subject: User, activation: BreakGlassActivation, session_id: string}
     */
    private function createGraph(string $case = 'valid'): array
    {
        $requester = User::factory()->create();
        $subjectAttributes = match ($case) {
            'inactive_subject' => ['status' => 'DISABLED'],
            'malformed_subject_ulid' => ['public_id' => str_repeat('I', 26)],
            default => [],
        };
        $subject = User::factory()->create($subjectAttributes);
        $approver = User::factory()->create();
        $databaseNow = app(PrivilegedAccessDatabaseClock::class)->now();
        $startsAt = $case === 'expired' ? $databaseNow->subMinutes(20) : $databaseNow->subMinutes(2);
        $expiresAt = match ($case) {
            'expired' => $databaseNow->subMinute(),
            'overlong_ttl' => $databaseNow->addMinutes(20),
            default => $databaseNow->addMinutes(10),
        };
        $environment = $case === 'wrong_environment' ? 'preview' : self::ENVIRONMENT;
        $releaseSha = $case === 'wrong_release' ? str_repeat('b', 40) : self::RELEASE_SHA;
        $capabilities = [Capability::USER_MANAGE, Capability::AUDIT_VIEW];
        $approvalDeadlineAt = $case === 'late_approval'
            ? $databaseNow->subMinutes(4)
            : $databaseNow->addMinutes(5);
        $assuranceMethod = $case === 'weak_assurance' ? 'PASSWORD' : 'TOTP';
        $bindingAt = match ($case) {
            'preactivation_binding' => $databaseNow->subMinutes(3),
            'future_binding' => $databaseNow->addMinute(),
            default => $databaseNow->subMinutes(2),
        };
        $sessionId = $case === 'valid'
            ? self::SESSION_ID
            : substr(hash('sha256', 'bg03-session:'.$case), 0, 40);

        if ($case === 'malformed_snapshot') {
            $capabilities = array_reverse($capabilities);
        }

        $request = BreakGlassRequest::query()->create([
            'subject_user_id' => $subject->id,
            'requester_user_id' => $requester->id,
            'subject_snapshot' => ['public_id' => $subject->public_id],
            'requester_snapshot' => ['public_id' => $requester->public_id],
            'scope_key' => 'security-containment',
            'capability_snapshot' => $capabilities,
            'reason' => 'Contain a synthetic teaching identity during a bounded rehearsal.',
            'change_reference' => 'CHG-BG03-LOCAL',
            'requested_ttl_minutes' => 15,
            'requested_at' => $databaseNow->subMinutes(5),
            'approval_deadline_at' => $approvalDeadlineAt,
            'environment' => $environment,
            'release_sha' => $releaseSha,
            'canonical_digest' => $this->digest("{$case}:request"),
        ]);

        $decision = BreakGlassDecision::query()->create([
            'break_glass_request_id' => $request->id,
            'approver_user_id' => $approver->id,
            'approver_snapshot' => ['public_id' => $approver->public_id],
            'decision' => BreakGlassDecision::APPROVED,
            'rationale' => 'Approved synthetic rehearsal fact for resolver comparison only.',
            'assurance_method' => $assuranceMethod,
            'assured_at' => $databaseNow->subMinutes(4),
            'decided_at' => $databaseNow->subMinutes(3),
            'request_digest' => $request->canonical_digest,
            'environment' => $environment,
            'release_sha' => $releaseSha,
            'canonical_digest' => $this->digest("{$case}:decision"),
        ]);

        $activation = BreakGlassActivation::query()->create([
            'break_glass_request_id' => $request->id,
            'break_glass_decision_id' => $decision->id,
            'subject_user_id' => $subject->id,
            'approved_by_user_id' => $approver->id,
            'subject_snapshot' => ['public_id' => $subject->public_id],
            'approver_snapshot' => ['public_id' => $approver->public_id],
            'scope_key' => 'security-containment',
            'capability_snapshot' => $capabilities,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'nonce_version' => 1,
            'nonce_digest' => $this->digest("{$case}:nonce"),
            'request_digest' => $request->canonical_digest,
            'decision_digest' => $decision->canonical_digest,
            'environment' => $environment,
            'release_sha' => $releaseSha,
            'canonical_digest' => $this->digest("{$case}:activation"),
        ]);

        BreakGlassSubjectLease::query()->create([
            'subject_user_id' => $subject->id,
            'break_glass_activation_id' => $activation->id,
            'expires_at' => $expiresAt,
        ]);

        if ($case !== 'unbound_session') {
            BreakGlassSessionBinding::query()->create([
                'break_glass_activation_id' => $activation->id,
                'subject_user_id' => $subject->id,
                'session_reference_hmac' => (new PrivilegedAccessSessionReference)->hmac(
                    $sessionId,
                    self::SESSION_KEY,
                ),
                'assurance_method' => $assuranceMethod,
                'assured_at' => $bindingAt,
                'bound_at' => $bindingAt,
                'activation_digest' => $activation->canonical_digest,
                'environment' => $environment,
                'release_sha' => $releaseSha,
                'canonical_digest' => $this->digest("{$case}:binding"),
            ]);
        }

        if ($case === 'revoked') {
            BreakGlassRevocation::query()->create([
                'break_glass_activation_id' => $activation->id,
                'revoker_user_id' => $approver->id,
                'revoker_type' => BreakGlassRevocation::REVOKER_USER,
                'revoker_reference' => $approver->public_id,
                'revoker_snapshot' => ['public_id' => $approver->public_id],
                'reason' => 'Synthetic resolver revocation case.',
                'change_reference' => 'CHG-BG03-LOCAL',
                'revoked_at' => $databaseNow->subMinute(),
                'activation_digest' => $activation->canonical_digest,
                'environment' => $environment,
                'release_sha' => $releaseSha,
                'canonical_digest' => $this->digest("{$case}:revocation"),
            ]);
        }

        return ['subject' => $subject, 'activation' => $activation, 'session_id' => $sessionId];
    }

    private function digest(string $value): string
    {
        return hash('sha256', 'bg03-shadow-test:'.$value);
    }
}
