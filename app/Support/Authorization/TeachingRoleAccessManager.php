<?php

namespace App\Support\Authorization;

use App\Models\TeachingRoleAccessLease;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Database\SchemaQualifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * @phpstan-type RuntimeBindings array{environment: string, release_sha: string, deployment_url: string, canonical_host: string}
 * @phpstan-type ArtifactCounts array{sessions: int, passkeys: int, reset_records: int}
 * @phpstan-type AccountState array{
 *     email: string,
 *     expected_role: string|null,
 *     roles: list<string>,
 *     status: string,
 *     is_system_administrator: bool,
 *     verified: bool,
 *     remember_present: bool,
 *     mfa_present: bool,
 *     sessions: int,
 *     passkeys: int,
 *     reset_records: int,
 *     access_epoch: int,
 *     lease_public_id: string|null,
 *     expires_at: string|null,
 *     lease_valid: bool,
 *     active_leases: int,
 *     identity_candidates: int,
 *     invariant_ok: bool
 * }
 * @phpstan-type LifecycleResult array{
 *     action: string,
 *     target_email: string,
 *     mutated: bool,
 *     idempotent: bool,
 *     audit_recorded: bool,
 *     roster: list<AccountState>,
 *     aggregate: array{active: int, disabled: int, missing: int, drifted: int, sessions: int, passkeys: int, reset_records: int}
 * }
 */
final class TeachingRoleAccessManager
{
    public const ACTION_STATUS = 'status';

    public const ACTION_ACTIVATE = 'activate';

    public const ACTION_REVOKE = 'revoke';

    public const AUDIT_ACTIVATED = 'authorization.teaching_role.activated';

    public const AUDIT_REVOKED = 'authorization.teaching_role.revoked';

    public const AUDIT_COMPENSATED = 'authorization.teaching_role.compensated';

    /** @var array<string, string> */
    public const ROSTER = [
        'registrar.demo@example.invalid' => RoleCapabilityMatrix::ROLE_REGISTRAR,
        'nurse.demo@example.invalid' => RoleCapabilityMatrix::ROLE_NURSE,
        'physician.demo@example.invalid' => RoleCapabilityMatrix::ROLE_PHYSICIAN,
        'rmik.demo@example.invalid' => RoleCapabilityMatrix::ROLE_RMIK,
        'admin.demo@example.invalid' => RoleCapabilityMatrix::ROLE_ADMIN,
        'radiology.technologist.demo@example.invalid' => RoleCapabilityMatrix::ROLE_RADIOLOGY_TECHNOLOGIST,
        'radiologist.demo@example.invalid' => RoleCapabilityMatrix::ROLE_RADIOLOGIST,
        'laboratory.technologist.demo@example.invalid' => RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST,
        'laboratory.verifier.demo@example.invalid' => RoleCapabilityMatrix::ROLE_LABORATORY_VERIFIER,
        'pharmacist.demo@example.invalid' => RoleCapabilityMatrix::ROLE_PHARMACIST,
        'pharmacy.technician.demo@example.invalid' => RoleCapabilityMatrix::ROLE_PHARMACY_TECHNICIAN,
        'pharmacy.inventory.demo@example.invalid' => RoleCapabilityMatrix::ROLE_PHARMACY_INVENTORY_CONTROLLER,
        'cashier.demo@example.invalid' => RoleCapabilityMatrix::ROLE_CASHIER,
        'cashier.supervisor.demo@example.invalid' => RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR,
        'finance.steward.demo@example.invalid' => RoleCapabilityMatrix::ROLE_FINANCE_STEWARD,
        'procurement.officer.demo@example.invalid' => RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER,
        'procurement.approver.demo@example.invalid' => RoleCapabilityMatrix::ROLE_PROCUREMENT_APPROVER,
        'warehouse.receiver.demo@example.invalid' => RoleCapabilityMatrix::ROLE_WAREHOUSE_RECEIVER,
        'warehouse.inventory.controller.demo@example.invalid' => RoleCapabilityMatrix::ROLE_WAREHOUSE_INVENTORY_CONTROLLER,
        'warehouse.inventory.supervisor.demo@example.invalid' => RoleCapabilityMatrix::ROLE_WAREHOUSE_INVENTORY_SUPERVISOR,
    ];

    /** @var array<string, string> */
    public const NON_WAREHOUSE_ROSTER = [
        'registrar.demo@example.invalid' => RoleCapabilityMatrix::ROLE_REGISTRAR,
        'nurse.demo@example.invalid' => RoleCapabilityMatrix::ROLE_NURSE,
        'physician.demo@example.invalid' => RoleCapabilityMatrix::ROLE_PHYSICIAN,
        'rmik.demo@example.invalid' => RoleCapabilityMatrix::ROLE_RMIK,
        'admin.demo@example.invalid' => RoleCapabilityMatrix::ROLE_ADMIN,
        'radiology.technologist.demo@example.invalid' => RoleCapabilityMatrix::ROLE_RADIOLOGY_TECHNOLOGIST,
        'radiologist.demo@example.invalid' => RoleCapabilityMatrix::ROLE_RADIOLOGIST,
        'laboratory.technologist.demo@example.invalid' => RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST,
        'laboratory.verifier.demo@example.invalid' => RoleCapabilityMatrix::ROLE_LABORATORY_VERIFIER,
        'pharmacist.demo@example.invalid' => RoleCapabilityMatrix::ROLE_PHARMACIST,
        'pharmacy.technician.demo@example.invalid' => RoleCapabilityMatrix::ROLE_PHARMACY_TECHNICIAN,
        'pharmacy.inventory.demo@example.invalid' => RoleCapabilityMatrix::ROLE_PHARMACY_INVENTORY_CONTROLLER,
        'cashier.demo@example.invalid' => RoleCapabilityMatrix::ROLE_CASHIER,
        'cashier.supervisor.demo@example.invalid' => RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR,
        'finance.steward.demo@example.invalid' => RoleCapabilityMatrix::ROLE_FINANCE_STEWARD,
    ];

    /** @var array<string, string> */
    public const WAREHOUSE_ROSTER = [
        'procurement.officer.demo@example.invalid' => RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER,
        'procurement.approver.demo@example.invalid' => RoleCapabilityMatrix::ROLE_PROCUREMENT_APPROVER,
        'warehouse.receiver.demo@example.invalid' => RoleCapabilityMatrix::ROLE_WAREHOUSE_RECEIVER,
        'warehouse.inventory.controller.demo@example.invalid' => RoleCapabilityMatrix::ROLE_WAREHOUSE_INVENTORY_CONTROLLER,
        'warehouse.inventory.supervisor.demo@example.invalid' => RoleCapabilityMatrix::ROLE_WAREHOUSE_INVENTORY_SUPERVISOR,
    ];

