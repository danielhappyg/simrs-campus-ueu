<?php

namespace App\Support\Audit;

use App\Support\Database\SchemaQualifier;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class AuditActorAttributionPreflight
{
    public const CURRENT = 'CURRENT';

    public const BACKFILLABLE_USER = 'BACKFILLABLE_USER';

    public const BACKFILLABLE_SERVICE = 'BACKFILLABLE_SERVICE';

    public const BLOCKING = 'BLOCKING';

    public const INVALID_AUDIT_ID = 'INVALID_AUDIT_ID';

    public const USER_NOT_FOUND = 'USER_NOT_FOUND';

    public const INVALID_USER_PUBLIC_ID = 'INVALID_USER_PUBLIC_ID';

    public const PARTIAL_ATTRIBUTION = 'PARTIAL_ATTRIBUTION';

    public const USER_ATTRIBUTION_MISMATCH = 'USER_ATTRIBUTION_MISMATCH';

    public const SERVICE_ATTRIBUTION_MISMATCH = 'SERVICE_ATTRIBUTION_MISMATCH';

    public const ACTOR_REQUIRED_BUT_MISSING = 'ACTOR_REQUIRED_BUT_MISSING';

    /** @var list<string> */
    public const OPERATIONAL_CODES = [
        'UNSAFE_RUNTIME',
        'UNSUPPORTED_DRIVER',
        'SCHEMA_NOT_EXPANDED',
        'DIGEST_KEY_UNAVAILABLE',
        'EXISTING_TRANSACTION_UNSAFE',
        'DATABASE_SCAN_FAILED',
    ];

    /** @var list<string> */
    private const CLASSIFICATIONS = [
        self::CURRENT,
        self::BACKFILLABLE_USER,
        self::BACKFILLABLE_SERVICE,
        self::BLOCKING,
    ];

    /** @var list<string> */
    private const BLOCKING_CODES = [
        self::INVALID_AUDIT_ID,
        self::USER_NOT_FOUND,
        self::INVALID_USER_PUBLIC_ID,
        self::PARTIAL_ATTRIBUTION,
        self::USER_ATTRIBUTION_MISMATCH,
        self::SERVICE_ATTRIBUTION_MISMATCH,
        self::ACTOR_REQUIRED_BUT_MISSING,
    ];

    /**
     * @return array{
     *   schema_version: int,
     *   command: string,
     *   mode: string,
     *   result: string,
     *   rows_scanned: int,
     *   counts: array<string, int>,
     *   blocking_reason_counts: array<string, int>,
     *   contract_ready: bool,
     *   backfill_ready: bool,
     *   root_digest_algorithm: string,
     *   root_digest: string
     * }
     */
    public function scan(): array
    {
        $this->assertPreconditions();

        $key = (string) config('app.key');
        $connection = DB::connection();

        try {
            if ($connection->transactionLevel() > 0) {
                throw new RuntimeException('EXISTING_TRANSACTION_UNSAFE');
            }

            if ($connection->getDriverName() === 'mysql') {
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            return $connection->transaction(function () use ($connection, $key): array {
                if ($connection->getDriverName() === 'pgsql') {
                    $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                }

                return $this->scanWithinSnapshot($connection, $key);
            }, 1);
        } catch (RuntimeException $exception) {
            if (in_array($exception->getMessage(), self::OPERATIONAL_CODES, true)) {
                throw $exception;
            }

            throw new RuntimeException('DATABASE_SCAN_FAILED', previous: $exception);
        } catch (Throwable $exception) {
            throw new RuntimeException('DATABASE_SCAN_FAILED', previous: $exception);
        }
    }

    private function assertPreconditions(): void
    {
        if (strtoupper((string) config('simulation.mode')) !== 'SIMULATION'
            || filter_var(config('simulation.synthetic_only'), FILTER_VALIDATE_BOOLEAN) !== true) {
            throw new RuntimeException('UNSAFE_RUNTIME');
        }

        if (! in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql', 'mysql'], true)) {
            throw new RuntimeException('UNSUPPORTED_DRIVER');
        }

        $auditTable = SchemaQualifier::table('audit_events');
        if (! Schema::hasColumns($auditTable, [
            'id',
            'action',
            'actor_user_id',
            'actor_type',
            'actor_reference',
        ])) {
            throw new RuntimeException('SCHEMA_NOT_EXPANDED');
        }

        if (trim((string) config('app.key')) === '') {
            throw new RuntimeException('DIGEST_KEY_UNAVAILABLE');
        }
    }

    /**
     * @return array{
     *   schema_version: int,
     *   command: string,
     *   mode: string,
     *   result: string,
     *   rows_scanned: int,
     *   counts: array<string, int>,
     *   blocking_reason_counts: array<string, int>,
     *   contract_ready: bool,
     *   backfill_ready: bool,
     *   root_digest_algorithm: string,
     *   root_digest: string
     * }
     */
    private function scanWithinSnapshot(Connection $connection, string $key): array
    {
        $counts = array_fill_keys(self::CLASSIFICATIONS, 0);
        $blockingReasonCounts = array_fill_keys(self::BLOCKING_CODES, 0);
        $digest = hash_init('sha256', HASH_HMAC, $key);
        hash_update($digest, "SIMRS-AUDIT-ACTOR-ATTRIBUTION-PREFLIGHT\0V1\0");
        $rowsScanned = 0;
        $auditTable = SchemaQualifier::table('audit_events');
        $usersTable = SchemaQualifier::table('users');

        $rows = $connection->table($auditTable.' as ae')
            ->leftJoin($usersTable.' as u', 'u.id', '=', 'ae.actor_user_id')
            ->select([
                'ae.id as audit_id',
                'ae.action',
                'ae.actor_user_id',
                'ae.actor_type',
                'ae.actor_reference',
                'u.id as resolved_user_id',
                'u.public_id as resolved_user_public_id',
            ])
            ->lazyById(500, 'ae.id', 'audit_id');

        foreach ($rows as $row) {
            $auditId = $row->audit_id ?? null;
            $action = $row->action ?? null;
            $actorUserId = $row->actor_user_id ?? null;
            $actorType = $row->actor_type ?? null;
            $actorReference = $row->actor_reference ?? null;
            $resolvedUserId = $row->resolved_user_id ?? null;
            $resolvedUserPublicId = $row->resolved_user_public_id ?? null;
            $actorUserId = $this->normalizeInteger($actorUserId);
            $resolvedUserId = $this->normalizeInteger($resolvedUserId);
            [$classification, $blockingCode] = $this->classify(
                auditId: $auditId,
                action: $action,
                actorUserId: $actorUserId,
                actorType: $actorType,
                actorReference: $actorReference,
                resolvedUserId: $resolvedUserId,
                resolvedUserPublicId: $resolvedUserPublicId,
            );

            $counts[$classification]++;
            if ($blockingCode !== null) {
                $blockingReasonCounts[$blockingCode]++;
            }
            $rowsScanned++;

            $leaf = $this->frame([
                $auditId,
                $action,
                $actorUserId,
                $actorType,
                $actorReference,
                $resolvedUserId,
                $resolvedUserPublicId,
                $classification,
                $blockingCode,
            ]);
            hash_update($digest, pack('N', strlen($leaf)).$leaf);
        }

        $result = $counts[self::BLOCKING] > 0
            ? self::BLOCKING
            : (($counts[self::BACKFILLABLE_USER] + $counts[self::BACKFILLABLE_SERVICE]) > 0
                ? 'BACKFILL_REQUIRED'
                : self::CURRENT);

        return [
            'schema_version' => 1,
            'command' => 'audit:attribution:preflight',
            'mode' => 'READ_ONLY',
            'result' => $result,
            'rows_scanned' => $rowsScanned,
            'counts' => $counts,
            'blocking_reason_counts' => $blockingReasonCounts,
            'contract_ready' => $result === self::CURRENT,
            'backfill_ready' => $result !== self::BLOCKING,
            'root_digest_algorithm' => 'hmac-sha256-length-prefixed-v1',
            'root_digest' => hash_final($digest),
        ];
    }

    /** @return array{string, string|null} */
    private function classify(
        mixed $auditId,
        mixed $action,
        mixed $actorUserId,
        mixed $actorType,
        mixed $actorReference,
        mixed $resolvedUserId,
        mixed $resolvedUserPublicId,
    ): array {
        if (! $this->isUppercaseUlid($auditId)) {
            return [self::BLOCKING, self::INVALID_AUDIT_ID];
        }

        $hasType = $actorType !== null;
        $hasReference = $actorReference !== null;
        if ($hasType !== $hasReference) {
            return [self::BLOCKING, self::PARTIAL_ATTRIBUTION];
        }

        if ($actorUserId !== null) {
            if ($resolvedUserId === null || (string) $resolvedUserId !== (string) $actorUserId) {
                return [self::BLOCKING, self::USER_NOT_FOUND];
            }

            if (! $this->isUppercaseUlid($resolvedUserPublicId)) {
                return [self::BLOCKING, self::INVALID_USER_PUBLIC_ID];
            }

            if (! $hasType) {
                return [self::BACKFILLABLE_USER, null];
            }

            if ($actorType === AuditActorAttribution::TYPE_USER
                && $actorReference === $resolvedUserPublicId) {
                return [self::CURRENT, null];
            }

            return [self::BLOCKING, self::USER_ATTRIBUTION_MISMATCH];
        }

        $serviceReference = is_string($action)
            ? $this->registeredServiceReference($action)
            : null;

        if (! $hasType) {
            return $this->legacyBackfillableServiceReference($action) === null
                ? [self::BLOCKING, self::ACTOR_REQUIRED_BUT_MISSING]
                : [self::BACKFILLABLE_SERVICE, null];
        }

        if ($serviceReference !== null
            && $actorType === AuditActorAttribution::TYPE_SERVICE
            && $actorReference === $serviceReference) {
            return [self::CURRENT, null];
        }

        return [self::BLOCKING, self::SERVICE_ATTRIBUTION_MISMATCH];
    }

    private function registeredServiceReference(string $action): ?string
    {
        try {
            $attribution = app(AuditActorAttribution::class)->forRecording($action, null);

            return $attribution['actor_reference'];
        } catch (InvalidAuditEvent) {
            return null;
        }
    }

    private function legacyBackfillableServiceReference(mixed $action): ?string
    {
        // Rebuild-admin reconciliation has always been service-only. Teaching
        // resets historically accepted an optional user, whose old SET NULL FK
        // could have erased identity evidence, so null reset actors must block.
        return $action === 'authorization.rebuild_admin.reconciled'
            ? AuditActorAttribution::REBUILD_ADMIN_SERVICE
            : null;
    }

    private function isUppercaseUlid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $value) === 1;
    }

    private function normalizeInteger(mixed $value): mixed
    {
        if (is_string($value) && ctype_digit($value)) {
            $normalized = filter_var($value, FILTER_VALIDATE_INT);

            return $normalized === false ? $value : $normalized;
        }

        return $value;
    }

    /** @param list<mixed> $values */
    private function frame(array $values): string
    {
        $framed = '';

        foreach ($values as $value) {
            if ($value === null) {
                $type = 'N';
                $bytes = '';
            } elseif (is_int($value)) {
                $type = 'I';
                $bytes = (string) $value;
            } elseif (is_bool($value)) {
                $type = 'B';
                $bytes = $value ? '1' : '0';
            } elseif (is_float($value)) {
                $type = 'F';
                $bytes = serialize($value);
            } elseif (is_string($value)) {
                $type = 'S';
                $bytes = $value;
            } else {
                $type = 'X';
                $bytes = get_debug_type($value);
            }

            $framed .= $type.pack('N', strlen($bytes)).$bytes;
        }

        return $framed;
    }
}
