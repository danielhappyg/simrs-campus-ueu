<?php

namespace App\Support\PrivilegedAccess;

use App\Models\BreakGlassActivation;
use App\Models\BreakGlassDecision;
use App\Models\BreakGlassSubjectLease;
use App\Models\User;
use App\Support\Authorization\Capability;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * BG-03 comparison-only resolver.
 *
 * The caller supplies the authoritative legacy result. This class must return
 * that exact value even when configuration, session, database, or logging
 * fails. It cannot activate privilege or add a capability.
 */
final class PrivilegedAccessShadowResolver
{
    public const TELEMETRY_MESSAGE = 'Privileged-access shadow authorization comparison.';

    public function __construct(
        private readonly PrivilegedAccessDatabaseClock $databaseClock,
        private readonly PrivilegedAccessSessionReference $sessionReference,
    ) {}

    public function resolve(
        User $user,
        string $capability,
        bool $legacyAllowed,
        ?Request $request = null,
    ): bool {
        $mode = config('break_glass.mode');

        // The default/off path has deliberately zero resolver queries and zero
        // resolver telemetry. Existing authorization is the whole result.
        if ($mode === PrivilegedAccessConfiguration::MODE_OFF) {
            return $legacyAllowed;
        }

        // Unknown mode values cannot accidentally activate the observer.
        if (! in_array($mode, [
            PrivilegedAccessConfiguration::MODE_SHADOW,
            PrivilegedAccessConfiguration::MODE_ENFORCE,
        ], true)) {
            return $legacyAllowed;
        }

        try {
            $comparison = $this->compare($user, $capability, $request);
        } catch (Throwable) {
            $comparison = $this->deny('comparison_failed');
        }

        $this->emitTelemetry(
            mode: $mode,
            user: $user,
            capability: $capability,
            legacyAllowed: $legacyAllowed,
            comparison: $comparison,
        );

        return $legacyAllowed;
    }

    /**
     * @return array{allowed: bool, reason: string, activation_public_id: string|null}
     */
    private function compare(User $user, string $capability, ?Request $request): array
    {
        /** @var mixed $settings */
        $settings = config('break_glass');

        if (! is_array($settings)) {
            return $this->deny('configuration_missing');
        }

        try {
            $configuration = new PrivilegedAccessConfiguration($settings);
        } catch (Throwable) {
            return $this->deny('configuration_invalid');
        }

        if (($settings['global_disabled'] ?? null) !== false) {
            return $this->deny('global_disabled');
        }

        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            return $this->deny('unsafe_application_mode');
        }

        $environment = $settings['environment'] ?? null;
        $releaseSha = $settings['release_sha'] ?? null;
        $sessionHmacKey = $settings['session_hmac_key'] ?? null;