    /** @var list<string> */
    private const PLACEHOLDERS = [
        '-', 'n/a', 'na', 'none', 'null', 'operator', 'placeholder', 'reason',
        'tbd', 'test', 'todo', 'unknown',
    ];

    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly TeachingRoleAccessLeaseGuard $leaseGuard,
    ) {}

    /** @return array<string, string> */
    public static function effectiveRoster(): array
    {
        if (config('simulation.warehouse_capability_enabled') === true) {
            return self::ROSTER;
        }

        return self::NON_WAREHOUSE_ROSTER;
    }

    /** @return LifecycleResult */
    public function execute(
        string $action,
        string $email,
        ?string $confirmation,
        ?string $operator,
        ?string $reason,
        ?string $expectedEnvironment = null,
        ?string $expectedReleaseSha = null,
        ?string $expectedDeploymentUrl = null,
        ?string $expectedCanonicalHost = null,
        ?int $ttlMinutes = null,
    ): array {
        $action = strtolower(trim($action));
        $this->assertAction($action);
        $email = strtolower(trim($email));
        $expectedRole = ($action === self::ACTION_REVOKE ? self::ROSTER : self::effectiveRoster())[$email] ?? null;

        if ($expectedRole === null) {
            throw new InvalidArgumentException('Teaching-role access refused: target is not in the exact demo-account roster.');
        }

        $this->assertConfirmation($action, $email, $confirmation);
        $operator = $this->validatedAttribution($operator, 'operator', 3, 120);
        $reason = $this->validatedAttribution($reason, 'reason', 8, 240);

        if ($action === self::ACTION_REVOKE) {
            return $this->executeContainmentRevoke(
                $email,
                $expectedRole,
                $operator,
                $reason,
                $expectedEnvironment,
                $expectedReleaseSha,
                $expectedDeploymentUrl,
                $expectedCanonicalHost,
            );
        }

        $this->assertSafeSimulationMode();
        $bindings = $this->validatedRuntimeBindings(
            $expectedEnvironment,
            $expectedReleaseSha,
            $expectedDeploymentUrl,
            $expectedCanonicalHost,
        );

        if ($action === self::ACTION_STATUS) {
            return $this->readbackResult($action, $email, false, true, false);
        }

        $plainText = $this->activationPassword();
        $ttlMinutes = $this->validatedTtl($ttlMinutes);

        $mutation = DB::transaction(function () use (
            $email, $expectedRole, $operator, $reason, $bindings, $plainText, $ttlMinutes,
        ): array {
            $roster = $this->lockedRoster();
            $target = $roster->get($email);
            if (! $target instanceof User) {
                throw new TeachingRoleAccessException('Teaching-role access refused: target roster account does not exist.');
            }

            $this->lockRoleAssignments($roster);
            $this->lockLeases($roster);
            $this->assertExactAccountInvariant($target, $email, $expectedRole);

            $this->assertCompleteRosterInvariant($roster);
            $this->assertNoOtherActiveAccount($roster, $email);

            return $this->activate(
                $target, $email, $expectedRole, $operator, $reason, $bindings,
                (string) $plainText, (int) $ttlMinutes,
            );
        }, attempts: 1);

        try {
            $result = $this->readbackResult(
                $action, $email, $mutation['mutated'], $mutation['idempotent'], true,
            );
            $targetState = collect($result['roster'])->firstWhere('email', $email);
            $confirmed = is_array($targetState)
                && $targetState['invariant_ok'] === true
                && $this->isPublicActivatedState($targetState)
                && $targetState['lease_valid'] === true;
        } catch (Throwable $exception) {
            Log::critical('Teaching-role access final readback failed.', [
                'exception_class' => $exception::class,
                'failure_fingerprint' => hash('sha256', $exception::class."\0".$exception->getMessage()),
            ]);
            $this->compensateToDisabled($email, $expectedRole, $operator, $reason, $bindings);

            throw new TeachingRoleAccessException(
                'Teaching-role access could not verify final containment. Immediate operator review is required.',
            );
        }

        if (! $confirmed) {
            $this->compensateToDisabled($email, $expectedRole, $operator, $reason, $bindings);
            throw new TeachingRoleAccessException(
                'Teaching-role access failed closed because final readback did not confirm the requested state.',
            );
        }

        return $result;
    }

    public static function confirmationPhrase(string $action, string $email): string
    {
        return sprintf('SIMRS CAMPUS UEU %s %s', strtoupper($action), strtolower($email));
    }

    /** @return LifecycleResult */
    private function executeContainmentRevoke(
        string $email,
        string $expectedRole,
        string $operator,
        string $reason,
        ?string $expectedEnvironment,
        ?string $expectedReleaseSha,
        ?string $expectedDeploymentUrl,
        ?string $expectedCanonicalHost,
    ): array {
        $bindings = $this->containmentRuntimeBindings(
            $expectedEnvironment,
            $expectedReleaseSha,
            $expectedDeploymentUrl,
            $expectedCanonicalHost,
        );
        $mutations = DB::transaction(function () use (
            $email, $expectedRole, $operator, $reason, $bindings,
        ): array {
            $targets = User::query()
                ->where(function ($query) use ($email, $expectedRole): void {
                    $query->where('email', $email)
                        ->orWhere('teaching_access_roster_key', $expectedRole);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($targets->isEmpty()) {
                throw new TeachingRoleAccessException('Teaching-role revocation refused: target roster account does not exist.');
            }

            $results = [];
            foreach ($targets as $target) {
                TeachingRoleAccessLease::query()
                    ->where('user_id', $target->getKey())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $result = $this->revoke(
                    $target, $email, $expectedRole, $operator, $reason, $bindings,
                );
                $result['target_id'] = (int) $target->getKey();
                $results[] = $result;
            }

            return $results;
        }, attempts: 1);

        try {
            foreach ($mutations as $mutation) {
                $candidate = User::query()->find($mutation['target_id']);
                if (! $candidate instanceof User
                    || ! $this->isPublicRevokedState($this->accountState($candidate, false, false, true))
                    || TeachingRoleAccessLease::query()
                        ->where('user_id', $candidate->getKey())
                        ->where('status', 'ACTIVE')
                        ->exists()
                    || $candidate->teaching_access_lease_public_id !== null
                    || $candidate->teaching_access_expires_at_epoch !== null) {
                    $this->compensateToDisabled($email, $expectedRole, $operator, $reason, $bindings);
                    throw new TeachingRoleAccessException(
                        'Teaching-role revocation could not verify final containment. Immediate operator review is required.',
                    );
                }
            }
        } catch (TeachingRoleAccessException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::critical('Teaching-role revocation final readback failed.', [
                'exception_class' => $exception::class,
                'failure_fingerprint' => hash('sha256', $exception::class."\0".$exception->getMessage()),
            ]);
            $this->compensateToDisabled($email, $expectedRole, $operator, $reason, $bindings);

            throw new TeachingRoleAccessException(
                'Teaching-role revocation could not verify final containment. Immediate operator review is required.',
            );
        }

        if (count($mutations) !== 1) {
            Log::critical('Teaching-role revocation contained ambiguous roster identity drift.', [
                'candidate_count' => count($mutations),
                'target_email_fingerprint' => hash('sha256', $email),
            ]);

            throw new TeachingRoleAccessException(
                'Teaching-role revocation contained multiple matching roster identities. Keep access closed and complete an incident review before further activation.',
            );
        }

        $mutation = $mutations[0];

        try {
            DB::transaction(function () use (
                $mutation, $email, $expectedRole, $operator, $reason, $bindings,
            ): void {
                $target = User::query()->whereKey($mutation['target_id'])->lockForUpdate()->first();
                $lastLease = TeachingRoleAccessLease::query()
                    ->whereKey($mutation['last_lease_id'])
                    ->lockForUpdate()
                    ->first();
                if (! $target instanceof User || ! $lastLease instanceof TeachingRoleAccessLease) {
                    throw new TeachingRoleAccessException(
                        'Teaching-role revocation audit context is unavailable.',
                    );
                }

                $current = $this->accountState($target, true, false, true);
                if (! $current['invariant_ok']
                    || ! $this->isPublicRevokedState($current)
                    || (int) $target->teaching_access_epoch !== $mutation['contained_epoch']
                    || (int) $target->teaching_access_mutex !== $mutation['contained_mutex']
                    || $lastLease->status !== $mutation['contained_lease_status']
                    || $lastLease->ended_at_epoch !== $mutation['contained_lease_ended_at_epoch']
                    || $target->teaching_access_lease_public_id !== null
                    || $target->teaching_access_expires_at_epoch !== null) {
                    throw new TeachingRoleAccessException(
                        'Teaching-role revocation audit refused because containment state drifted.',
                    );
                }

                $this->recordLifecycleAudit(
                    self::AUDIT_REVOKED,
                    $target,
                    $email,
                    $expectedRole,
                    $operator,
                    $reason,
                    $bindings,
                    $mutation['before'],
                    $mutation['after'],
                    $mutation['revoked'],
                    $mutation['rotated'],
                    $mutation['idempotent'],
                    $lastLease,
                    null,
                    $mutation['login_state_commitment'],
                );
            }, attempts: 1);
        } catch (Throwable $exception) {
            Log::critical('Teaching-role revocation audit failed after containment.', [
                'exception_class' => $exception::class,
                'failure_fingerprint' => hash('sha256', $exception::class."\0".$exception->getMessage()),
            ]);

            throw new TeachingRoleAccessException(
                'Teaching-role revocation completed containment but could not record its audit evidence. Immediate operator review is required.',
            );
        }

        return $this->readbackResult(
            self::ACTION_REVOKE,
            $email,
            $mutation['mutated'],
            $mutation['idempotent'],
            true,
        );
    }

    /**
     * @param  RuntimeBindings  $bindings
     * @return array{mutated: bool, idempotent: bool}
     */
    private function activate(
        User $target,
        string $email,
        string $expectedRole,
        string $operator,
        string $reason,
        array $bindings,
        string $plainText,
        int $ttlMinutes,
    ): array {
        $before = $this->accountState($target, true);
        $currentLease = $this->activeLease($target);
        $activationCommitment = $this->leaseGuard->credentialCommitment($plainText);

        if ($this->isExactActivatedState(
            $target, $before, $plainText, $currentLease, $bindings, $expectedRole,
        )) {
            $this->recordLifecycleAudit(
                self::AUDIT_ACTIVATED, $target, $email, $expectedRole, $operator, $reason,
                $bindings, $before, $before,
                ['sessions' => 0, 'passkeys' => 0, 'reset_records' => 0],
                false, true, $currentLease, $activationCommitment,
                $this->safeLoginStateCommitment((string) $target->password),
            );

            return ['mutated' => false, 'idempotent' => true];
        }

        if ($target->status === 'TEACHING_ACTIVE') {
            throw new TeachingRoleAccessException(
                'Teaching-role activation refused: the active target does not match its exact current lease.',
            );
        }
        if ($before['active_leases'] !== 0
            || $target->teaching_access_lease_public_id !== null
            || $target->teaching_access_expires_at_epoch !== null) {
            throw new TeachingRoleAccessException(
                'Teaching-role activation refused: the disabled target retains dangling lease evidence.',
            );
        }
        if (! $this->isPublicRevokedState($before) || $before['invariant_ok'] !== true) {
            throw new TeachingRoleAccessException(
                'Teaching-role activation refused: the target account is not in the exact fully closed state. Revoke it before activation.',
            );
        }
        if (TeachingRoleAccessLease::query()->where('credential_commitment', $activationCommitment)->exists()) {
            throw new TeachingRoleAccessException(
                'Teaching-role activation refused: this access value was already used for an earlier window.',
            );
        }

        $nowEpoch = $this->leaseGuard->databaseEpoch();
        $expiresAtEpoch = $nowEpoch + ($ttlMinutes * 60);
        $revoked = $this->revokeArtifacts($target);
        $passwordHash = Hash::make($plainText);
        $leasePublicId = (string) Str::ulid();

        $target->forceFill([
            'status' => 'DISABLED',
            'teaching_access_mutex' => (int) $target->teaching_access_mutex + 1,
        ])->save();

        $lease = TeachingRoleAccessLease::query()->create([
            'public_id' => $leasePublicId,
            'user_id' => $target->getKey(),
            'expected_role' => $expectedRole,
            'credential_commitment' => $activationCommitment,
            'password_state_commitment' => $this->safeLoginStateCommitment($passwordHash),
            'environment' => $bindings['environment'],
            'release_sha' => $bindings['release_sha'],
            'deployment_url' => $bindings['deployment_url'],
            'canonical_host' => $bindings['canonical_host'],
            'status' => 'ACTIVE',
            'active_slot' => 1,
            'activated_at_epoch' => $nowEpoch,
            'expires_at_epoch' => $expiresAtEpoch,
            'operator' => $operator,
            'reason' => $reason,
        ]);

        $target->forceFill([
            'password' => $passwordHash,
            'email_verified_at' => CarbonImmutable::createFromTimestampUTC($nowEpoch),
            'status' => 'TEACHING_ACTIVE',
            'remember_token' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'teaching_access_epoch' => (int) $target->teaching_access_epoch + 1,
            'teaching_access_lease_public_id' => $leasePublicId,
            'teaching_access_expires_at_epoch' => $expiresAtEpoch,
        ])->save();

        $target->refresh();
        $after = $this->accountState($target, false);
        if (! $this->isExactActivatedState(
            $target, $after, $plainText, $lease, $bindings, $expectedRole,
        )) {
            throw new TeachingRoleAccessException(
                sprintf(
                    'Teaching-role activation refused to commit an incomplete access-window transition (public=%s, lease=%s, time=%s, environment=%s, release=%s, access=%s, login=%s).',
                    $this->isPublicActivatedState($after) ? 'yes' : 'no',
                    $lease->status === 'ACTIVE' ? 'yes' : 'no',
                    $lease->expires_at_epoch > $this->leaseGuard->databaseEpoch() ? 'yes' : 'no',
                    $lease->environment === $bindings['environment'] ? 'yes' : 'no',
                    $lease->release_sha === $bindings['release_sha'] ? 'yes' : 'no',
                    hash_equals($lease->credential_commitment, $this->leaseGuard->credentialCommitment($plainText)) ? 'yes' : 'no',
                    Hash::check($plainText, (string) $target->password) ? 'yes' : 'no',
                ),
            );
        }

        $this->recordLifecycleAudit(
            self::AUDIT_ACTIVATED, $target, $email, $expectedRole, $operator, $reason,
            $bindings, $before, $after, $revoked, true, false, $lease,
            $activationCommitment,
            $this->safeLoginStateCommitment((string) $target->password),
        );

        return ['mutated' => true, 'idempotent' => false];
    }

    /**
     * @param  RuntimeBindings  $bindings
     * @return array{
     *     mutated: bool,
     *     idempotent: bool,
     *     before: AccountState,
     *     after: AccountState,
     *     revoked: ArtifactCounts,
     *     rotated: bool,
     *     last_lease_id: int,
     *     login_state_commitment: string,
     *     contained_epoch: int,
     *     contained_mutex: int,
     *     contained_lease_status: string,
     *     contained_lease_ended_at_epoch: int|null
     * }
     */
    private function revoke(
        User $target,
        string $email,
        string $expectedRole,
        string $operator,
        string $reason,
        array $bindings,
    ): array {
        $before = $this->accountState($target, true, false, true);
        $lease = $this->activeLease($target);
        $activeLeases = TeachingRoleAccessLease::query()
            ->where('user_id', $target->getKey())
            ->where('status', 'ACTIVE')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $lastLease = $lease ?? TeachingRoleAccessLease::query()
            ->where('user_id', $target->getKey())->orderByDesc('id')->first();
        $currentStateCommitment = $this->safeLoginStateCommitment((string) $target->password);
        $idempotent = $this->isPublicRevokedState($before)
            && $target->teaching_access_lease_public_id === null
            && $target->teaching_access_expires_at_epoch === null
            && $activeLeases->isEmpty()
            && $lastLease instanceof TeachingRoleAccessLease
            && $lastLease->status !== 'ACTIVE'
            && hash_equals($lastLease->password_state_commitment, $currentStateCommitment);
        $revoked = ['sessions' => 0, 'passkeys' => 0, 'reset_records' => 0];
        $rotated = false;

        if (! $idempotent) {
            $target->forceFill([
                'status' => 'DISABLED',
                'teaching_access_epoch' => (int) $target->teaching_access_epoch + 1,
                'teaching_access_mutex' => (int) $target->teaching_access_mutex + 1,
            ])->save();
            $revoked = $this->revokeArtifacts($target);
            $unknownHash = Hash::make(base64_encode(random_bytes(48)));
            $target->forceFill([
                'password' => $unknownHash,
                'email_verified_at' => null,
                'status' => 'DISABLED',
                'remember_token' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'teaching_access_lease_public_id' => null,
                'teaching_access_expires_at_epoch' => null,
            ])->save();

            if ($activeLeases->isNotEmpty()) {
                foreach ($activeLeases as $activeLease) {
                    $activeLease->forceFill([
                        'status' => 'REVOKED',
                        'active_slot' => null,
                        'ended_at_epoch' => $this->leaseGuard->databaseEpoch(),
                        'end_reason' => $reason,
                        'password_state_commitment' => $this->safeLoginStateCommitment($unknownHash),
                    ])->save();
                }
                $lastLease = $activeLeases->last();
            } elseif ($lease instanceof TeachingRoleAccessLease) {
                $lease->forceFill([
                    'status' => 'REVOKED',
                    'active_slot' => null,
                    'ended_at_epoch' => $this->leaseGuard->databaseEpoch(),
                    'end_reason' => $reason,
                    'password_state_commitment' => $this->safeLoginStateCommitment($unknownHash),
                ])->save();
                $lastLease = $lease;
            } else {
                $closedAtEpoch = $this->leaseGuard->databaseEpoch();
                $lastLease = TeachingRoleAccessLease::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'user_id' => $target->getKey(),
                    'expected_role' => $expectedRole,
                    'credential_commitment' => hash('sha256', random_bytes(48)),
                    'password_state_commitment' => $this->safeLoginStateCommitment($unknownHash),
                    'environment' => $bindings['environment'],
                    'release_sha' => $bindings['release_sha'],
                    'deployment_url' => $bindings['deployment_url'],
                    'canonical_host' => $bindings['canonical_host'],
                    'status' => 'REVOKED',
                    'active_slot' => null,
                    'activated_at_epoch' => $closedAtEpoch,
                    'expires_at_epoch' => $closedAtEpoch,
                    'ended_at_epoch' => $closedAtEpoch,
                    'end_reason' => $reason,
                    'operator' => $operator,
                    'reason' => $reason,
                ]);
            }
            $rotated = true;
        }

        $target->refresh();
        $after = $this->accountState($target, false, false, true);
        if (! $this->isPublicRevokedState($after)
            || $target->teaching_access_lease_public_id !== null
            || $target->teaching_access_expires_at_epoch !== null) {
            throw new TeachingRoleAccessException(
                'Teaching-role revocation refused to commit an incomplete access-window transition.',
            );
        }

        return [
            'mutated' => ! $idempotent,
            'idempotent' => $idempotent,
            'before' => $before,
            'after' => $after,
            'revoked' => $revoked,
            'rotated' => $rotated,
            'last_lease_id' => (int) $lastLease->getKey(),
            'login_state_commitment' => $this->safeLoginStateCommitment((string) $target->password),
            'contained_epoch' => (int) $target->teaching_access_epoch,
            'contained_mutex' => (int) $target->teaching_access_mutex,
            'contained_lease_status' => (string) $lastLease->status,
            'contained_lease_ended_at_epoch' => $lastLease->ended_at_epoch,
        ];
    }

    /** @param RuntimeBindings $bindings */
    private function compensateToDisabled(
        string $email,
        string $rosterKey,
        string $operator,
        string $reason,
        array $bindings,
    ): void {
        try {
            $mutations = DB::transaction(function () use (
                $email, $rosterKey, $operator, $reason, $bindings,
            ): array {
                $targets = User::query()
                    ->where(function ($query) use ($email, $rosterKey): void {
                        $query->where('email', $email)
                            ->orWhere('teaching_access_roster_key', $rosterKey);
                    })
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                if ($targets->isEmpty()) {
                    throw new TeachingRoleAccessException(
                        'Teaching-role compensation cannot find its roster identity.',
                    );
                }

                $results = [];
                foreach ($targets as $target) {
                    $before = $this->accountState($target, true, false, true);
                    $leases = TeachingRoleAccessLease::query()
                        ->where('user_id', $target->getKey())
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                    $activeLeases = $leases->where('status', 'ACTIVE');
                    $target->forceFill([
                        'status' => 'DISABLED',
                        'teaching_access_epoch' => (int) $target->teaching_access_epoch + 1,
                        'teaching_access_mutex' => (int) $target->teaching_access_mutex + 1,
                    ])->save();
                    $revoked = $this->revokeArtifacts($target);
                    $unknownHash = Hash::make(base64_encode(random_bytes(48)));
                    $target->forceFill([
                        'password' => $unknownHash,
                        'email_verified_at' => null,
                        'remember_token' => null,
                        'two_factor_secret' => null,
                        'two_factor_recovery_codes' => null,
                        'two_factor_confirmed_at' => null,
                        'teaching_access_lease_public_id' => null,
                        'teaching_access_expires_at_epoch' => null,
                    ])->save();
                    $endedAtEpoch = $this->leaseGuard->databaseEpoch();
                    foreach ($activeLeases as $activeLease) {
                        $activeLease->forceFill([
                            'status' => 'COMPENSATED',
                            'active_slot' => null,
                            'ended_at_epoch' => $endedAtEpoch,
                            'end_reason' => 'Final readback failed; access disabled by compensation.',
                            'password_state_commitment' => $this->safeLoginStateCommitment($unknownHash),
                        ])->save();
                    }
                    $lastLease = $activeLeases->last();
                    if (! $lastLease instanceof TeachingRoleAccessLease) {
                        $lastLease = TeachingRoleAccessLease::query()->create([
                            'public_id' => (string) Str::ulid(),
                            'user_id' => $target->getKey(),
                            'expected_role' => $rosterKey,
                            'credential_commitment' => hash('sha256', random_bytes(48)),
                            'password_state_commitment' => $this->safeLoginStateCommitment($unknownHash),
                            'environment' => $bindings['environment'],
                            'release_sha' => $bindings['release_sha'],
                            'deployment_url' => $bindings['deployment_url'],
                            'canonical_host' => $bindings['canonical_host'],
                            'status' => 'COMPENSATED',
                            'active_slot' => null,
                            'activated_at_epoch' => $endedAtEpoch,
                            'expires_at_epoch' => $endedAtEpoch,
                            'ended_at_epoch' => $endedAtEpoch,
                            'end_reason' => 'Final readback failed; access disabled by compensation.',
                            'operator' => $operator,
                            'reason' => $reason,
                        ]);
                    }

                    $target->refresh();
                    $after = $this->accountState($target, false, false, true);
                    if (! $this->isPublicRevokedState($after)
                        || TeachingRoleAccessLease::query()
                            ->where('user_id', $target->getKey())
                            ->where('status', 'ACTIVE')
                            ->exists()
                        || $target->teaching_access_lease_public_id !== null
                        || $target->teaching_access_expires_at_epoch !== null) {
                        throw new TeachingRoleAccessException(
                            'Teaching-role compensation did not establish the exact closed state.',
                        );
                    }

                    $results[] = [
                        'target_id' => (int) $target->getKey(),
                        'lease_id' => (int) $lastLease->getKey(),
                        'before' => $before,
                        'after' => $after,
                        'revoked' => $revoked,
                        'login_state_commitment' => $this->safeLoginStateCommitment((string) $target->password),
                        'contained_epoch' => (int) $target->teaching_access_epoch,
                        'contained_mutex' => (int) $target->teaching_access_mutex,
                        'contained_lease_status' => (string) $lastLease->status,
                        'contained_lease_ended_at_epoch' => $lastLease->ended_at_epoch,
                    ];
                }

                return $results;
            }, attempts: 1);
        } catch (Throwable $exception) {
            Log::critical('Teaching-role access compensation failed.', [
                'exception_class' => $exception::class,
                'failure_fingerprint' => hash('sha256', $exception::class."\0".$exception->getMessage()),
            ]);

            throw new TeachingRoleAccessException(
                'Teaching-role access compensation failed; closed-state containment is not verified. Immediate incident response is required.',
            );
        }

        try {
            DB::transaction(function () use (
                $mutations, $email, $rosterKey, $operator, $reason, $bindings,
            ): void {
                foreach ($mutations as $mutation) {
                    $target = User::query()->whereKey($mutation['target_id'])->lockForUpdate()->first();
                    $lease = TeachingRoleAccessLease::query()
                        ->whereKey($mutation['lease_id'])
                        ->lockForUpdate()
                        ->first();
                    if (! $target instanceof User || ! $lease instanceof TeachingRoleAccessLease) {
                        throw new TeachingRoleAccessException(
                            'Teaching-role compensation audit context is unavailable.',
                        );
                    }

                    $current = $this->accountState($target, true, false, true);
                    if (! $current['invariant_ok']
                        || ! $this->isPublicRevokedState($current)
                        || (int) $target->teaching_access_epoch !== $mutation['contained_epoch']
                        || (int) $target->teaching_access_mutex !== $mutation['contained_mutex']
                        || $lease->status !== $mutation['contained_lease_status']
                        || $lease->ended_at_epoch !== $mutation['contained_lease_ended_at_epoch']
                        || TeachingRoleAccessLease::query()
                            ->where('user_id', $target->getKey())
                            ->where('status', 'ACTIVE')
                            ->exists()
                        || $target->teaching_access_lease_public_id !== null
                        || $target->teaching_access_expires_at_epoch !== null) {
                        throw new TeachingRoleAccessException(
                            'Teaching-role compensation audit refused because containment state drifted.',
                        );
                    }

                    $this->recordLifecycleAudit(
                        self::AUDIT_COMPENSATED,
                        $target,
                        $email,
                        $rosterKey,
                        $operator,
                        $reason,
                        $bindings,
                        $mutation['before'],
                        $mutation['after'],
                        $mutation['revoked'],
                        true,
                        false,
                        $lease,
                        null,
                        $mutation['login_state_commitment'],
                    );
                }
            }, attempts: 1);
        } catch (Throwable $exception) {
            Log::critical('Teaching-role access compensation audit failed after containment.', [
                'exception_class' => $exception::class,
                'failure_fingerprint' => hash('sha256', $exception::class."\0".$exception->getMessage()),
            ]);

            throw new TeachingRoleAccessException(
                'Teaching-role access compensation completed containment but could not record its audit evidence. Immediate operator review is required.',
            );
        }
    }

    /**
     * @param  RuntimeBindings  $bindings
     * @param  AccountState  $before
     * @param  AccountState  $after
     * @param  ArtifactCounts  $revoked
     */
    private function recordLifecycleAudit(
        string $action,
        User $target,
        string $email,
        string $expectedRole,
        string $operator,
        string $reason,
        array $bindings,
        array $before,
        array $after,
        array $revoked,
        bool $rotated,
        bool $idempotent,
        ?TeachingRoleAccessLease $lease,
        ?string $activationCommitment,
        string $loginStateCommitment,
    ): void {
        $event = $this->auditRecorder->record(
            action: $action,
            resourceType: 'user',
            resourceId: (string) $target->public_id,
            outcome: 'SUCCESS',
            reason: $reason,
            metadata: [
                'target_email' => $email,
                'expected_role' => $expectedRole,
                'operator' => $operator,
                'reason' => $reason,
                'environment' => $bindings['environment'],
                'release_sha' => $bindings['release_sha'],
                'deployment_url' => $bindings['deployment_url'],
                'canonical_host' => $bindings['canonical_host'],
                'lease_public_id' => $lease?->public_id,
                'expires_at' => $this->epochIso($lease?->expires_at_epoch),
                'access_epoch' => (int) $target->teaching_access_epoch,
                'activation_commitment' => $activationCommitment,
                'login_state_commitment' => $loginStateCommitment,
                'before' => $this->auditState($before),
                'after' => $this->auditState($after),
                'revoked' => $revoked,
                'login_material_rotated' => $rotated,
                'idempotent' => $idempotent,
            ],
            includeRequestFingerprint: false,
        );
        if ($event === null) {
            throw new TeachingRoleAccessException(
                'Teaching-role access rolled back because its audit event could not be recorded.',
            );
        }
    }

    private function assertSafeSimulationMode(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new TeachingRoleAccessException(
                'Teaching-role access requires APP_MODE=SIMULATION and APP_SYNTHETIC_ONLY=true.',
            );
        }
        $defaultConnection = config('database.default');
        $sessionConnection = config('session.connection') ?: $defaultConnection;
        if (config('session.driver') !== 'database'
            || config('session.table') !== 'sessions'
            || ! is_string($defaultConnection)
            || $sessionConnection !== $defaultConnection) {
            throw new TeachingRoleAccessException(
                'Teaching-role access requires the database session driver on the application connection and sessions table.',
            );
        }
    }

    private function assertAction(string $action): void
    {
        if (! in_array($action, [self::ACTION_STATUS, self::ACTION_ACTIVATE, self::ACTION_REVOKE], true)) {
            throw new InvalidArgumentException('Action must be exactly one of: status, activate, revoke.');
        }
    }

    private function assertConfirmation(string $action, string $email, ?string $confirmation): void
    {
        $expected = self::confirmationPhrase($action, $email);
        if (! is_string($confirmation) || ! hash_equals($expected, $confirmation)) {
            throw new InvalidArgumentException("Confirmation refused. Use exactly: {$expected}");
        }
    }

    private function validatedAttribution(?string $value, string $field, int $minimum, int $maximum): string
    {
        $value = is_string($value) ? trim($value) : '';
        if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1F\x7F-\x9F]/u', $value) === 1) {
            throw new InvalidArgumentException("--{$field} contains prohibited characters.");
        }
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        if (mb_strlen($value) < $minimum || mb_strlen($value) > $maximum
            || in_array(strtolower($value), self::PLACEHOLDERS, true)) {
            throw new InvalidArgumentException(
                sprintf('--%s must be a non-placeholder value between %d and %d characters.', $field, $minimum, $maximum),
            );
        }

        return $value;
    }

    private function activationPassword(): string
    {
        $value = config('simulation.teaching_role_access_password');
        if (! is_string($value) || mb_strlen($value, '8bit') < 24) {
            throw new TeachingRoleAccessException(
                'Activation requires a unique TEACHING_ROLE_ACCESS_PASSWORD with at least 24 bytes.',
            );
        }

        return $value;
    }

    /** @return RuntimeBindings */
    private function validatedRuntimeBindings(
        ?string $expectedEnvironment,
        ?string $expectedReleaseSha,
        ?string $expectedDeploymentUrl,
        ?string $expectedCanonicalHost,
    ): array {
        $bindings = $this->leaseGuard->runtimeBindings();
        $environment = is_string($expectedEnvironment) ? trim($expectedEnvironment) : '';
        $releaseSha = is_string($expectedReleaseSha) ? strtolower(trim($expectedReleaseSha)) : '';
        $deploymentUrl = is_string($expectedDeploymentUrl) ? strtolower(trim($expectedDeploymentUrl)) : '';
        $canonicalHost = is_string($expectedCanonicalHost) ? strtolower(trim($expectedCanonicalHost)) : '';
        if (! hash_equals($bindings['environment'], $environment)
            || ! hash_equals($bindings['release_sha'], $releaseSha)
            || ! hash_equals($bindings['deployment_url'], $deploymentUrl)
            || ! hash_equals($bindings['canonical_host'], $canonicalHost)) {
            throw new TeachingRoleAccessException(
                'Teaching-role access expected deployment binding does not match the trusted runtime binding.',
            );
        }

        return $bindings;
    }

    /** @return RuntimeBindings */
    private function containmentRuntimeBindings(
        ?string $expectedEnvironment,
        ?string $expectedReleaseSha,
        ?string $expectedDeploymentUrl,
        ?string $expectedCanonicalHost,
    ): array {
        try {
            return $this->leaseGuard->runtimeBindings();
        } catch (Throwable $exception) {
            Log::warning('Teaching-role revocation is using drift-containment bindings.', [
                'exception_class' => $exception::class,
                'failure_fingerprint' => hash('sha256', $exception::class."\0".$exception->getMessage()),
            ]);

            $environment = is_string($expectedEnvironment)
                && preg_match('/\A[a-z0-9][a-z0-9._-]{2,63}\z/', $expectedEnvironment) === 1
                    ? $expectedEnvironment
                    : 'containment-drift';
            $releaseSha = is_string($expectedReleaseSha)
                && preg_match('/\A[a-f0-9]{40}\z/', strtolower($expectedReleaseSha)) === 1
                    ? strtolower($expectedReleaseSha)
                    : str_repeat('0', 40);
            $deploymentUrl = is_string($expectedDeploymentUrl)
                && preg_match('/\A[a-z0-9][a-z0-9.-]{2,253}\z/', strtolower($expectedDeploymentUrl)) === 1
                    ? strtolower($expectedDeploymentUrl)
                    : 'containment.invalid';
            $canonicalHost = is_string($expectedCanonicalHost)
                && preg_match('/\A[a-z0-9][a-z0-9.-]{2,253}\z/', strtolower($expectedCanonicalHost)) === 1
                    ? strtolower($expectedCanonicalHost)
                    : 'containment.invalid';

            return [
                'environment' => $environment,
                'release_sha' => $releaseSha,
                'deployment_url' => $deploymentUrl,
                'canonical_host' => $canonicalHost,
            ];
        }
    }

    private function validatedTtl(?int $ttlMinutes): int
    {
        $maximum = config('simulation.teaching_role_access_max_ttl_minutes');
        if (! is_int($maximum) || $maximum < 5 || $maximum > 60
            || ! is_int($ttlMinutes) || $ttlMinutes < 5 || $ttlMinutes > $maximum) {
            throw new TeachingRoleAccessException(
                'Teaching-role activation TTL must be between 5 minutes and the configured maximum.',
            );
        }

        return $ttlMinutes;
    }

    /** @return Collection<string, User> */
    private function lockedRoster(): Collection
    {
        $effectiveRoster = self::effectiveRoster();

        /** @var Collection<string, User> $roster */
        $roster = User::query()->whereIn('email', array_keys($effectiveRoster))
            ->orderBy('email')->lockForUpdate()->get()
            ->keyBy(fn (User $user): string => (string) $user->email);

        return $roster;
    }

    /** @param Collection<string, User> $roster */
    private function lockRoleAssignments(Collection $roster): void
    {
        DB::table(SchemaQualifier::table('role_user'))
            ->whereIn('user_id', $roster->pluck('id')->all())
            ->orderBy('user_id')->orderBy('role_id')->lockForUpdate()->get();
    }

    /** @param Collection<string, User> $roster */
    private function lockLeases(Collection $roster): void
    {
        TeachingRoleAccessLease::query()->whereIn('user_id', $roster->pluck('id')->all())
            ->orderBy('user_id')->orderBy('id')->lockForUpdate()->get();
    }

    private function assertExactAccountInvariant(User $user, string $email, string $role): void
    {
        if ($user->email !== $email) {
            throw new TeachingRoleAccessException('Teaching-role access refused: roster email drift was detected.');
        }
        if ($user->is_system_administrator !== false) {
            throw new TeachingRoleAccessException("Teaching-role access refused: {$email} has administrator drift.");
        }
        if ($user->teaching_access_roster_key !== $role) {
            throw new TeachingRoleAccessException("Teaching-role access refused: {$email} has roster identity drift.");
        }
        if ($this->roleSlugs($user) !== [$role]) {
            throw new TeachingRoleAccessException("Teaching-role access refused: {$email} does not have its exact expected role.");
        }
    }

    /** @param Collection<string, User> $roster */
    private function assertCompleteRosterInvariant(Collection $roster): void
    {
        $effectiveRoster = self::effectiveRoster();
        if ($roster->count() !== count($effectiveRoster)) {
            throw new TeachingRoleAccessException('Teaching-role activation refused: the exact demo-account roster is incomplete.');
        }
        foreach ($effectiveRoster as $email => $role) {
            $user = $roster->get($email);
            if (! $user instanceof User) {
                throw new TeachingRoleAccessException('Teaching-role activation refused: the exact demo-account roster is incomplete.');
            }
            $this->assertExactAccountInvariant($user, $email, $role);
        }
    }

    /** @param Collection<string, User> $roster */
    private function assertNoOtherActiveAccount(Collection $roster, string $targetEmail): void
    {
        foreach ($roster as $email => $other) {
            if ($email === $targetEmail) {
                continue;
            }

            $state = $this->accountState($other, true, false);
            if ($other->status === 'TEACHING_ACTIVE') {
                throw new TeachingRoleAccessException(
                    "Teaching-role activation refused: another roster account is active ({$other->email}).",
                );
            }
            if (! $this->isPublicRevokedState($state)
                || $state['active_leases'] !== 0
                || $other->teaching_access_lease_public_id !== null
                || $other->teaching_access_expires_at_epoch !== null) {
                throw new TeachingRoleAccessException(
                    "Teaching-role activation refused: non-target roster account is not fully closed ({$other->email}).",
                );
            }
        }
    }

    /** @return list<string> */
    private function roleSlugs(User $user): array
    {
        /** @var list<string> $roles */
        $roles = $user->roles()->pluck('slug')->map(
            static fn (mixed $role): string => (string) $role,
        )->unique()->sort()->values()->all();

        return $roles;
    }

    private function activeLease(User $user): ?TeachingRoleAccessLease
    {
        if (! is_string($user->teaching_access_lease_public_id)
            || $user->teaching_access_lease_public_id === '') {
            return null;
        }

        return TeachingRoleAccessLease::query()
            ->where('public_id', $user->teaching_access_lease_public_id)
            ->where('user_id', $user->getKey())->lockForUpdate()->first();
    }

    /** @return AccountState */
    private function accountState(
        User $user,
        bool $lockArtifacts,
        bool $evaluateLease = true,
        bool $useCanonicalRoster = false,
    ): array {
        $sessions = DB::table(SchemaQualifier::table('sessions'))->where('user_id', $user->getKey());
        $passkeys = DB::table(SchemaQualifier::table('passkeys'))->where('user_id', $user->getKey());
        $resets = DB::table(SchemaQualifier::table('password_reset_tokens'))->where('email', $user->email);
        if ($lockArtifacts) {
            $sessionCount = count($sessions->lockForUpdate()->get()->all());
            $passkeyCount = count($passkeys->lockForUpdate()->get()->all());
            $resetCount = count($resets->lockForUpdate()->get()->all());
        } else {
            $sessionCount = $sessions->count();
            $passkeyCount = $passkeys->count();
            $resetCount = $resets->count();
        }

        $roles = $this->roleSlugs($user);
        $leaseValid = $evaluateLease && $this->leaseGuard->allows($user);
        $effectiveRoster = $useCanonicalRoster ? self::ROSTER : self::effectiveRoster();
        $canonicalRole = $effectiveRoster[strtolower((string) $user->email)] ?? null;
        $markerRole = is_string($user->teaching_access_roster_key)
            && in_array($user->teaching_access_roster_key, array_values($effectiveRoster), true)
                ? $user->teaching_access_roster_key
                : null;
        $expectedRole = $canonicalRole ?? $markerRole;
        $expectedEmail = $expectedRole === null ? null : array_search($expectedRole, $effectiveRoster, true);
        $activeLeaseCount = TeachingRoleAccessLease::query()
            ->where('user_id', $user->getKey())
            ->where('status', 'ACTIVE')
            ->count();
        $state = [
            'email' => (string) $user->email,
            'expected_role' => $expectedRole,
            'roles' => $roles,
            'status' => (string) $user->status,
            'is_system_administrator' => (bool) $user->is_system_administrator,
            'verified' => $user->email_verified_at !== null,
            'remember_present' => $user->remember_token !== null && $user->remember_token !== '',
            'mfa_present' => $user->two_factor_secret !== null
                || $user->two_factor_recovery_codes !== null
                || $user->two_factor_confirmed_at !== null,
            'sessions' => $sessionCount,
            'passkeys' => $passkeyCount,
            'reset_records' => $resetCount,
            'access_epoch' => (int) $user->teaching_access_epoch,
            'lease_public_id' => $user->teaching_access_lease_public_id,
            'expires_at' => $this->epochIso($user->teaching_access_expires_at_epoch),
            'lease_valid' => $leaseValid,
            'active_leases' => $activeLeaseCount,
            'identity_candidates' => 1,
            'invariant_ok' => false,
        ];

        $identityExact = is_string($expectedEmail)
            && $canonicalRole !== null
            && $user->teaching_access_roster_key === $canonicalRole
            && strtolower((string) $user->email) === $expectedEmail
            && $roles === [$expectedRole]
            && $user->is_system_administrator === false;
        $state['invariant_ok'] = $identityExact && (
            ($this->isPublicActivatedState($state) && $leaseValid)
            || ($this->isPublicRevokedState($state)
                && $activeLeaseCount === 0
                && $user->teaching_access_lease_public_id === null
                && $user->teaching_access_expires_at_epoch === null)
        );

        return $state;
    }

    /** @return ArtifactCounts */
    private function revokeArtifacts(User $target): array
    {
        return [
            'sessions' => DB::table(SchemaQualifier::table('sessions'))->where('user_id', $target->getKey())->delete(),
            'passkeys' => DB::table(SchemaQualifier::table('passkeys'))->where('user_id', $target->getKey())->delete(),
            'reset_records' => DB::table(SchemaQualifier::table('password_reset_tokens'))->where('email', $target->email)->delete(),
        ];
    }

    /**
     * @param  AccountState  $state
     * @param  RuntimeBindings  $bindings
     */
    private function isExactActivatedState(
        User $target,
        array $state,
        string $plainText,
        ?TeachingRoleAccessLease $lease,
        array $bindings,
        string $expectedRole,
    ): bool {
        return $this->isPublicActivatedState($state)
            && $state['lease_valid'] === true
            && $state['active_leases'] === 1
            && $lease instanceof TeachingRoleAccessLease
            && $lease->status === 'ACTIVE'
            && $lease->active_slot === 1
            && $lease->expected_role === $expectedRole
            && $target->teaching_access_roster_key === $expectedRole
            && $target->teaching_access_lease_public_id === $lease->public_id
            && $target->teaching_access_expires_at_epoch === $lease->expires_at_epoch
            && $lease->expires_at_epoch > $this->leaseGuard->databaseEpoch()
            && $lease->environment === $bindings['environment']
            && $lease->release_sha === $bindings['release_sha']
            && $lease->deployment_url === $bindings['deployment_url']
            && $lease->canonical_host === $bindings['canonical_host']
            && hash_equals($lease->credential_commitment, $this->leaseGuard->credentialCommitment($plainText))
            && hash_equals(
                $lease->password_state_commitment,
                $this->safeLoginStateCommitment((string) $target->password),
            )
            && Hash::check($plainText, (string) $target->password);
    }

    /** @param AccountState $state */
    private function isPublicActivatedState(array $state): bool
    {
        return $state['status'] === 'TEACHING_ACTIVE' && $state['verified'] === true
            && $state['remember_present'] === false && $state['mfa_present'] === false
            && $state['sessions'] === 0 && $state['passkeys'] === 0 && $state['reset_records'] === 0;
    }

    /** @param AccountState $state */
    private function isPublicRevokedState(array $state): bool
    {
        return $state['status'] === 'DISABLED' && $state['verified'] === false
            && $state['remember_present'] === false && $state['mfa_present'] === false
            && $state['sessions'] === 0 && $state['passkeys'] === 0 && $state['reset_records'] === 0;
    }

    /**
     * @param  AccountState  $state
     * @return array{status: string, verified: bool, remember_present: bool, mfa_present: bool, sessions: int, passkeys: int, reset_records: int}
     */
    private function auditState(array $state): array
    {
        return [
            'status' => $state['status'],
            'verified' => $state['verified'],
            'remember_present' => $state['remember_present'],
            'mfa_present' => $state['mfa_present'],
            'sessions' => $state['sessions'],
            'passkeys' => $state['passkeys'],
            'reset_records' => $state['reset_records'],
        ];
    }

    private function epochIso(?int $epoch): ?string
    {
        return $epoch === null ? null : CarbonImmutable::createFromTimestampUTC($epoch)->toIso8601String();
    }

    private function safeLoginStateCommitment(string $loginHash): string
    {
        try {
            return $this->leaseGuard->passwordStateCommitment($loginHash);
        } catch (Throwable $exception) {
            Log::warning('Teaching-role containment commitment key is unavailable.', [
                'exception_class' => $exception::class,
                'failure_fingerprint' => hash('sha256', $exception::class."\0".$exception->getMessage()),
            ]);

            return hash('sha256', "teaching-role-containment-state\0{$loginHash}");
        }
    }

    /** @return LifecycleResult */
    private function readbackResult(
        string $action,
        string $targetEmail,
        bool $mutated,
        bool $idempotent,
        bool $auditRecorded,
    ): array {
        $effectiveRoster = self::effectiveRoster();
        $reportedRoster = $effectiveRoster;
        if ($action === self::ACTION_REVOKE
            && isset(self::ROSTER[$targetEmail])
            && ! isset($effectiveRoster[$targetEmail])) {
            $reportedRoster[$targetEmail] = self::ROSTER[$targetEmail];
        }
        $users = User::query()
            ->whereIn('email', array_keys($reportedRoster))
            ->orWhereIn('teaching_access_roster_key', array_values($reportedRoster))
            ->get();
        $matches = [];
        $candidateUseCounts = [];
        foreach ($reportedRoster as $email => $expectedRole) {
            $matches[$email] = $users->filter(
                fn (User $candidate): bool => strtolower((string) $candidate->email) === $email
                    || $candidate->teaching_access_roster_key === $expectedRole,
            )->values();
            foreach ($matches[$email] as $candidate) {
                $candidateId = (int) $candidate->getKey();
                $candidateUseCounts[$candidateId] = ($candidateUseCounts[$candidateId] ?? 0) + 1;
            }
        }

        $roster = [];
        foreach ($reportedRoster as $email => $expectedRole) {
            if (! array_key_exists($email, $matches)) {
                throw new LogicException('Teaching-role roster grouping is incomplete.');
            }
            $candidates = $matches[$email];
            $user = $candidates->count() === 1 ? $candidates->first() : null;
            $ambiguous = $user instanceof User
                && ($candidateUseCounts[(int) $user->getKey()] ?? 0) !== 1;
            if (! $user instanceof User || $ambiguous) {
                $roster[] = [
                    'email' => $email, 'expected_role' => $expectedRole, 'roles' => [],
                    'status' => $candidates->isEmpty() ? 'MISSING' : 'AMBIGUOUS',
                    'is_system_administrator' => false,
                    'verified' => false, 'remember_present' => false, 'mfa_present' => false,
                    'sessions' => 0, 'passkeys' => 0, 'reset_records' => 0,
                    'access_epoch' => 0, 'lease_public_id' => null, 'expires_at' => null,
                    'lease_valid' => false, 'invariant_ok' => false,
                    'active_leases' => 0,
                    'identity_candidates' => $candidates->count(),
                ];

                continue;
            }
            $state = $this->accountState(
                $user,
                false,
                useCanonicalRoster: ! isset($effectiveRoster[$email]),
            );
            $roster[] = $state;
        }

        return [
            'action' => $action,
            'target_email' => $targetEmail,
            'mutated' => $mutated,
            'idempotent' => $idempotent,
            'audit_recorded' => $auditRecorded,
            'roster' => $roster,
            'aggregate' => [
                'active' => count(array_filter($roster, fn (array $state): bool => $state['status'] === 'TEACHING_ACTIVE')),
                'disabled' => count(array_filter($roster, fn (array $state): bool => $state['status'] === 'DISABLED')),
                'missing' => count(array_filter($roster, fn (array $state): bool => $state['status'] === 'MISSING')),
                'drifted' => count(array_filter($roster, fn (array $state): bool => $state['invariant_ok'] === false)),
                'sessions' => array_sum(array_column($roster, 'sessions')),
                'passkeys' => array_sum(array_column($roster, 'passkeys')),
                'reset_records' => array_sum(array_column($roster, 'reset_records')),
            ],
        ];
    }
}
