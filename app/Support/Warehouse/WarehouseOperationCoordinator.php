<?php

namespace App\Support\Warehouse;

use App\Models\User;
use App\Models\WarehouseOperationReceipt;
use App\Models\WarehouseSupplier;
use App\Models\WarehouseSupplierVersion;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class WarehouseOperationCoordinator
{
    private const AUDIT_ACTION = 'warehouse.workflow.mutate';

    private const AUDIT_RESOURCE = 'warehouse_record';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly WarehouseEvidenceFingerprint $fingerprints,
        private readonly WarehouseActorTransactionFence $actorFence,
    ) {}

    public function authorize(
        User $actor,
        string $operation,
        ?string $resource,
        callable $authorization,
    ): WarehouseOperationAuthorization {
        try {
            $claim = $authorization();
            if (! $claim instanceof WarehouseOperationAuthorization || ! $claim->matches($actor, $operation)) {
                throw new AuthorizationException;
            }

            return $claim;
        } catch (AuthorizationException $exception) {
            $this->denial($actor, $operation, $resource, 'role_not_permitted');

            throw $exception;
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $validation
     * @return T
     */
    public function validate(User $actor, string $operation, ?string $resource, callable $validation): mixed
    {
        try {
            return $validation();
        } catch (WarehouseDenied $exception) {
            $this->denial($actor, $operation, $resource, $exception->reason);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(?string, Connection): WarehouseOperationOutcome  $write
     */
    public function execute(
        User $actor,
        string $operation,
        ?string $resource,
        string $key,
        array $payload,
        string $resultType,
        WarehouseOperationAuthorization $authorization,
        callable $write,
        ?string $correlationId = null,
    ): WarehouseMutationResult {
        $key = mb_strtolower(trim($key));
        if (preg_match('/\A[a-z0-9][a-z0-9._:-]{7,254}\z/', $key) !== 1) {
            $denied = new WarehouseDenied('validation_failed', 'Kunci idempotensi tidak valid.');
            $this->denial($actor, $operation, $resource, $denied->reason);

            throw $denied;
        }

        $resolvedCorrelationId = $this->correlationId($correlationId);
        if ($resolvedCorrelationId === false) {
            $denied = new WarehouseDenied('validation_failed', 'ID korelasi permintaan tidak valid.');
            $this->denial($actor, $operation, $resource, $denied->reason, null);

            throw $denied;
        }

        $payloadDigest = WarehouseCanonicalJson::digest([
            'definition' => WarehouseEvidenceFingerprint::DEFINITION,
            'operation' => $operation,
            'payload' => $payload,
        ]);

        try {
            return $this->transaction($actor, $operation, $authorization, function (Connection $connection) use ($actor, $operation, $key, $payloadDigest, $resultType, $resolvedCorrelationId, $write): WarehouseMutationResult {
                if ($replay = $this->replay($connection, $actor, $operation, $key, $payloadDigest, $resultType)) {
                    return $replay;
                }

                $outcome = $write($resolvedCorrelationId, $connection);
                $this->assertOutcome($outcome, $resultType, $connection);
                $resultPublicId = (string) $outcome->record->getAttribute('public_id');
                $resultDigest = $this->fingerprints->operationResult(
                    $resultType,
                    $resultPublicId,
                    $outcome->version,
                    $outcome->state,
                    $outcome->contentDigest,
                );
                $receiptPublicId = (string) Str::ulid();
                $metadata = $this->successMetadata($operation, $resultType, $resultPublicId, $receiptPublicId, $outcome);
                $auditRequest = $this->auditRequest($resolvedCorrelationId);
                $audit = $this->audit->recordOnConnection(
                    $connection->getName(),
                    self::AUDIT_ACTION,
                    self::AUDIT_RESOURCE,
                    $resultPublicId,
                    $actor,
                    'SUCCESS',
                    null,
                    $metadata,
                    $auditRequest,
                    true,
                    [
                        'operation' => $operation,
                        'result_version' => $outcome->version,
                        'result_digest' => $resultDigest,
                        'control_total' => $outcome->controlTotal,
                    ],
                );
                if (! $audit instanceof AuditEvent) {
                    throw new WarehouseAuditUnavailable('Perubahan gudang dibatalkan karena audit wajib tidak tersedia.');
                }

                $receipt = new WarehouseOperationReceipt;
                $receipt->setConnection($connection->getName());
                $receipt->public_id = $receiptPublicId;
                $receipt->fill([
                    'actor_user_id' => $actor->id,
                    'audit_event_id' => $audit->id,
                    'audit_action_snapshot' => self::AUDIT_ACTION,
                    'audit_resource_type_snapshot' => self::AUDIT_RESOURCE,
                    'operation' => $operation,
                    'idempotency_key' => $key,
                    'payload_digest' => $payloadDigest,
                    'result_type' => $resultType,
                    'result_public_id' => $resultPublicId,
                    'result_version' => $outcome->version,
                    'result_state' => $outcome->state,
                    'result_digest' => $resultDigest,
                    'control_total' => $outcome->controlTotal,
                    'request_correlation_id' => $audit->request_correlation_id,
                    'completed_at' => now(),
                ]);
                $receipt->save();

                return new WarehouseMutationResult($outcome->record, false);
            });
        } catch (AuthorizationException $exception) {
            $this->denial($actor, $operation, $resource, 'role_not_permitted');
            throw $exception;
        } catch (ModelNotFoundException $exception) {
            $this->denial($actor, $operation, $resource, 'resource_not_found');
            throw $exception;
        } catch (WarehouseDenied $exception) {
            if (in_array($exception->reason, ['supplier_code_conflict', 'stale_version', 'stale_fingerprint'], true)) {
                try {
                    $replay = $this->replayAfterConflict(
                        $actor,
                        $operation,
                        $key,
                        $payloadDigest,
                        $resultType,
                        $authorization,
                    );
                } catch (WarehouseDenied $replayException) {
                    $this->denial($actor, $operation, $resource, $replayException->reason);
                    throw $replayException;
                }
                if ($replay instanceof WarehouseMutationResult) {
                    return $replay;
                }
            }

            $this->denial($actor, $operation, $resource, $exception->reason);
            throw $exception;
        } catch (UniqueConstraintViolationException) {
            try {
                $replay = $this->replayAfterConflict(
                    $actor,
                    $operation,
                    $key,
                    $payloadDigest,
                    $resultType,
                    $authorization,
                );
            } catch (WarehouseDenied $exception) {
                $this->denial($actor, $operation, $resource, $exception->reason);
                throw $exception;
            }
            if ($replay instanceof WarehouseMutationResult) {
                return $replay;
            }

            $denied = new WarehouseDenied('concurrent_state_conflict', 'Data gudang berubah secara bersamaan.');
            $this->denial($actor, $operation, $resource, $denied->reason);
            throw $denied;
        }
    }

    private function replayAfterConflict(
        User $actor,
        string $operation,
        string $key,
        string $payloadDigest,
        string $resultType,
        WarehouseOperationAuthorization $authorization,
    ): ?WarehouseMutationResult {
        return $this->transaction(
            $actor,
            $operation,
            $authorization,
            fn (Connection $connection): ?WarehouseMutationResult => $this->replay(
                $connection,
                $actor,
                $operation,
                $key,
                $payloadDigest,
                $resultType,
            ),
            governed: false,
        );
    }

    private function replay(
        Connection $connection,
        User $actor,
        string $operation,
        string $key,
        string $payloadDigest,
        string $resultType,
    ): ?WarehouseMutationResult {
        $receipt = WarehouseOperationReceipt::on($connection->getName())
            ->where('actor_user_id', $actor->id)
            ->where('operation', $operation)
            ->where('idempotency_key', $key)
            ->lockForUpdate()
            ->first();
        if (! $receipt instanceof WarehouseOperationReceipt) {
            return null;
        }
        if (! hash_equals((string) $receipt->payload_digest, $payloadDigest)
            || $receipt->result_type !== $resultType) {
            throw new WarehouseDenied('idempotency_key_conflict', 'Kunci idempotensi sudah digunakan untuk permintaan berbeda.');
        }

        if ($resultType !== WarehouseOperationReceipt::RESULT_SUPPLIER) {
            throw new WarehouseDenied('receipt_corrupt', 'Jenis hasil bukti operasi gudang tidak dikenal.');
        }

        $record = $this->retainedSupplier($connection, $receipt);
        $this->assertReceiptAudit($connection, $receipt, $operation, $record);

        return new WarehouseMutationResult($record, true);
    }

    private function retainedSupplier(Connection $connection, WarehouseOperationReceipt $receipt): WarehouseSupplier
    {
        $supplier = WarehouseSupplier::on($connection->getName())
            ->where('public_id', $receipt->result_public_id)
            ->first();
        if (! $supplier instanceof WarehouseSupplier
            || ! Str::isUlid((string) $supplier->public_id)
            || (int) $supplier->version < (int) $receipt->result_version) {
            throw new WarehouseDenied('receipt_corrupt', 'Bukti pemasok tidak lagi lengkap.');
        }

        $versions = WarehouseSupplierVersion::on($connection->getName())
            ->where('supplier_id', $supplier->id)
            ->where('version', '<=', (int) $supplier->version)
            ->orderBy('version')
            ->get();
        if ($versions->count() !== (int) $supplier->version) {
            throw new WarehouseDenied('receipt_corrupt', 'Rantai versi pemasok tidak lengkap.');
        }

        $previousDigest = null;
        $previousVersionId = null;
        $previousVersionNumber = null;
        $retired = false;
        foreach ($versions as $offset => $version) {
            if ((int) $version->version !== $offset + 1
                || ! Str::isUlid((string) $version->public_id)
                || $version->supplier_code_snapshot !== $supplier->supplier_code
                || ($version->previous_version_id === null ? null : (int) $version->previous_version_id) !== $previousVersionId
                || ($version->previous_version_number === null ? null : (int) $version->previous_version_number) !== $previousVersionNumber
                || $version->previous_content_digest !== $previousDigest
                || $retired
                || ! in_array($version->state, [WarehouseSupplier::ACTIVE, WarehouseSupplier::RETIRED], true)) {
                throw new WarehouseDenied('receipt_corrupt', 'Rantai versi pemasok tidak valid.');
            }
            $computed = $this->fingerprints->supplierVersion($version, (string) $supplier->public_id);
            if (! hash_equals((string) $version->content_digest, $computed)) {
                throw new WarehouseDenied('receipt_corrupt', 'Digest versi pemasok tidak valid.');
            }
            $previousDigest = $computed;
            $previousVersionId = (int) $version->id;
            $previousVersionNumber = (int) $version->version;
            $retired = $version->state === WarehouseSupplier::RETIRED;
        }

        $latest = $versions->last();
        $retained = $versions->firstWhere('version', (int) $receipt->result_version);
        if (! $latest instanceof WarehouseSupplierVersion
            || ! $retained instanceof WarehouseSupplierVersion
            || $latest->state !== $supplier->state
            || ! $this->supplierHeadMatchesVersion($supplier, $latest)
            || ! hash_equals((string) $latest->content_digest, (string) $supplier->current_content_digest)
            || (int) $retained->actor_user_id !== (int) $receipt->actor_user_id
            || $retained->request_correlation_id !== $receipt->request_correlation_id
            || $retained->state !== $receipt->result_state) {
            throw new WarehouseDenied('receipt_corrupt', 'Bukti hasil pemasok tidak cocok dengan versi tersimpan.');
        }

        $expectedState = match ($receipt->operation) {
            WarehouseSupplierService::OP_CREATE, WarehouseSupplierService::OP_REVISE => WarehouseSupplier::ACTIVE,
            WarehouseSupplierService::OP_RETIRE => WarehouseSupplier::RETIRED,
            default => null,
        };
        if ($expectedState === null
            || $retained->state !== $expectedState
            || ($receipt->operation === WarehouseSupplierService::OP_CREATE && (int) $retained->version !== 1)
            || ($receipt->operation !== WarehouseSupplierService::OP_CREATE && (int) $retained->version < 2)) {
            throw new WarehouseDenied('receipt_corrupt', 'Operasi dan versi pemasok tidak cocok.');
        }

        $resultDigest = $this->fingerprints->operationResult(
            WarehouseOperationReceipt::RESULT_SUPPLIER,
            (string) $supplier->public_id,
            (int) $retained->version,
            (string) $retained->state,
            (string) $retained->content_digest,
        );
        if (! hash_equals((string) $receipt->result_digest, $resultDigest)
            || (int) $receipt->control_total !== 0) {
            throw new WarehouseDenied('receipt_corrupt', 'Digest hasil operasi pemasok tidak valid.');
        }

        $view = clone $supplier;
        $attributes = $view->getAttributes();
        foreach (['display_name', 'synthetic_contact_name', 'synthetic_email', 'synthetic_phone', 'synthetic_reference'] as $field) {
            $attributes[$field] = $retained->getAttribute($field);
        }
        $attributes['supplier_code'] = $retained->supplier_code_snapshot;
        $attributes['state'] = $retained->state;
        $attributes['version'] = (int) $retained->version;
        $attributes['current_content_digest'] = $retained->content_digest;
        $attributes['updated_at'] = $retained->created_at;
        $view->setRawAttributes($attributes, true);

        return $view;
    }

    private function assertReceiptAudit(
        Connection $connection,
        WarehouseOperationReceipt $receipt,
        string $operation,
        WarehouseSupplier $record,
    ): void {
        if (! Str::isUlid((string) $receipt->public_id)
            || $receipt->audit_action_snapshot !== self::AUDIT_ACTION
            || $receipt->audit_resource_type_snapshot !== self::AUDIT_RESOURCE) {
            throw new WarehouseDenied('receipt_corrupt', 'Bukti operasi gudang tidak memiliki ikatan audit yang valid.');
        }

        $audit = AuditEvent::on($connection->getName())->whereKey($receipt->audit_event_id)->first();
        $metadata = $audit?->metadata;
        $expectedMetadata = [
            'operation' => $operation,
            'entity_type' => WarehouseOperationReceipt::RESULT_SUPPLIER,
            'state' => $receipt->result_state,
            'version' => (int) $receipt->result_version,
            'result_public_id' => $record->public_id,
            'operation_receipt_public_id' => $receipt->public_id,
            'result_content_digest' => $record->current_content_digest,
            'supplier_public_id' => $record->public_id,
        ];
        if (! $audit instanceof AuditEvent
            || (int) $audit->actor_user_id !== (int) $receipt->actor_user_id
            || $audit->action !== self::AUDIT_ACTION
            || $audit->resource_type !== self::AUDIT_RESOURCE
            || $audit->resource_id !== $receipt->result_public_id
            || $audit->outcome !== 'SUCCESS'
            || $audit->reason !== null
            || $audit->request_correlation_id !== $receipt->request_correlation_id
            || $audit->getAttribute('warehouse_operation_snapshot') !== $receipt->operation
            || (int) $audit->getAttribute('warehouse_result_version_snapshot') !== $receipt->result_version
            || $audit->getAttribute('warehouse_result_digest_snapshot') !== $receipt->result_digest
            || (int) $audit->getAttribute('warehouse_control_total_snapshot') !== $receipt->control_total
            || ! is_array($metadata)
            || ! WarehouseCanonicalJson::equivalent($metadata, $expectedMetadata)) {
            throw new WarehouseDenied('receipt_corrupt', 'Audit hasil operasi pemasok tidak cocok.');
        }
    }

    private function supplierHeadMatchesVersion(WarehouseSupplier $supplier, WarehouseSupplierVersion $version): bool
    {
        foreach (['display_name', 'synthetic_contact_name', 'synthetic_email', 'synthetic_phone', 'synthetic_reference'] as $field) {
            if ($supplier->getAttribute($field) !== $version->getAttribute($field)) {
                return false;
            }
        }

        return $supplier->supplier_code === $version->supplier_code_snapshot
            && $supplier->state === $version->state
            && (int) $supplier->version === (int) $version->version;
    }

    private function assertOutcome(WarehouseOperationOutcome $outcome, string $resultType, Connection $connection): void
    {
        $publicId = $outcome->record->getAttribute('public_id');
        $keys = array_keys($outcome->auditMetadata);
        sort($keys);
        if (! is_string($publicId)
            || ! Str::isUlid($publicId)
            || $outcome->record->getConnectionName() !== $connection->getName()
            || $outcome->version < 1
            || $outcome->state === ''
            || preg_match('/\A[a-f0-9]{64}\z/', $outcome->contentDigest) !== 1
            || $outcome->controlTotal < 0
            || ($resultType === WarehouseOperationReceipt::RESULT_SUPPLIER
                && ($keys !== ['supplier_public_id'] || $outcome->auditMetadata['supplier_public_id'] !== $publicId))) {
            throw new LogicException('Warehouse mutation returned an invalid operation outcome.');
        }
    }

    /** @return array<string, mixed> */
    private function successMetadata(
        string $operation,
        string $resultType,
        string $resultPublicId,
        string $receiptPublicId,
        WarehouseOperationOutcome $outcome,
    ): array {
        return [
            'operation' => $operation,
            'entity_type' => $resultType,
            'state' => $outcome->state,
            'version' => $outcome->version,
            'result_public_id' => $resultPublicId,
            'operation_receipt_public_id' => $receiptPublicId,
            'result_content_digest' => $outcome->contentDigest,
            ...$outcome->auditMetadata,
        ];
    }

    private function denial(
        User $actor,
        string $operation,
        ?string $resource,
        string $reason,
        string|false|null $correlationId = false,
    ): void {
        $connection = $this->connection();
        $resolved = $correlationId === false ? $this->ambientCorrelationId() : $correlationId;
        $request = $this->auditRequest(is_string($resolved) ? $resolved : null);
        $audit = $connection->transaction(fn (): ?AuditEvent => $this->audit->recordOnConnection(
            $connection->getName(),
            self::AUDIT_ACTION,
            self::AUDIT_RESOURCE,
            $this->safeResource($resource),
            $actor,
            'DENIED',
            $reason,
            ['operation' => $operation],
            $request,
            true,
        ), 3);
        if (! $audit instanceof AuditEvent) {
            throw new WarehouseAuditUnavailable('Audit penolakan operasi gudang tidak tersedia.');
        }
    }

    /**
     * @template T
     *
     * @param  callable(Connection): T  $callback
     * @return T
     */
    private function transaction(
        User $actor,
        string $operation,
        WarehouseOperationAuthorization $authorization,
        callable $callback,
        bool $governed = true,
    ): mixed {
        $connection = $this->connection();

        return $connection->transaction(function () use ($actor, $operation, $authorization, $callback, $connection, $governed): mixed {
            $this->actorFence->assertAuthorized($connection, $actor, $operation, $authorization);
            $result = ! $governed
                ? $callback($connection)
                : WarehouseMutationScope::run(
                    fn (?Connection $scoped = null): mixed => $callback($scoped ?? $connection),
                );
            $this->actorFence->assertAuthorized($connection, $actor, $operation, $authorization);

            return $result;
        }, 3);
    }

    private function connection(): Connection
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? DB::connection()
            : WarehouseMutationScope::assertWriterConnection();
    }

    private function correlationId(?string $explicit): string|false|null
    {
        $candidate = $explicit ?? $this->ambientCorrelationId();

        return $candidate === null || (is_string($candidate) && Str::isUlid($candidate))
            ? $candidate
            : false;
    }

    private function ambientCorrelationId(): mixed
    {
        return app()->bound('request')
            ? request()->attributes->get('request_id')
            : null;
    }

    private function auditRequest(?string $correlationId): Request
    {
        $request = app()->bound('request')
            ? clone request()
            : Request::create('/');
        $request->attributes->remove('request_id');
        if ($correlationId !== null) {
            $request->attributes->set('request_id', $correlationId);
        }

        return $request;
    }

    private function safeResource(?string $resource): ?string
    {
        return is_string($resource) && Str::isUlid($resource) ? $resource : null;
    }
}