        if (! is_string($environment) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,31}\z/', $environment) !== 1) {
            return $this->deny('environment_invalid');
        }

        if (! is_string($releaseSha) || preg_match('/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/', $releaseSha) !== 1) {
            return $this->deny('release_invalid');
        }

        if (! is_string($sessionHmacKey) || strlen($sessionHmacKey) < 32) {
            return $this->deny('session_key_invalid');
        }

        if (! in_array($capability, Capability::all(), true)) {
            return $this->deny('capability_unregistered');
        }

        if (! $user->exists || (! is_int($user->getKey()) && ! ctype_digit((string) $user->getKey()))) {
            return $this->deny('subject_invalid');
        }

        $subject = User::query()
            ->select(['id', 'public_id', 'status', 'email_verified_at'])
            ->find($user->getKey());

        if ($subject === null || ! $this->eligibleActor($subject)) {
            return $this->deny('subject_inactive_or_unverified');
        }

        if ($request === null || ! $request->hasSession()) {
            return $this->deny('session_missing');
        }

        $requestUser = $request->user();

        if (! $requestUser instanceof User || (string) $requestUser->getKey() !== (string) $subject->getKey()) {
            return $this->deny('session_subject_mismatch');
        }

        $sessionId = $request->session()->getId();

        try {
            $currentSessionHmac = $this->sessionReference->hmac($sessionId, $sessionHmacKey);
        } catch (Throwable) {
            return $this->deny('session_reference_invalid');
        }

        $databaseNow = $this->databaseClock->now();
        $leases = BreakGlassSubjectLease::query()
            ->where('subject_user_id', $subject->getKey())
            ->with([
                'activation.request.requester',
                'activation.decision.approver',
                'activation.revocation',
                'activation.sessionBinding',
            ])
            ->limit(2)
            ->get();

        if ($leases->count() !== 1) {
            return $this->deny('lease_count_invalid');
        }

        $lease = $leases->first();
        $activation = $lease?->activation;

        if (! $activation instanceof BreakGlassActivation) {
            return $this->deny('activation_missing');
        }

        $activationPublicId = $this->safeUlid($activation->public_id);

        if (
            (string) $lease->subject_user_id !== (string) $subject->getKey()
            || (string) $activation->subject_user_id !== (string) $subject->getKey()
            || (string) $lease->break_glass_activation_id !== (string) $activation->getKey()
        ) {
            return $this->deny('activation_subject_mismatch', $activationPublicId);
        }

        $startsAt = $this->utcTimestamp($activation, 'starts_at');
        $expiresAt = $this->utcTimestamp($activation, 'expires_at');
        $leaseExpiresAt = $this->utcTimestamp($lease, 'expires_at');

        if (
            $startsAt === null
            || $expiresAt === null
            || $leaseExpiresAt === null
            || $startsAt->greaterThan($databaseNow)
            || ! $expiresAt->greaterThan($databaseNow)
            || ! $leaseExpiresAt->greaterThan($databaseNow)
            || ! $leaseExpiresAt->equalTo($expiresAt)
        ) {
            return $this->deny('activation_outside_database_time', $activationPublicId);
        }

        if ($activation->revocation !== null) {
            return $this->deny('activation_revoked', $activationPublicId);
        }

        $breakGlassRequest = $activation->request;
        $decision = $activation->decision;
        $requester = $breakGlassRequest?->requester;
        $approver = $decision?->approver;

        if (
            $breakGlassRequest === null
            || ! $decision instanceof BreakGlassDecision
            || ! $requester instanceof User
            || ! $approver instanceof User
            || $decision->decision !== BreakGlassDecision::APPROVED
            || (string) $breakGlassRequest->subject_user_id !== (string) $subject->getKey()
            || (string) $activation->break_glass_request_id !== (string) $breakGlassRequest->getKey()
            || (string) $activation->break_glass_decision_id !== (string) $decision->getKey()
            || (string) $breakGlassRequest->requester_user_id === (string) $decision->approver_user_id
            || (string) $decision->approver_user_id === (string) $subject->getKey()
            || (string) $activation->approved_by_user_id !== (string) $decision->approver_user_id
            || ! $this->sameDigest($activation->request_digest, $breakGlassRequest->canonical_digest)
            || ! $this->sameDigest($activation->decision_digest, $decision->canonical_digest)
            || ! $this->sameDigest($decision->request_digest, $breakGlassRequest->canonical_digest)
        ) {
            return $this->deny('approval_chain_invalid', $activationPublicId);
        }

        if (! $this->eligibleActor($requester) || ! $this->eligibleActor($approver)) {
            return $this->deny('approval_actor_ineligible', $activationPublicId);
        }

        $requestedAt = $this->utcTimestamp($breakGlassRequest, 'requested_at');
        $approvalDeadlineAt = $this->utcTimestamp($breakGlassRequest, 'approval_deadline_at');
        $decisionAssuredAt = $this->utcTimestamp($decision, 'assured_at');
        $decidedAt = $this->utcTimestamp($decision, 'decided_at');

        try {
            $requestedTtl = $configuration->resolveTtlMinutes($breakGlassRequest->requested_ttl_minutes);
        } catch (Throwable) {
            return $this->deny('activation_ttl_invalid', $activationPublicId);
        }

        $activationSeconds = $expiresAt->getTimestamp() - $startsAt->getTimestamp();

        if (
            $requestedAt === null
            || $approvalDeadlineAt === null
            || $decisionAssuredAt === null
            || $decidedAt === null
            || $approvalDeadlineAt->lessThan($requestedAt)
            || $decisionAssuredAt->lessThan($requestedAt)
            || $decisionAssuredAt->greaterThan($decidedAt)
            || $decidedAt->greaterThan($approvalDeadlineAt)
            || $startsAt->lessThan($decidedAt)
            || $activationSeconds < 1
            || $activationSeconds > ($requestedTtl * 60)
        ) {
            return $this->deny('activation_timing_invalid', $activationPublicId);
        }

        if (! $this->strongAssurance($decision->assurance_method)) {
            return $this->deny('approval_assurance_invalid', $activationPublicId);
        }

        if (
            ! $this->matchesRuntime($breakGlassRequest->environment, $environment, $breakGlassRequest->release_sha, $releaseSha)
            || ! $this->matchesRuntime($decision->environment, $environment, $decision->release_sha, $releaseSha)
            || ! $this->matchesRuntime($activation->environment, $environment, $activation->release_sha, $releaseSha)
        ) {
            return $this->deny('runtime_binding_mismatch', $activationPublicId);
        }

        try {
            $canonicalCapabilities = $configuration->scopes()->capabilitiesFor((string) $activation->scope_key);
        } catch (Throwable) {
            return $this->deny('scope_invalid', $activationPublicId);
        }

        if (
            $breakGlassRequest->scope_key !== $activation->scope_key
            || ! $this->isExactSnapshot($breakGlassRequest->capability_snapshot, $canonicalCapabilities)
            || ! $this->isExactSnapshot($activation->capability_snapshot, $canonicalCapabilities)
            || ! in_array($capability, $canonicalCapabilities, true)
        ) {
            return $this->deny('capability_snapshot_mismatch', $activationPublicId);
        }

        $activationSubjectSnapshot = $activation->getAttribute('subject_snapshot');
        $requestSubjectSnapshot = $breakGlassRequest->getAttribute('subject_snapshot');
        $requesterSnapshot = $breakGlassRequest->getAttribute('requester_snapshot');
        $approverSnapshot = $decision->getAttribute('approver_snapshot');
        $activationApproverSnapshot = $activation->getAttribute('approver_snapshot');

        if (
            ! is_array($activationSubjectSnapshot)
            || ($activationSubjectSnapshot['public_id'] ?? null) !== $subject->public_id
            || ! is_array($requestSubjectSnapshot)
            || ($requestSubjectSnapshot['public_id'] ?? null) !== $subject->public_id
            || ! is_array($requesterSnapshot)
            || ($requesterSnapshot['public_id'] ?? null) !== $requester->public_id
            || ! is_array($approverSnapshot)
            || ($approverSnapshot['public_id'] ?? null) !== $approver->public_id
            || ! is_array($activationApproverSnapshot)
            || ($activationApproverSnapshot['public_id'] ?? null) !== $approver->public_id
        ) {
            return $this->deny('subject_snapshot_mismatch', $activationPublicId);
        }

        $binding = $activation->sessionBinding;
        $bindingAssuredAt = $binding instanceof Model ? $this->utcTimestamp($binding, 'assured_at') : null;
        $boundAt = $binding instanceof Model ? $this->utcTimestamp($binding, 'bound_at') : null;
        $bindingSessionReference = $binding instanceof Model
            ? $binding->getAttribute('session_reference_hmac')
            : null;

        if (
            $binding === null
            || (string) $binding->subject_user_id !== (string) $subject->getKey()
            || (string) $binding->break_glass_activation_id !== (string) $activation->getKey()
            || ! $this->sameDigest($binding->activation_digest, $activation->canonical_digest)
            || ! $this->matchesRuntime($binding->environment, $environment, $binding->release_sha, $releaseSha)
            || ! is_string($bindingSessionReference)
            || preg_match('/\A[0-9a-f]{64}\z/', $bindingSessionReference) !== 1
            || ! hash_equals($bindingSessionReference, $currentSessionHmac)
        ) {
            return $this->deny('session_binding_mismatch', $activationPublicId);
        }

        if (
            ! $this->strongAssurance($binding->assurance_method)
            || $bindingAssuredAt === null
            || $boundAt === null
            || $bindingAssuredAt->lessThan($startsAt)
            || $bindingAssuredAt->greaterThan($boundAt)
            || $boundAt->lessThan($startsAt)
            || $bindingAssuredAt->greaterThan($databaseNow)
            || $boundAt->greaterThan($databaseNow)
            || ! $expiresAt->greaterThan($boundAt)
        ) {
            return $this->deny('session_assurance_invalid', $activationPublicId);
        }

        return [
            'allowed' => true,
            'reason' => 'all_shadow_controls_satisfied',
            'activation_public_id' => $activationPublicId,
        ];
    }

    /**
     * @param  array{allowed: bool, reason: string, activation_public_id: string|null}  $comparison
     */
    private function emitTelemetry(
        string $mode,
        User $user,
        string $capability,
        bool $legacyAllowed,
        array $comparison,
    ): void {
        $safeCapability = in_array($capability, Capability::all(), true)
            ? $capability
            : 'unregistered';

        $context = [
            'schema_version' => 1,
            'observer' => 'BG-03',
            'authoritative' => false,
            'mode' => $mode,
            'legacy_allowed' => $legacyAllowed,
            'shadow_allowed' => $comparison['allowed'],
            'divergence' => $legacyAllowed !== $comparison['allowed'],
            'reason_code' => $comparison['reason'],
            'capability' => $safeCapability,
            'subject_public_id' => $this->safeUlid($user->public_id),
            'activation_public_id' => $comparison['activation_public_id'],
        ];

        try {
            Log::notice(self::TELEMETRY_MESSAGE, $context);
        } catch (Throwable) {
            // Shadow telemetry can never change the legacy Gate result.
        }
    }

    /** @return array{allowed: false, reason: string, activation_public_id: string|null} */
    private function deny(string $reason, ?string $activationPublicId = null): array
    {
        return [
            'allowed' => false,
            'reason' => $reason,
            'activation_public_id' => $activationPublicId,
        ];
    }

    private function matchesRuntime(mixed $storedEnvironment, string $environment, mixed $storedReleaseSha, string $releaseSha): bool
    {
        return is_string($storedEnvironment)
            && hash_equals($environment, $storedEnvironment)
            && is_string($storedReleaseSha)
            && preg_match('/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/', $storedReleaseSha) === 1
            && hash_equals($releaseSha, $storedReleaseSha);
    }

    /** @param list<string> $canonicalCapabilities */
    private function isExactSnapshot(mixed $snapshot, array $canonicalCapabilities): bool
    {
        return is_array($snapshot)
            && array_is_list($snapshot)
            && $snapshot === $canonicalCapabilities;
    }

    private function sameDigest(mixed $left, mixed $right): bool
    {
        return is_string($left)
            && is_string($right)
            && preg_match('/\A[0-9a-f]{64}\z/', $left) === 1
            && preg_match('/\A[0-9a-f]{64}\z/', $right) === 1
            && hash_equals($left, $right);
    }

    private function safeUlid(mixed $value): ?string
    {
        return is_string($value) && Str::isUlid($value) ? $value : null;
    }

    private function eligibleActor(User $user): bool
    {
        return $user->exists
            && $user->status === 'ACTIVE'
            && $user->email_verified_at !== null
            && $this->safeUlid($user->public_id) !== null;
    }

    private function strongAssurance(mixed $method): bool
    {
        return is_string($method) && in_array($method, ['TOTP', 'PASSKEY_MFA'], true);
    }

    private function utcTimestamp(Model $model, string $attribute): ?CarbonImmutable
    {
        $value = $model->getRawOriginal($attribute);

        try {
            if ($value instanceof DateTimeInterface) {
                return CarbonImmutable::instance($value)->utc();
            }

            return is_string($value) && trim($value) !== ''
                ? CarbonImmutable::parse($value, 'UTC')->utc()
                : null;
        } catch (Throwable) {
            return null;
        }
    }
}
