<?php

namespace App\Support\Authorization;

use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Database\SchemaQualifier;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class RebuildAdminRoleReconciler
{
    /** @var list<string> */
    private const CANONICAL_ROLES = [RoleCapabilityMatrix::ROLE_ADMIN];

    /** @var list<string> */
    private const KNOWN_DRIFT_ROLES = [
        RoleCapabilityMatrix::ROLE_ADMIN,
        RoleCapabilityMatrix::ROLE_NURSE,
        RoleCapabilityMatrix::ROLE_PHYSICIAN,
        RoleCapabilityMatrix::ROLE_REGISTRAR,
        RoleCapabilityMatrix::ROLE_RMIK,
    ];

    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    /**
     * @return array{
     *     applied: bool,
     *     target_email: string,
     *     target_public_id: string,
     *     before_roles: list<string>,
     *     after_roles: list<string>,
     *     before_status: string,
     *     after_status: string,
     *     sessions_found: int,
     *     sessions_revoked: int,
     *     roles_changed: bool,
     *     status_changed: bool,
     *     mutated: bool,
     *     audit_recorded: bool,
     *     system_admin_bypass_remains: true
     * }
     */
    public function reconcile(
        bool $apply = false,
        bool $disable = false,
        ?string $operator = null,
        ?string $reason = null,
    ): array {
        $this->assertSafeSimulationMode();

        if ($apply) {
            $operator = $this->validatedAttribution($operator, 'operator', 3);
            $reason = $this->validatedAttribution($reason, 'reason', 8);
        }

        if (! $apply) {
            $target = $this->validatedTarget(lockForUpdate: false);

            return $this->result(
                target: $target,
                beforeRoles: $this->roleSlugs($target),
                beforeStatus: (string) $target->status,
                disable: $disable,
                sessionsFound: $this->sessionCount($target),
                applied: false,
            );
        }

        return DB::transaction(function () use ($disable, $operator, $reason): array {
            $target = $this->validatedTarget(lockForUpdate: true);
            $beforeRoles = $this->roleSlugs($target);
            $beforeStatus = (string) $target->status;
            $sessionsFound = $this->sessionCount($target);

            $adminRole = Role::query()
                ->where('slug', RoleCapabilityMatrix::ROLE_ADMIN)
                ->first();

            if ($adminRole === null) {
                throw new RuntimeException('Rebuild admin reconciliation refused: canonical admin role is missing.');
            }

            $rolesChanged = $beforeRoles !== self::CANONICAL_ROLES;
            $afterStatus = $disable ? 'DISABLED' : $beforeStatus;
            $statusChanged = $beforeStatus !== $afterStatus;

            if ($rolesChanged) {
                $target->roles()->sync([$adminRole->getKey()]);
            }

            if ($statusChanged) {
                $target->forceFill(['status' => $afterStatus])->save();
            }

            $sessionsRevoked = DB::table(SchemaQualifier::table('sessions'))
                ->where('user_id', $target->getKey())
                ->delete();

            $mutated = $rolesChanged || $statusChanged || $sessionsRevoked > 0;
            $note = 'is_system_administrator remains true; the system-admin capability bypass remains active.';

            $auditEvent = $this->auditRecorder->record(
                action: 'authorization.rebuild_admin.reconciled',
                resourceType: 'user',
                resourceId: (string) $target->public_id,
                outcome: 'SUCCESS',
                reason: $reason,
                metadata: [
                    'before' => [
                        'roles' => $beforeRoles,
                        'status' => $beforeStatus,
                    ],
                    'after' => [
                        'roles' => self::CANONICAL_ROLES,
                        'status' => $afterStatus,
                    ],
                    'sessions_revoked' => $sessionsRevoked,
                    'operator' => $operator,
                    'reason' => $reason,
                    'disable_requested' => $disable,
                    'roles_changed' => $rolesChanged,
                    'status_changed' => $statusChanged,
                    'mutated' => $mutated,
                    'system_admin_bypass_remains' => true,
                    'note' => $note,
                ],
                includeRequestFingerprint: false,
            );

            if ($auditEvent === null) {
                throw new RuntimeException('Rebuild admin reconciliation rolled back because its audit event could not be recorded.');
            }

            return $this->result(
                target: $target,
                beforeRoles: $beforeRoles,
                beforeStatus: $beforeStatus,
                disable: $disable,
                sessionsFound: $sessionsFound,
                applied: true,
                sessionsRevoked: $sessionsRevoked,
                auditRecorded: true,
            );
        });
    }

    private function assertSafeSimulationMode(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException(
                'Rebuild admin reconciliation requires APP_MODE=SIMULATION and APP_SYNTHETIC_ONLY=true.',
            );
        }
    }

    private function validatedTarget(bool $lockForUpdate): User
    {
        $email = config('simulation.rebuild_admin_email');

        if (! is_string($email) || trim($email) === '') {
            throw new RuntimeException('Rebuild admin reconciliation refused: configured target email is missing.');
        }

        $query = User::query()->where('email', $email);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $target = $query->first();

        if ($target === null) {
            throw new RuntimeException('Rebuild admin reconciliation refused: configured target user does not exist.');
        }

        if ($target->is_system_administrator !== true) {
            throw new RuntimeException(
                'Rebuild admin reconciliation refused: configured target is not a system administrator.',
            );
        }

        if ($lockForUpdate) {
            DB::table(SchemaQualifier::table('role_user'))
                ->where('user_id', $target->getKey())
                ->lockForUpdate()
                ->get();
        }

        $roles = $this->roleSlugs($target);

        if (! in_array(RoleCapabilityMatrix::ROLE_ADMIN, $roles, true)) {
            throw new RuntimeException('Rebuild admin reconciliation refused: configured target lacks the admin role.');
        }

        if ($roles !== self::CANONICAL_ROLES && $roles !== self::KNOWN_DRIFT_ROLES) {
            throw new RuntimeException(
                'Rebuild admin reconciliation refused: target role set is neither canonical nor the known drift set.',
            );
        }

        return $target;
    }

    /** @return list<string> */
    private function roleSlugs(User $target): array
    {
        /** @var list<string> $roles */
        $roles = $target->roles()
            ->pluck('slug')
            ->map(static fn (mixed $slug): string => (string) $slug)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $roles;
    }

    private function sessionCount(User $target): int
    {
        return DB::table(SchemaQualifier::table('sessions'))
            ->where('user_id', $target->getKey())
            ->count();
    }

    private function validatedAttribution(?string $value, string $field, int $minimumLength): string
    {
        $value = trim((string) $value);
        $normalized = strtolower($value);
        $placeholders = [
            '-',
            'n/a',
            'na',
            'none',
            'null',
            'operator',
            'placeholder',
            'reason',
            'tbd',
            'test',
            'todo',
            'unknown',
        ];

        if (mb_strlen($value) < $minimumLength || in_array($normalized, $placeholders, true)) {
            throw new InvalidArgumentException(
                sprintf('--%s must be a non-placeholder value of at least %d characters.', $field, $minimumLength),
            );
        }

        return $value;
    }

    /**
     * @param  list<string>  $beforeRoles
     * @return array{
     *     applied: bool,
     *     target_email: string,
     *     target_public_id: string,
     *     before_roles: list<string>,
     *     after_roles: list<string>,
     *     before_status: string,
     *     after_status: string,
     *     sessions_found: int,
     *     sessions_revoked: int,
     *     roles_changed: bool,
     *     status_changed: bool,
     *     mutated: bool,
     *     audit_recorded: bool,
     *     system_admin_bypass_remains: true
     * }
     */
    private function result(
        User $target,
        array $beforeRoles,
        string $beforeStatus,
        bool $disable,
        int $sessionsFound,
        bool $applied,
        int $sessionsRevoked = 0,
        bool $auditRecorded = false,
    ): array {
        $afterStatus = $disable ? 'DISABLED' : $beforeStatus;
        $rolesChanged = $beforeRoles !== self::CANONICAL_ROLES;
        $statusChanged = $beforeStatus !== $afterStatus;

        return [
            'applied' => $applied,
            'target_email' => (string) $target->email,
            'target_public_id' => (string) $target->public_id,
            'before_roles' => $beforeRoles,
            'after_roles' => self::CANONICAL_ROLES,
            'before_status' => $beforeStatus,
            'after_status' => $afterStatus,
            'sessions_found' => $sessionsFound,
            'sessions_revoked' => $sessionsRevoked,
            'roles_changed' => $rolesChanged,
            'status_changed' => $statusChanged,
            'mutated' => $applied && ($rolesChanged || $statusChanged || $sessionsRevoked > 0),
            'audit_recorded' => $auditRecorded,
            'system_admin_bypass_remains' => true,
        ];
    }
}
