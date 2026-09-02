<?php

namespace Tests\Unit\Audit;

use App\Support\Audit\AuditEventSchemaRegistry;
use App\Support\Audit\InvalidAuditEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WarehouseAuditContractTest extends TestCase
{
    private const ULID = '01J00000000000000000000000';

    private const RECEIPT_ULID = '01J00000000000000000000001';

    private const DIGEST = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @param array<string, mixed> $event */
    #[DataProvider('successEvents')]
    public function test_it_accepts_each_exact_warehouse_success_operation_state_and_binding(array $event): void
    {
        (new AuditEventSchemaRegistry)->assertAllows(...$event);

        $this->addToAssertionCount(1);
    }

    /** @param array<string, mixed> $event */
    #[DataProvider('successEvents')]
    public function test_it_rejects_a_mismatched_resource_for_each_warehouse_success_operation_family(array $event): void
    {
        $this->expectException(InvalidAuditEvent::class);

        $event['metadata']['result_public_id'] = self::RECEIPT_ULID;
        (new AuditEventSchemaRegistry)->assertAllows(...$event);
    }

    /** @param array<string, mixed> $event */
    #[DataProvider('deniedEvents')]
    public function test_it_accepts_attributed_warehouse_denials_before_or_after_resource_lookup(array $event): void
    {
        (new AuditEventSchemaRegistry)->assertAllows(...$event);

        $this->addToAssertionCount(1);
    }

    /** @param array<string, mixed> $event */
    #[DataProvider('invalidEvents')]
    public function test_it_rejects_malformed_or_unregistered_warehouse_audits(array $event): void
    {
        $this->expectException(InvalidAuditEvent::class);

        (new AuditEventSchemaRegistry)->assertAllows(...$event);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function successEvents(): iterable
    {
        foreach (self::contracts() as $operation => [$entity, $states]) {
            foreach ($states as $state) {
                yield strtolower($operation.'_'.$state) => [self::success($operation, $entity, $state)];
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function deniedEvents(): iterable
    {
        yield 'authorization denial before lookup' => [self::denied(
            'WAREHOUSE_PURCHASE_ORDER_CREATE',
            'role_not_permitted',
            null,
        )];
        yield 'integrity denial after lookup' => [self::denied(
            'WAREHOUSE_TRANSFER_REVIEW',
            'custody_integrity_failure',
            self::ULID,
        )];
        yield 'idempotency denial' => [self::denied(
            'WAREHOUSE_RECEIPT_RECORD',
            'idempotency_key_conflict',
            self::ULID,
        )];
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidEvents(): iterable
    {
        $supplier = self::success('WAREHOUSE_SUPPLIER_CREATE', 'SUPPLIER', 'ACTIVE');
        $purchaseOrder = self::success('WAREHOUSE_PURCHASE_ORDER_SUBMIT', 'PURCHASE_ORDER', 'SUBMITTED');
        $receipt = self::success('WAREHOUSE_RECEIPT_RECORD', 'RECEIPT', 'RECORDED');

        yield 'wrong resource tuple' => [self::replace($supplier, ['resourceType' => 'pharmacy_record'])];
        yield 'success requires actor' => [self::replace($supplier, ['actorPresent' => false])];
        yield 'success requires resource ULID' => [self::replace($supplier, ['resourceId' => null])];
        yield 'success reason must be null' => [self::replace($supplier, ['reason' => 'validation_failed'])];
        yield 'operation is closed' => [self::withMetadata($supplier, ['operation' => 'WAREHOUSE_UNKNOWN'])];
        yield 'operation state pair is closed' => [self::withMetadata($supplier, ['state' => 'RETIRED'])];
        yield 'entity binding is exact' => [self::withMetadata($supplier, ['entity_type' => 'PURCHASE_ORDER'])];
        yield 'version must be positive' => [self::withMetadata($supplier, ['version' => 0])];
        yield 'operation receipt must be ULID' => [self::withMetadata($supplier, ['operation_receipt_public_id' => 'receipt-1'])];
        yield 'result public id must be ULID' => [self::withMetadata($supplier, ['result_public_id' => 'supplier-1'])];
        yield 'result digest must be sha256' => [self::withMetadata($supplier, ['result_content_digest' => 'not-a-digest'])];
        yield 'supplier binding must match resource' => [self::withMetadata($supplier, ['supplier_public_id' => self::RECEIPT_ULID])];
        yield 'purchase order binding must match resource' => [self::withMetadata($purchaseOrder, ['purchase_order_public_id' => self::RECEIPT_ULID])];
        yield 'purchase order fingerprint must be sha256' => [self::withMetadata($purchaseOrder, ['purchase_order_fingerprint' => 'not-a-digest'])];
        yield 'stock total must be integer' => [self::withMetadata($receipt, ['after_total_quantity' => '12'])];
        yield 'stock total must be nonnegative' => [self::withMetadata($receipt, ['after_total_value' => -1])];
        yield 'success metadata cannot omit required binding' => [self::withoutMetadata($receipt, 'operation_receipt_public_id')];
        yield 'success metadata cannot add unregistered detail' => [self::withMetadata($receipt, ['note' => 'unregistered'])];

        $denied = self::denied('WAREHOUSE_TRANSFER_DISPATCH', 'role_not_permitted', null);
        yield 'denial requires actor' => [self::replace($denied, ['actorPresent' => false])];
        yield 'denial resource is nullable but must be ULID' => [self::replace($denied, ['resourceId' => 'transfer-1'])];
        yield 'denial reason is closed' => [self::replace($denied, ['reason' => 'invented_reason'])];
        yield 'denial operation is closed' => [self::withMetadata($denied, ['operation' => 'WAREHOUSE_UNKNOWN'])];
        yield 'denial metadata is operation only' => [self::withMetadata($denied, ['state' => 'DISPATCHED'])];
    }

    /** @return array<string, array{string, list<string>}> */
    private static function contracts(): array
    {
        return [
            'WAREHOUSE_SUPPLIER_CREATE' => ['SUPPLIER', ['ACTIVE']],
            'WAREHOUSE_SUPPLIER_REVISE' => ['SUPPLIER', ['ACTIVE']],
            'WAREHOUSE_SUPPLIER_RETIRE' => ['SUPPLIER', ['RETIRED']],
            'WAREHOUSE_PURCHASE_ORDER_CREATE' => ['PURCHASE_ORDER', ['DRAFT']],
            'WAREHOUSE_PURCHASE_ORDER_REVISE' => ['PURCHASE_ORDER', ['DRAFT']],
            'WAREHOUSE_PURCHASE_ORDER_SUBMIT' => ['PURCHASE_ORDER', ['SUBMITTED']],
            'WAREHOUSE_PURCHASE_ORDER_REVIEW' => ['PURCHASE_ORDER_DECISION', ['APPROVED', 'REJECTED']],
            'WAREHOUSE_RECEIPT_RECORD' => ['RECEIPT', ['RECORDED']],
            'WAREHOUSE_TRANSFER_DISPATCH' => ['TRANSFER', ['DISPATCHED']],
            'WAREHOUSE_TRANSFER_REVIEW' => ['TRANSFER_DECISION', ['ACCEPTED', 'REJECTED']],
            'WAREHOUSE_SUPPLIER_RETURN_REQUEST' => ['SUPPLIER_RETURN', ['REQUESTED']],
            'WAREHOUSE_SUPPLIER_RETURN_REVIEW' => ['SUPPLIER_RETURN_DECISION', ['APPROVED', 'REJECTED']],
            'WAREHOUSE_UNIT_RETURN_REQUEST' => ['UNIT_RETURN', ['DISPATCHED']],
            'WAREHOUSE_UNIT_RETURN_REVIEW' => ['UNIT_RETURN_DECISION', ['ACCEPTED', 'REJECTED']],
            'WAREHOUSE_CORRECTION_REQUEST' => ['CORRECTION_REQUEST', ['REQUESTED']],
            'WAREHOUSE_CORRECTION_REVIEW' => ['CORRECTION_DECISION', ['APPROVED', 'REJECTED']],
            'WAREHOUSE_CORRECTION_COMPENSATE' => ['CORRECTION_COMPENSATION', ['RECORDED']],
        ];
    }

    /** @return array<string, mixed> */
    private static function success(string $operation, string $entity, string $state): array
    {
        $metadata = [
            'operation' => $operation,
            'entity_type' => $entity,
            'state' => $state,
            'version' => 1,
            'result_public_id' => self::ULID,
            'operation_receipt_public_id' => self::RECEIPT_ULID,
            'result_content_digest' => self::DIGEST,
        ];

        if (in_array($operation, ['WAREHOUSE_SUPPLIER_CREATE', 'WAREHOUSE_SUPPLIER_REVISE', 'WAREHOUSE_SUPPLIER_RETIRE'], true)) {
            $metadata['supplier_public_id'] = self::ULID;
        }
        if (in_array($operation, [
            'WAREHOUSE_PURCHASE_ORDER_CREATE', 'WAREHOUSE_PURCHASE_ORDER_REVISE', 'WAREHOUSE_PURCHASE_ORDER_SUBMIT',
            'WAREHOUSE_PURCHASE_ORDER_REVIEW', 'WAREHOUSE_RECEIPT_RECORD',
        ], true)) {
            $metadata['supplier_public_id'] = self::ULID;
            $metadata['purchase_order_public_id'] = self::ULID;
            $metadata['purchase_order_fingerprint'] = self::DIGEST;
        }
        if (in_array($operation, ['WAREHOUSE_SUPPLIER_RETURN_REQUEST', 'WAREHOUSE_SUPPLIER_RETURN_REVIEW'], true)) {
            $metadata['supplier_public_id'] = self::ULID;
        }
        if (in_array($operation, [
            'WAREHOUSE_RECEIPT_RECORD', 'WAREHOUSE_TRANSFER_DISPATCH', 'WAREHOUSE_TRANSFER_REVIEW',
            'WAREHOUSE_SUPPLIER_RETURN_REVIEW', 'WAREHOUSE_UNIT_RETURN_REQUEST',
            'WAREHOUSE_UNIT_RETURN_REVIEW', 'WAREHOUSE_CORRECTION_COMPENSATE',
        ], true)) {
            $metadata += [
                'before_total_quantity' => 10,
                'after_total_quantity' => 10,
                'before_total_value' => 100_000,
                'after_total_value' => 100_000,
            ];
        }

        return [
            'action' => 'warehouse.workflow.mutate',
            'resourceType' => 'warehouse_record',
            'resourceId' => self::ULID,
            'actorPresent' => true,
            'outcome' => 'SUCCESS',
            'reason' => null,
            'metadata' => $metadata,
        ];
    }

    /** @return array<string, mixed> */
    private static function denied(string $operation, string $reason, ?string $resourceId): array
    {
        return [
            'action' => 'warehouse.workflow.mutate',
            'resourceType' => 'warehouse_record',
            'resourceId' => $resourceId,
            'actorPresent' => true,
            'outcome' => 'DENIED',
            'reason' => $reason,
            'metadata' => ['operation' => $operation],
        ];
    }

    /** @param array<string, mixed> $event @param array<string, mixed> $values @return array<string, mixed> */
    private static function replace(array $event, array $values): array
    {
        return array_replace($event, $values);
    }

    /** @param array<string, mixed> $event @param array<string, mixed> $values @return array<string, mixed> */
    private static function withMetadata(array $event, array $values): array
    {
        $event['metadata'] = array_replace($event['metadata'], $values);

        return $event;
    }

    /** @param array<string, mixed> $event @return array<string, mixed> */
    private static function withoutMetadata(array $event, string $key): array
    {
        unset($event['metadata'][$key]);

        return $event;
    }
}
