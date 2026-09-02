<?php

namespace App\Support\Audit;

use App\Models\ClinicalEntry;
use App\Models\Encounter;
use App\Models\EncounterCancellation;
use App\Models\InpatientClinicalDocument;
use App\Models\InpatientDischarge;
use App\Models\InpatientDischargeCodingSource;
use App\Models\InpatientDischargeSummary;
use App\Models\InpatientRmCoding;
use App\Models\InpatientRmCompletenessReview;
use App\Models\LabDiagnosticResult;
use App\Models\OutpatientClinicalDocument;
use App\Models\OutpatientClinicalDocumentAddendum;
use App\Models\OutpatientPostClosureAmendmentRequest;
use App\Models\OutpatientRmAmendmentReview;
use App\Models\OutpatientRmCompletenessReview;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Authorization\TeachingRoleAccessManager;
use App\Support\Clinical\LabTestCatalog;

final class AuditEventSchemaRegistry
{
    /** @var list<string> */
    private const PRINT_DOCUMENTS = ['bukti', 'antrian', 'sep', 'gelang', 'kartu', 'consent'];

    /** @var list<string> */
    private const COMPLETENESS_ITEMS = [
        'IDENTITY_LINKED',
        'NURSING_FINAL',
        'NURSING_PROVENANCE',
        'MEDICAL_FINAL',
        'MEDICAL_REQUIRED_FIELDS',
        'MEDICAL_PROVENANCE',
        'NO_ACTIVE_LAB_ORDERS',
        'NO_UNRESOLVED_LAB_SPECIMENS',
        'NO_UNACKNOWLEDGED_VERIFIED_LAB_RESULTS',
        'NO_ACTIVE_RADIOLOGY_ORDERS',
        'NO_UNACKNOWLEDGED_VERIFIED_RADIOLOGY_REPORTS',
        'NO_ACTIVE_MEDICATION_PRESCRIPTIONS',
    ];

    /** @var list<string> */
    private const EMERGENCY_OPERATIONS = [
        'EMERGENCY_TRIAGE_VOCABULARY_CREATE',
        'EMERGENCY_TRIAGE_VOCABULARY_REVISE',
        'EMERGENCY_TRIAGE_INITIAL_FINALIZE',
        'EMERGENCY_TRIAGE_REASSESS',
        'EMERGENCY_DOCUMENT_DRAFT_SAVE',
        'EMERGENCY_DOCUMENT_FINALIZE',
        'EMERGENCY_FOLLOW_UP_PROPOSE',
        'EMERGENCY_FOLLOW_UP_ACCEPT',
        'EMERGENCY_DISPOSITION_SIGN',
        'EMERGENCY_DISPOSITION_CORRECT_PRE_HANDOFF',
        'EMERGENCY_DISPOSITION_CORRECTION_INTENT_CREATE',
        'EMERGENCY_DISPOSITION_CORRECTION_INTENT_REVOKE',
        'EMERGENCY_INPATIENT_HANDOFF',
        'EMERGENCY_INPATIENT_HANDOFF_COMPENSATION',
    ];

    /** @var list<string> */
    private const EMERGENCY_DENIAL_REASONS = [
        'role_not_permitted', 'resource_not_found', 'validation_failed',
        'invalid_idempotency_key', 'idempotency_conflict', 'receipt_corrupt',
        'fixed_category_violation', 'vocabulary_retired', 'vocabulary_not_active',
        'stale_version', 'initial_triage_not_permitted', 'reassessment_not_permitted',
        'encounter_not_eligible', 'encounter_cancelled', 'observed_time_out_of_range',
        'late_entry_reason_required', 'vital_out_of_range', 'blood_pressure_pair_required',
        'unobtainable_field_mismatch', 'unobtainable_reason_required', 'flag_note_required',
        'initial_triage_required', 'document_final', 'signed_disposition_exists',
        'diagnostic_not_unresolved', 'assignee_not_eligible', 'prior_assignment_not_accepted',
        'actor_not_accountable', 'diagnostic_evidence_changed', 'acceptance_not_permitted',
        'assignment_already_accepted', 'assignment_superseded', 'stale_assignment_fingerprint',
        'disposition_not_permitted', 'diagnostic_follow_up_required',
        'executed_handoff_requires_intent', 'stale_or_ineligible_disposition',
        'handoff_already_compensated', 'pending_correction_intent_exists',
        'correction_intent_revoke_not_permitted', 'correction_intent_expired',
        'correction_intent_not_pending', 'stale_correction_intent', 'source_not_eligible',
        'handoff_already_completed', 'bed_inactive', 'active_patient_admission_exists',
        'bed_occupied', 'disposition_changed', 'disposition_invalid', 'patient_link_mismatch',
        'bed_changed', 'bed_unavailable', 'concurrent_change', 'handoff_binding_invalid',
        'correction_intent_stale', 'child_progressed', 'bed_reconciliation_invalid',
        'source_target_patient_mismatch', 'wrong_care_setting', 'evidence_fingerprint_invalid',
        'pharmacy_evidence_exists', 'active_pharmacy_prescriptions', 'active_pharmacy_preparation',
    ];

    /** @var array<string,list<string>> */
    private const PHARMACY_SUCCESS_PAIRS = [
        'PHARMACY_MEDICINE_CREATE' => ['ACTIVE'], 'PHARMACY_MEDICINE_REVISE' => ['ACTIVE', 'RETIRED'],
        'PHARMACY_DEPOT_CREATE' => ['ACTIVE'], 'PHARMACY_DEPOT_REVISE' => ['ACTIVE', 'RETIRED'],
        'PHARMACY_STOCK_OPEN' => ['ACTIVE'], 'PHARMACY_STOCK_CORRECT' => ['ACTIVE', 'QUARANTINED'],
        'PHARMACY_STOCK_STATE_CHANGE' => ['ACTIVE', 'QUARANTINED', 'RETIRED'],
        'PHARMACY_PRESCRIPTION_DRAFT_CREATE' => ['DRAFT'], 'PHARMACY_PRESCRIPTION_DRAFT_REVISE' => ['DRAFT'],
        'PHARMACY_PRESCRIPTION_ORDER' => ['ORDERED'], 'PHARMACY_PRESCRIPTION_CANCEL' => ['CANCELLED'],
        'PHARMACY_PRESCRIPTION_REPLACE' => ['DRAFT'], 'PHARMACY_VERIFY' => ['VERIFIED'],
        'PHARMACY_REFUSE' => ['REFUSED'], 'PHARMACY_PREPARE' => ['ACTIVE'],
        'PHARMACY_HANDOVER' => ['FULL', 'PARTIAL'], 'PHARMACY_UNFILLED_CLOSE' => ['UNFILLED_CLOSED'],
        'PHARMACY_RETURN' => ['RECORDED'],
    ];

    /** @var list<string> */
    private const PHARMACY_DENIAL_REASONS = [
        'role_not_permitted', 'resource_not_found', 'validation_failed', 'master_code_conflict', 'invalid_master_state', 'master_retired',
        'lot_code_conflict', 'negative_stock', 'invalid_lot_state', 'lot_not_empty', 'lot_retired', 'lot_quarantined',
        'stale_version', 'encounter_not_eligible', 'encounter_closed', 'encounter_cancelled', 'depot_not_eligible',
        'draft_not_editable', 'cancellation_not_permitted', 'replacement_not_permitted', 'manual_allergy_review_required',
        'verification_check_failed', 'verification_quantity_invalid', 'verification_items_incomplete', 'prescription_not_ordered',
        'prescription_not_verified', 'active_preparation_exists', 'insufficient_stock', 'nothing_to_prepare', 'prescription_not_prepared',
        'preparation_already_handed_over', 'evidence_fingerprint_invalid', 'stale_preparation', 'partial_reason_required',
        'unfilled_close_not_permitted', 'return_quantity_invalid', 'idempotency_key_conflict', 'receipt_corrupt', 'concurrent_state_conflict',
        'replacement_depot_change_not_permitted', 'prescription_handover_mismatch',
        'replacement_reason_required', 'pharmacy_location_changed', 'pharmacy_master_changed',
    ];

    /** @var array<string,array{entity:string,states:list<string>}> */
    private const WAREHOUSE_SUCCESS_CONTRACTS = [
        'WAREHOUSE_SUPPLIER_CREATE' => ['entity' => 'SUPPLIER', 'states' => ['ACTIVE']],
        'WAREHOUSE_SUPPLIER_REVISE' => ['entity' => 'SUPPLIER', 'states' => ['ACTIVE']],
        'WAREHOUSE_SUPPLIER_RETIRE' => ['entity' => 'SUPPLIER', 'states' => ['RETIRED']],
        'WAREHOUSE_PURCHASE_ORDER_CREATE' => ['entity' => 'PURCHASE_ORDER', 'states' => ['DRAFT']],
        'WAREHOUSE_PURCHASE_ORDER_REVISE' => ['entity' => 'PURCHASE_ORDER', 'states' => ['DRAFT']],
        'WAREHOUSE_PURCHASE_ORDER_SUBMIT' => ['entity' => 'PURCHASE_ORDER', 'states' => ['SUBMITTED']],
        'WAREHOUSE_PURCHASE_ORDER_REVIEW' => ['entity' => 'PURCHASE_ORDER_DECISION', 'states' => ['APPROVED', 'REJECTED']],
        'WAREHOUSE_RECEIPT_RECORD' => ['entity' => 'RECEIPT', 'states' => ['RECORDED']],
        'WAREHOUSE_TRANSFER_DISPATCH' => ['entity' => 'TRANSFER', 'states' => ['DISPATCHED']],
        'WAREHOUSE_TRANSFER_REVIEW' => ['entity' => 'TRANSFER_DECISION', 'states' => ['ACCEPTED', 'REJECTED']],
        'WAREHOUSE_SUPPLIER_RETURN_REQUEST' => ['entity' => 'SUPPLIER_RETURN', 'states' => ['REQUESTED']],
        'WAREHOUSE_SUPPLIER_RETURN_REVIEW' => ['entity' => 'SUPPLIER_RETURN_DECISION', 'states' => ['APPROVED', 'REJECTED']],
        'WAREHOUSE_UNIT_RETURN_REQUEST' => ['entity' => 'UNIT_RETURN', 'states' => ['DISPATCHED']],
        'WAREHOUSE_UNIT_RETURN_REVIEW' => ['entity' => 'UNIT_RETURN_DECISION', 'states' => ['ACCEPTED', 'REJECTED']],
        'WAREHOUSE_CORRECTION_REQUEST' => ['entity' => 'CORRECTION_REQUEST', 'states' => ['REQUESTED']],
        'WAREHOUSE_CORRECTION_REVIEW' => ['entity' => 'CORRECTION_DECISION', 'states' => ['APPROVED', 'REJECTED']],
        'WAREHOUSE_CORRECTION_COMPENSATE' => ['entity' => 'CORRECTION_COMPENSATION', 'states' => ['RECORDED']],
    ];

    /** @var list<string> */
    private const WAREHOUSE_DENIAL_REASONS = [
        'role_not_permitted', 'resource_not_found', 'validation_failed',
        'idempotency_key_conflict', 'receipt_corrupt', 'concurrent_state_conflict',
        'stale_version', 'stale_fingerprint', 'evidence_fingerprint_invalid',
        'master_code_conflict', 'supplier_code_conflict', 'supplier_reference_conflict', 'master_retired',
        'invalid_master_state', 'invalid_purchase_order_state', 'creator_mismatch',
        'same_actor_separation', 'purchase_order_not_approved', 'over_receipt',
        'receipt_variance_invalid', 'expiry_required', 'expired_stock',
        'insufficient_stock', 'negative_stock', 'invalid_lot_state',
        'custody_integrity_failure', 'transfer_not_dispatched', 'transfer_already_resolved',
        'destination_mismatch', 'rejection_reason_required', 'return_not_requested',
        'return_not_dispatched', 'return_already_resolved', 'return_quantity_invalid',
        'correction_quantity_invalid', 'correction_already_decided', 'correction_not_approved',
    ];

    /** @var array<string,list<string>> */
    private const FINANCE_SUCCESS_PAIRS = [
        'FINANCE_SOURCE_SYNC' => ['OPEN_NO_VERSION', 'NEW_SOURCE_PENDING', 'ISSUED_CURRENT'],
        'FINANCE_BILL_ISSUE' => ['ISSUED_CURRENT'],
        'FINANCE_CASH_SETTLEMENT' => ['ISSUED_CURRENT'],
        'FINANCE_SETTLEMENT_CORRECTION_REQUEST' => ['CORRECTION_REQUESTED'],
        'FINANCE_SETTLEMENT_CORRECTION_REVIEW' => ['REVIEW_REJECTED', 'REFUND_APPROVED'],
        'FINANCE_SETTLEMENT_REFUND_COMPLETE' => ['REFUND_COMPLETED'],
        'FINANCE_CASHIER_COLLECTION_OPEN' => ['OPEN'],
        'FINANCE_CASHIER_COLLECTION_CLOSE_REQUEST' => ['CLOSE_REQUESTED'],
        'FINANCE_CASHIER_COLLECTION_RECOUNT' => ['RECOUNT_SUBMITTED'],
        'FINANCE_CASHIER_COLLECTION_VERIFY' => ['CLOSE_VERIFIED'],
        'FINANCE_CASH_DEPOSIT_HANDOFF_CREATE' => ['DEPOSIT_HANDOFF_CREATED'],
    ];

    /** @var list<string> */
    private const FINANCE_DENIAL_REASONS = [
        'role_not_permitted', 'validation_failed', 'resource_not_found', 'no_valued_source',
        'non_synthetic_record', 'source_binding_invalid', 'source_integrity_failure',
        'source_reconciliation_failed', 'unsupported_source', 'over_reversal',
        'encounter_cancelled', 'stale_bill', 'source_set_unchanged',
        'unresolved_radiology_source', 'unresolved_laboratory_source', 'unresolved_accommodation_source',
        'accommodation_interval_open', 'accommodation_location_history_incomplete', 'tariff_not_effective',
        'bill_not_issued', 'new_source_pending', 'stale_bill_version', 'zero_amount',
        'zero_outstanding_amount', 'refund_required', 'prior_settlement_corrupt',
        'bill_version_already_settled',
        'settlement_integrity_failure', 'correction_integrity_failure',
        'settlement_not_owned', 'stale_settlement', 'correction_case_exists',
        'cash_handoff_exists', 'same_actor_separation', 'stale_correction_case',
        'review_already_completed', 'refund_not_approved', 'refund_not_completed',
        'idempotency_key_conflict', 'receipt_corrupt', 'concurrent_state_conflict',
        'active_batch_exists', 'cashier_collection_batch_required',
        'cashier_collection_batch_not_open', 'batch_not_open',
        'batch_not_recountable', 'batch_not_verifiable', 'batch_not_verified',
        'batch_variance_nonzero', 'handoff_already_exists', 'batch_integrity_failure',
        'pending_cash_correction', 'stale_collection_batch', 'batch_frozen',
    ];

    /** @var array<string,string> */
    private const FINANCE_TARIFF_OPERATION_ENTITIES = [
        'FINANCE_COST_COMPONENT_GROUP_CREATE' => 'COST_COMPONENT_GROUP',
        'FINANCE_COST_COMPONENT_GROUP_REVISE' => 'COST_COMPONENT_GROUP',
        'FINANCE_COST_COMPONENT_GROUP_RETIRE' => 'COST_COMPONENT_GROUP',
        'FINANCE_COST_COMPONENT_CREATE' => 'COST_COMPONENT',
        'FINANCE_COST_COMPONENT_REVISE' => 'COST_COMPONENT',
        'FINANCE_COST_COMPONENT_RETIRE' => 'COST_COMPONENT',
        'FINANCE_TARIFF_CATALOGUE_CREATE' => 'TARIFF_CATALOGUE',
        'FINANCE_TARIFF_CATALOGUE_REVISE' => 'TARIFF_CATALOGUE',
        'FINANCE_TARIFF_CATALOGUE_RETIRE' => 'TARIFF_CATALOGUE',
        'FINANCE_TARIFF_ITEM_CREATE' => 'TARIFF_ITEM',
        'FINANCE_TARIFF_ITEM_APPEND_VERSION' => 'TARIFF_ITEM',
        'FINANCE_TARIFF_ITEM_RETIRE' => 'TARIFF_ITEM',
        'FINANCE_RADIOLOGY_TARIFF_BINDING_CREATE' => 'RADIOLOGY_TARIFF_BINDING',
        'FINANCE_RADIOLOGY_TARIFF_BINDING_APPEND_VERSION' => 'RADIOLOGY_TARIFF_BINDING',
        'FINANCE_RADIOLOGY_TARIFF_BINDING_RETIRE' => 'RADIOLOGY_TARIFF_BINDING',
        'FINANCE_LABORATORY_TARIFF_BINDING_CREATE' => 'LABORATORY_TARIFF_BINDING',
        'FINANCE_LABORATORY_TARIFF_BINDING_APPEND_VERSION' => 'LABORATORY_TARIFF_BINDING',
        'FINANCE_LABORATORY_TARIFF_BINDING_RETIRE' => 'LABORATORY_TARIFF_BINDING',
        'FINANCE_ACCOMMODATION_TARIFF_BINDING_CREATE' => 'ACCOMMODATION_TARIFF_BINDING',
        'FINANCE_ACCOMMODATION_TARIFF_BINDING_APPEND_VERSION' => 'ACCOMMODATION_TARIFF_BINDING',
        'FINANCE_ACCOMMODATION_TARIFF_BINDING_RETIRE' => 'ACCOMMODATION_TARIFF_BINDING',
    ];

    /** @var list<string> */
    private const FINANCE_TARIFF_DENIAL_REASONS = [
        'role_not_permitted', 'resource_not_found', 'validation_failed',
        'stale_version', 'stale_digest', 'master_retired', 'active_components_remain',
        'dependent_tariffs_remain', 'upstream_inactive',
        'effective_date_not_after_latest', 'retroactive_effective_date',
        'binding_retired', 'master_version_mismatch', 'tariff_not_effective',
        'source_binding_invalid', 'source_integrity_failure',
        'idempotency_key_conflict', 'receipt_corrupt', 'concurrent_state_conflict',
    ];

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function assertAllows(
        string $action,
        string $resourceType,
        ?string $resourceId,
        bool $actorPresent,
        string $outcome,
        ?string $reason,
        array $metadata,
    ): void {
        $tuple = implode('|', [$action, $outcome, $resourceType]);

        switch ($tuple) {
            case 'finance.tariff.mutate|SUCCESS|finance_tariff_record':
                $this->assertActor($actorPresent);
                $this->assertPublicId($resourceId, 'resource_id');
                $this->assertNullReason($reason);
                $diagnosticBinding = in_array(
                    $metadata['entity_type'] ?? null,
                    ['RADIOLOGY_TARIFF_BINDING', 'LABORATORY_TARIFF_BINDING', 'ACCOMMODATION_TARIFF_BINDING'],
                    true,
                );
                $successKeys = [
                    'operation', 'entity_type', 'state', 'version', 'effective_from',
                    'replayed', 'future_activation',
                ];
                if ($diagnosticBinding) {
                    $successKeys[] = 'care_setting';
                }
                $this->assertExactKeys($metadata, $successKeys);
                if (! is_string($metadata['operation'])
                    || ! is_string($metadata['entity_type'])
                    || (self::FINANCE_TARIFF_OPERATION_ENTITIES[$metadata['operation']] ?? null) !== $metadata['entity_type']) {
                    throw new InvalidAuditEvent('Finance tariff operation/entity pair is not allowed.');
                }
                $expectedState = str_ends_with($metadata['operation'], '_RETIRE') ? 'RETIRED' : 'ACTIVE';
                $this->assertExactValue($metadata['state'], $expectedState, 'metadata.state');
                $this->assertPositiveInt($metadata['version'], 'metadata.version');
                $this->assertBool($metadata['replayed'], 'metadata.replayed');
                $this->assertBool($metadata['future_activation'], 'metadata.future_activation');
                if (in_array($metadata['entity_type'], ['TARIFF_ITEM', 'RADIOLOGY_TARIFF_BINDING', 'LABORATORY_TARIFF_BINDING', 'ACCOMMODATION_TARIFF_BINDING'], true)) {
                    $this->assertDate($metadata['effective_from'], 'metadata.effective_from');
                } elseif ($metadata['effective_from'] !== null || $metadata['future_activation'] !== false) {
                    throw new InvalidAuditEvent('Only effective-dated tariff records may carry an effective date or future activation marker.');
                }
                if ($diagnosticBinding && ! in_array($metadata['care_setting'], ['OUTPATIENT', 'EMERGENCY', 'INPATIENT'], true)) {
                    throw new InvalidAuditEvent('Diagnostic tariff binding care setting is not allowed.');
                }

                return;
            case 'finance.tariff.mutate|DENIED|finance_tariff_record':
                $this->assertActor($actorPresent);
                $this->assertNullablePublicId($resourceId, 'resource_id');
                $this->assertReasonIn($reason, self::FINANCE_TARIFF_DENIAL_REASONS);
                $denialKeys = ['operation', 'entity_type'];
                if (in_array(
                    $metadata['entity_type'] ?? null,
                    ['RADIOLOGY_TARIFF_BINDING', 'LABORATORY_TARIFF_BINDING', 'ACCOMMODATION_TARIFF_BINDING'],
                    true,
                ) && array_key_exists('care_setting', $metadata)) {
                    $denialKeys[] = 'care_setting';
                }
                $this->assertExactKeys($metadata, $denialKeys);
                if (! is_string($metadata['operation'])
                    || ! is_string($metadata['entity_type'])
                    || (self::FINANCE_TARIFF_OPERATION_ENTITIES[$metadata['operation']] ?? null) !== $metadata['entity_type']) {
                    throw new InvalidAuditEvent('Finance tariff denial operation/entity pair is not allowed.');
                }
                if (array_key_exists('care_setting', $metadata)
                    && ! in_array($metadata['care_setting'], ['OUTPATIENT', 'EMERGENCY', 'INPATIENT'], true)) {
                    throw new InvalidAuditEvent('Diagnostic tariff binding denial care setting is not allowed.');
                }

                return;
            case 'finance.workflow.mutate|SUCCESS|finance_record':
                $this->assertActor($actorPresent);
                $this->assertPublicId($resourceId, 'resource_id');
                $this->assertNullReason($reason);
                $collectionOperation = in_array($metadata['operation'] ?? null, [
                    'FINANCE_CASHIER_COLLECTION_OPEN', 'FINANCE_CASHIER_COLLECTION_CLOSE_REQUEST',
                    'FINANCE_CASHIER_COLLECTION_RECOUNT', 'FINANCE_CASHIER_COLLECTION_VERIFY',
                    'FINANCE_CASH_DEPOSIT_HANDOFF_CREATE',
                ], true);
                $successKeys = ($metadata['operation'] ?? null) === 'FINANCE_CASH_SETTLEMENT'
                    ? ['operation', 'state', 'version', 'settlement_public_id', 'settlement_content_digest', 'settlement_result_digest']
                    : ($collectionOperation
                        ? ['operation', 'state', 'version', 'batch_public_id', 'batch_content_digest', 'event_public_id', 'event_content_digest', 'handoff_public_id', 'handoff_content_digest', 'expected_net_amount', 'evidence_count']
                        : ['operation', 'state', 'version']);
                $this->assertExactKeys($metadata, $successKeys);
                if (! is_string($metadata['operation']) || ! is_string($metadata['state']) || ! in_array($metadata['state'], self::FINANCE_SUCCESS_PAIRS[$metadata['operation']] ?? [], true)) {
                    throw new InvalidAuditEvent('Finance success operation/state pair is not allowed.');
                }
                if ($metadata['operation'] === 'FINANCE_CASH_SETTLEMENT') {
                    $this->assertPublicIdValue($metadata['settlement_public_id'], 'metadata.settlement_public_id');
                    $this->assertSha256($metadata['settlement_content_digest'], 'metadata.settlement_content_digest');
                    $this->assertSha256($metadata['settlement_result_digest'], 'metadata.settlement_result_digest');
                }
                if ($collectionOperation) {
                    $this->assertPublicIdValue($metadata['batch_public_id'], 'metadata.batch_public_id');
                    $this->assertSha256($metadata['batch_content_digest'], 'metadata.batch_content_digest');
                    $this->assertNullablePublicId($metadata['event_public_id'], 'metadata.event_public_id');
                    $this->assertNullableSha256($metadata['event_content_digest'], 'metadata.event_content_digest');
                    $this->assertNullablePublicId($metadata['handoff_public_id'], 'metadata.handoff_public_id');
                    $this->assertNullableSha256($metadata['handoff_content_digest'], 'metadata.handoff_content_digest');
                    $this->assertNonNegativeInt($metadata['expected_net_amount'], 'metadata.expected_net_amount');
                    $this->assertNonNegativeInt($metadata['evidence_count'], 'metadata.evidence_count');
                }
                $this->assertNonNegativeInt($metadata['version'], 'metadata.version');
                if (! $collectionOperation && (($metadata['state'] === 'OPEN_NO_VERSION' && ($metadata['operation'] !== 'FINANCE_SOURCE_SYNC' || $metadata['version'] !== 0))
                    || ($metadata['state'] !== 'OPEN_NO_VERSION' && $metadata['version'] < 1))) {
                    throw new InvalidAuditEvent('Finance audit operation/state/version combination is not allowed.');
                }

                return;
            case 'finance.workflow.mutate|DENIED|finance_record':
                $this->assertActor($actorPresent);
                $this->assertNullablePublicId($resourceId, 'resource_id');
                $this->assertReasonIn($reason, self::FINANCE_DENIAL_REASONS);
                $this->assertExactKeys($metadata, ['operation']);
                if (! is_string($metadata['operation']) || ! array_key_exists($metadata['operation'], self::FINANCE_SUCCESS_PAIRS)) {
                    throw new InvalidAuditEvent('Finance denial operation is not allowed.');
                }

                return;
            case 'pharmacy.workflow.mutate|SUCCESS|pharmacy_record':
                $this->assertActor($actorPresent);
                $this->assertPublicId($resourceId, 'resource_id');
                $this->assertNullReason($reason);
                $this->assertExactKeys($metadata, ['operation', 'state', 'version']);
                if (! is_string($metadata['operation']) || ! is_string($metadata['state']) || ! in_array($metadata['state'], self::PHARMACY_SUCCESS_PAIRS[$metadata['operation']] ?? [], true)) {
                    throw new InvalidAuditEvent('Pharmacy success operation/state pair is not allowed.');
                }
                $this->assertPositiveInt($metadata['version'], 'metadata.version');

                return;
            case 'pharmacy.workflow.mutate|DENIED|pharmacy_record':
                $this->assertActor($actorPresent);
                $this->assertNullablePublicId($resourceId, 'resource_id');
                $this->assertReasonIn($reason, self::PHARMACY_DENIAL_REASONS);
                $this->assertExactKeys($metadata, ['operation']);
                if (! is_string($metadata['operation']) || ! array_key_exists($metadata['operation'], self::PHARMACY_SUCCESS_PAIRS)) {
                    throw new InvalidAuditEvent('Pharmacy denial operation is not allowed.');
                }

                return;
            case 'warehouse.workflow.mutate|SUCCESS|warehouse_record':
                $this->assertActor($actorPresent);
                $this->assertPublicId($resourceId, 'resource_id');
                $this->assertNullReason($reason);
                $this->assertWarehouseSuccess($resourceId, $metadata);

                return;
            case 'warehouse.workflow.mutate|DENIED|warehouse_record':
                $this->assertActor($actorPresent);
                $this->assertNullablePublicId($resourceId, 'resource_id');
                $this->assertReasonIn($reason, self::WAREHOUSE_DENIAL_REASONS);
                $this->assertExactKeys($metadata, ['operation']);
                if (! is_string($metadata['operation']) || ! array_key_exists($metadata['operation'], self::WAREHOUSE_SUCCESS_CONTRACTS)) {
                    throw new InvalidAuditEvent('Warehouse denial operation is not allowed.');
                }

                return;
            case 'emergency.workflow.mutate|SUCCESS|emergency_record':
                $this->assertActor($actorPresent);
                $this->assertNullablePublicId($resourceId, 'resource_id');
                $this->assertNullReason($reason);
                $this->assertEmergencyOperation($metadata);

                return;
            case 'emergency.workflow.mutate|DENIED|emergency_record':
                $this->assertActor($actorPresent);
                $this->assertNullablePublicId($resourceId, 'resource_id');
                $this->assertReasonIn($reason, self::EMERGENCY_DENIAL_REASONS);
                $this->assertEmergencyOperation($metadata);

                return;
            case 'laboratory.workflow.mutate|SUCCESS|laboratory_record':
                $this->assertActor($actorPresent);
                $this->assertPublicId($resourceId, 'resource_id');
                $this->assertNullReason($reason);
                $this->assertExactKeys($metadata, ['operation', 'state', 'version']);
                $successPairs = [
                    'LABORATORY_MASTER_CREATE' => ['ACTIVE'],
                    'LABORATORY_MASTER_REVISE' => ['ACTIVE', 'RETIRED'],
                    'LABORATORY_ORDER_CREATE' => ['ORDERED'],
                    'LABORATORY_ORDER_CANCEL' => ['CANCELLED'],
                    'LABORATORY_SPECIMEN_COLLECT' => ['COLLECTED'],
                    'LABORATORY_SPECIMEN_RECEIVE' => ['RECEIVED'],
                    'LABORATORY_SPECIMEN_ACCEPT' => ['ACCEPTED'],
                    'LABORATORY_SPECIMEN_REJECT' => ['REJECTED'],
                    'LABORATORY_RESULT_DRAFT_SAVE' => ['DRAFT'],
                    'LABORATORY_RESULT_VERIFY' => ['VERIFIED'],
                    'LABORATORY_RESULT_AMEND_VERIFIED' => ['AMENDED_VERIFIED'],
                    'LABORATORY_RESULT_ACKNOWLEDGE' => ['ACKNOWLEDGED'],
                ];
                if (! is_string($metadata['operation'])
                    || ! is_string($metadata['state'])
                    || ! in_array($metadata['state'], $successPairs[$metadata['operation']] ?? [], true)) {
                    throw new InvalidAuditEvent('Laboratory success operation/state pair is not allowed.');
                }
                $this->assertPositiveInt($metadata['version'], 'metadata.version');

                return;
            case 'laboratory.workflow.mutate|DENIED|laboratory_record':
                $this->assertActor($actorPresent);
                if ($resourceId !== null) {
                    $this->assertPublicId($resourceId, 'resource_id');
                }
                $this->assertReasonIn($reason, [
                    'validation_failed', 'master_code_conflict', 'master_not_active', 'invalid_master_state',
                    'master_retired', 'encounter_not_eligible', 'encounter_cancelled', 'encounter_closed',
                    'cancellation_not_permitted', 'stale_version', 'order_not_ordered', 'specimen_evidence_exists',
                    'specimen_not_collectable', 'specimen_not_collected', 'specimen_not_received',
                    'specimen_already_resolved', 'accepted_specimen_exists', 'accepted_specimen_required',
                    'result_already_verified', 'result_author_mismatch', 'result_not_verified',
                    'amendment_base_stale', 'verification_not_permitted', 'critical_communication_required',
                    'critical_recipient_mismatch',
                    'acknowledgement_not_permitted', 'already_acknowledged', 'idempotency_key_conflict',
                    'evidence_fingerprint_invalid', 'receipt_corrupt', 'concurrent_state_conflict',
                    'role_not_permitted', 'resource_not_found', 'initial_triage_required',
                ]);
                $this->assertExactKeys($metadata, ['operation']);
                $operations = [
                    'LABORATORY_MASTER_CREATE', 'LABORATORY_MASTER_REVISE', 'LABORATORY_ORDER_CREATE',
                    'LABORATORY_ORDER_CANCEL', 'LABORATORY_SPECIMEN_COLLECT', 'LABORATORY_SPECIMEN_RECEIVE',
                    'LABORATORY_SPECIMEN_ACCEPT', 'LABORATORY_SPECIMEN_REJECT', 'LABORATORY_RESULT_DRAFT_SAVE',
                    'LABORATORY_RESULT_VERIFY', 'LABORATORY_RESULT_AMEND_VERIFIED', 'LABORATORY_RESULT_ACKNOWLEDGE',
                ];
                if (! is_string($metadata['operation']) || ! in_array($metadata['operation'], $operations, true)) {
                    throw new InvalidAuditEvent('Laboratory denial operation is not allowed.');
                }

                return;
            case 'radiology.workflow.mutate|SUCCESS|radiology_record':
                $this->assertActor($actorPresent);
                $this->assertPublicId($resourceId, 'resource_id');
                $this->assertNullReason($reason);
                $this->assertExactKeys($metadata, ['operation', 'state', 'version']);
                $successPairs = [
                    'RADIOLOGY_MASTER_CREATE' => ['ACTIVE'],
                    'RADIOLOGY_MASTER_REVISE' => ['ACTIVE', 'RETIRED'],
                    'RADIOLOGY_ORDER_CREATE' => ['ORDERED'],
                    'RADIOLOGY_ORDER_CANCEL' => ['CANCELLED'],
                    'RADIOLOGY_ORDER_PERFORM' => ['PERFORMED'],
                    'RADIOLOGY_REPORT_DRAFT_SAVE' => ['DRAFT'],
                    'RADIOLOGY_REPORT_VERIFY' => ['VERIFIED'],
                    'RADIOLOGY_REPORT_AMEND_VERIFIED' => ['AMENDED_VERIFIED'],
                    'RADIOLOGY_REPORT_ACKNOWLEDGE' => ['ACKNOWLEDGED'],
                ];
                if (! is_string($metadata['operation'])
                    || ! is_string($metadata['state'])
                    || ! in_array($metadata['state'], $successPairs[$metadata['operation']] ?? [], true)) {
                    throw new InvalidAuditEvent('Radiology success operation/state pair is not allowed.');
                }
                $this->assertPositiveInt($metadata['version'], 'metadata.version');

                return;
            case 'radiology.workflow.mutate|DENIED|radiology_record':
                $this->assertActor($actorPresent);
                if ($resourceId !== null) {
                    $this->assertPublicId($resourceId, 'resource_id');
                }
                $this->assertReasonIn($reason, [
                    'validation_failed', 'master_code_conflict', 'master_not_active', 'invalid_master_state', 'master_retired',
                    'encounter_not_eligible', 'encounter_cancelled', 'encounter_closed', 'cancellation_not_permitted',
                    'stale_version', 'order_not_ordered', 'already_performed', 'performance_required', 'report_already_verified',
                    'report_author_mismatch', 'report_not_verified', 'amendment_base_stale', 'verification_not_permitted',
                    'acknowledgement_not_permitted', 'already_acknowledged', 'idempotency_key_conflict',
                    'evidence_fingerprint_invalid', 'receipt_corrupt', 'concurrent_state_conflict',
                    'role_not_permitted', 'resource_not_found', 'initial_triage_required',
                ]);
                $this->assertExactKeys($metadata, ['operation']);
                $operations = [
                    'RADIOLOGY_MASTER_CREATE', 'RADIOLOGY_MASTER_REVISE', 'RADIOLOGY_ORDER_CREATE',
                    'RADIOLOGY_ORDER_CANCEL', 'RADIOLOGY_ORDER_PERFORM', 'RADIOLOGY_REPORT_DRAFT_SAVE',
                    'RADIOLOGY_REPORT_VERIFY', 'RADIOLOGY_REPORT_AMEND_VERIFIED', 'RADIOLOGY_REPORT_ACKNOWLEDGE',
                ];
                if (! is_string($metadata['operation']) || ! in_array($metadata['operation'], $operations, true)) {
                    throw new InvalidAuditEvent('Radiology denial operation is not allowed.');
                }

                return;
            case 'clinical.inpatient.summary-addendum.request.submit|SUCCESS|inpatient_summary_correction_request':
            case 'clinical.inpatient.summary-addendum.request.decide|SUCCESS|inpatient_summary_correction_request':
            case 'clinical.inpatient.summary-addendum.draft.save|SUCCESS|inpatient_summary_correction_request':
            case 'clinical.inpatient.summary-addendum.finalize|SUCCESS|inpatient_summary_correction_request':
            case 'rmik.inpatient.summary-addendum.review.save|SUCCESS|inpatient_summary_correction_request':
            case 'rmik.inpatient.summary-addendum.signoff|SUCCESS|inpatient_summary_correction_request':
                $this->assertActor($actorPresent);
                $this->assertPublicId($resourceId, 'resource_id');
                $this->assertNullReason($reason);
                $this->assertExactKeys($metadata, ['encounter_public_id', 'request_state', 'request_version']);

                return;
            case 'clinical.inpatient.summary-addendum.request.submit|DENIED|encounter':
                $this->assertInpatientSummaryAddendumDenial($resourceId, $actorPresent, $reason, $metadata, false);

                return;
            case 'clinical.inpatient.summary-addendum.request.decide|DENIED|inpatient_summary_correction_request':
            case 'clinical.inpatient.summary-addendum.draft.save|DENIED|inpatient_summary_correction_request':
            case 'clinical.inpatient.summary-addendum.finalize|DENIED|inpatient_summary_correction_request':
            case 'rmik.inpatient.summary-addendum.review.save|DENIED|inpatient_summary_correction_request':
            case 'rmik.inpatient.summary-addendum.signoff|DENIED|inpatient_summary_correction_request':
                $this->assertInpatientSummaryAddendumDenial($resourceId, $actorPresent, $reason, $metadata, true);

                return;
            case 'authorization.rebuild_admin.reconciled|SUCCESS|user':
                $this->assertRebuildAdminReconciled($resourceId, $actorPresent, $reason, $metadata);

                return;
            case 'authorization.teaching_role.activated|SUCCESS|user':
                $this->assertTeachingRoleLifecycle($resourceId, $actorPresent, $reason, $metadata, activated: true);

                return;
            case 'authorization.teaching_role.revoked|SUCCESS|user':
                $this->assertTeachingRoleLifecycle($resourceId, $actorPresent, $reason, $metadata, activated: false);

                return;
            case 'authorization.teaching_role.compensated|SUCCESS|user':
                $this->assertTeachingRoleLifecycle($resourceId, $actorPresent, $reason, $metadata, activated: false);

                return;
            case 'teaching.reset.started|SUCCESS|simulation':
                $this->assertTeachingReset($resourceId, $reason, $metadata, false);

                return;
            case 'teaching.reset.completed|SUCCESS|simulation':
                $this->assertTeachingReset($resourceId, $reason, $metadata, true);

                return;
            case 'patient.register|SUCCESS|encounter':
                $this->assertActor($actorPresent);
                $this->assertPublicId($resourceId, 'resource_id');
                $this->assertNullReason($reason);
                $this->assertPatientRegistration($metadata);

                return;
            case 'clinical.note.write|SUCCESS|encounter':
                $this->assertActor($actorPresent);
                $this->assertPublicId($resourceId, 'resource_id');
                $this->assertNullReason($reason);
                $this->assertClinicalNote($metadata);

                return;
            case 'clinical.note.write|DENIED|encounter':
                $this->assertActor($actorPresent);
                $this->assertPublicId($resourceId, 'resource_id');
                $this->assertExactReason($reason, 'encounter_cancelled');
                $this->assertClinicalNoteDenial($metadata);

                return;
            case 'encounter.cancel|SUCCESS|encounter':
                $this->assertActor($actorPresent);
                $this->assertPublicId($resourceId, 'resource_id');
                $this->assertNullReason($reason);
                $this->assertEncounterCancellation($metadata);

                return;
            case 'encounter.cancel|DENIED|encounter':
                $this->assertActor($actorPresent);
                $this->assertPublicId($resourceId, 'resource_id');
                $this->assertEncounterCancellationDenial($reason, $metadata);

                return;
            case 'encounter.print|SUCCESS|encounter':
                $this->assertActor($actorPresent);
                $this->assertPublicId($resourceId, 'resource_id');
                $this->assertNullReason($reason);
                $this->assertEncounterPrint($metadata);

                return;
            case 'encounter.print|DENIED|encounter':
                $this->assertActor($actorPresent);
                $this->assertPublicId($resourceId, 'resource_id');
                $this->assertExactReason($reason, 'encounter_cancelled');
                $this->assertEncounterPrintDenial($metadata);

                return;
            case 'authorization.denied|DENIED|http_route':
                $this->assertActor($actorPresent);
                $this->assertRouteName($resourceId);
                $this->assertExactReason($reason, 'authorization_check_failed');
                $this->assertAuthorizationDenial($metadata);

                return;
            case 'clinical.lab.order.create|SUCCESS|lab_service_request':
                $this->assertActor($actorPresent);
                $this->assertPublicId($resourceId, 'resource_id');
                $this->assertNullReason($reason);
                $this->assertLabOrderSuccess($metadata);

                return;
            case 'clinical.lab.order.create|DENIED|encounter':
                $this->assertActor($actorPresent);
                $this->assertPublicId($resourceId, 'resource_id');
                $this->assertReasonIn($reason, ['encounter_closed', 'encounter_cancelled']);
                $this->assertLabOrderDenial($metadata);

                return;
            case 'clinical.lab.result.write|SUCCESS|lab_service_request':
                $this->assertActor($actorPresent);
                $this->assertPublicId($resourceId, 'resource_id');
                $this->assertNullReason($reason);
                $this->assertLabResultSuccess($metadata);

                return;
            case 'clinical.lab.result.write|DENIED|lab_service_request':
                $this->assertActor($actorPresent);
                $this->assertPublicId($resourceId, 'resource_id');
                $this->assertReasonIn($reason, ['encounter_closed', 'encounter_cancelled', 'result_already_final', 'order_not_active']);
                $this->assertLabResultDenial($metadata);

                return;
            case 'clinical.outpatient.amendment.request.submit|SUCCESS|outpatient_post_closure_amendment_request':
                $this->assertAmendmentRequestSubmitSuccess($resourceId, $actorPresent, $reason, $metadata);

                return;
            case 'clinical.outpatient.amendment.request.submit|DENIED|encounter':
                $this->assertAmendmentRequestDenial($resourceId, $actorPresent, $reason, $metadata, decision: false);

                return;
            case 'clinical.outpatient.amendment.request.decide|SUCCESS|outpatient_post_closure_amendment_request':
                $this->assertAmendmentRequestDecisionSuccess($resourceId, $actorPresent, $reason, $metadata);

                return;
            case 'clinical.outpatient.amendment.request.decide|DENIED|outpatient_post_closure_amendment_request':
                $this->assertAmendmentRequestDenial($resourceId, $actorPresent, $reason, $metadata, decision: true);

                return;
            case 'clinical.outpatient.amendment.addendum.write|SUCCESS|outpatient_clinical_document_addendum':
                $this->assertAmendmentAddendumSuccess($resourceId, $actorPresent, $reason, $metadata, finalize: false);

                return;
            case 'clinical.outpatient.amendment.addendum.finalize|SUCCESS|outpatient_clinical_document_addendum':
                $this->assertAmendmentAddendumSuccess($resourceId, $actorPresent, $reason, $metadata, finalize: true);

                return;
            case 'clinical.outpatient.amendment.addendum.write|DENIED|outpatient_post_closure_amendment_request':
            case 'clinical.outpatient.amendment.addendum.finalize|DENIED|outpatient_post_closure_amendment_request':
                $this->assertAmendmentAddendumDenial($resourceId, $actorPresent, $reason, $metadata);

                return;
            case 'rmik.outpatient.amendment.review.save|SUCCESS|outpatient_rm_amendment_review':
                $this->assertRmAmendmentSuccess($resourceId, $actorPresent, $reason, $metadata, signoff: false);

                return;
            case 'rmik.outpatient.amendment.signoff|SUCCESS|outpatient_rm_amendment_review':
                $this->assertRmAmendmentSuccess($resourceId, $actorPresent, $reason, $metadata, signoff: true);

                return;
            case 'rmik.outpatient.amendment.review.save|DENIED|outpatient_post_closure_amendment_request':
            case 'rmik.outpatient.amendment.signoff|DENIED|outpatient_post_closure_amendment_request':
                $this->assertRmAmendmentDenial($resourceId, $actorPresent, $reason, $metadata);

                return;
            case 'master.inpatient.ward.create|SUCCESS|inpatient_ward':
            case 'master.inpatient.ward.update|SUCCESS|inpatient_ward':
            case 'master.inpatient.ward.retire|SUCCESS|inpatient_ward':
            case 'master.inpatient.bed.create|SUCCESS|inpatient_bed':
            case 'master.inpatient.bed.update|SUCCESS|inpatient_bed':
            case 'master.inpatient.bed.retire|SUCCESS|inpatient_bed':
                $this->assertInpatientMasterMutation($action, $resourceId, $actorPresent, $reason, $metadata);

                return;
            case 'inpatient.bed.transfer|SUCCESS|encounter':
                $this->assertInpatientBedTransferSuccess($resourceId, $actorPresent, $reason, $metadata);

                return;
            case 'inpatient.bed.transfer|DENIED|encounter':
                $this->assertInpatientBedTransferDenial($resourceId, $actorPresent, $reason, $metadata);

                return;
            case 'clinical.inpatient.discharge.execute|SUCCESS|inpatient_discharge':
                $this->assertInpatientDischargeExecution($resourceId, $actorPresent, $reason, $metadata);

                return;
            case 'clinical.inpatient.discharge.execute|DENIED|encounter':
                $this->assertInpatientDischargeDenial($resourceId, $actorPresent, $reason, $metadata);

                return;
        }

        if ($this->isDocumentationAction($action)) {
            $this->assertDocumentation($action, $resourceType, $resourceId, $actorPresent, $outcome, $reason, $metadata);

            return;
        }

        if ($this->isInpatientDocumentationAction($action)) {
            $this->assertInpatientDocumentation($resourceType, $resourceId, $actorPresent, $outcome, $reason, $metadata);

            return;
        }

        if ($this->isInpatientDischargeSummaryAction($action)) {
            $this->assertInpatientDischargeSummary($resourceType, $resourceId, $actorPresent, $outcome, $reason, $metadata);

            return;
        }

        if ($this->isInpatientDischargeCodingSourceAction($action)) {
            $this->assertInpatientDischargeCodingSource($resourceType, $resourceId, $actorPresent, $outcome, $reason, $metadata);

            return;
        }

        if (in_array($action, [
            'rmik.inpatient.coding.draft.save',
            'rmik.inpatient.completeness.review.save',
            'rmik.inpatient.episode.signoff',
        ], true)) {
            $this->assertInpatientRm($action, $resourceType, $resourceId, $actorPresent, $outcome, $reason, $metadata);

            return;
        }

        if (in_array($action, ['rmik.completeness.review.save', 'rmik.completeness.signoff'], true)) {
            $this->assertCompleteness($action, $resourceType, $resourceId, $actorPresent, $outcome, $reason, $metadata);

            return;
        }

        throw new InvalidAuditEvent('Audit event tuple is not registered.');
    }

    /** @param array<string, mixed> $metadata */
    private function assertInpatientDischargeExecution(?string $resourceId, bool $actorPresent, ?string $reason, array $metadata): void
    {
        $this->assertActor($actorPresent);
        $this->assertPublicId($resourceId, 'resource_id');
        $this->assertNullReason($reason);
        $this->assertExactKeys($metadata, [
            'encounter_public_id', 'disposition_code', 'discharge_summary_public_id',
            'discharge_summary_version', 'discharge_summary_version_public_id',
            'discharge_summary_content_digest', 'discharge_summary_provenance_digest',
            'discharge_coding_source_public_id', 'discharge_coding_source_version',
            'discharge_coding_source_version_public_id', 'discharge_coding_source_content_digest',
            'discharge_coding_source_provenance_digest',
            'location_sequence', 'source_ward_public_id',
            'source_ward_code', 'source_bed_public_id', 'source_bed_code',
            'encounter_status_before', 'encounter_status_after', 'inpatient_bed_released',
            'payload_digest',
        ]);
        foreach (['encounter_public_id', 'discharge_summary_public_id', 'discharge_summary_version_public_id', 'discharge_coding_source_public_id', 'discharge_coding_source_version_public_id', 'source_ward_public_id', 'source_bed_public_id'] as $key) {
            $this->assertPublicIdValue($metadata[$key], 'metadata.'.$key);
        }
        $this->assertExactValue($metadata['disposition_code'], InpatientDischarge::DISPOSITION_ROUTINE_HOME, 'metadata.disposition_code');
        $this->assertPositiveInt($metadata['discharge_summary_version'], 'metadata.discharge_summary_version');
        $this->assertPositiveInt($metadata['discharge_coding_source_version'], 'metadata.discharge_coding_source_version');
        $this->assertSha256($metadata['discharge_summary_content_digest'], 'metadata.discharge_summary_content_digest');
        $this->assertSha256($metadata['discharge_summary_provenance_digest'], 'metadata.discharge_summary_provenance_digest');
        $this->assertSha256($metadata['discharge_coding_source_content_digest'], 'metadata.discharge_coding_source_content_digest');
        $this->assertSha256($metadata['discharge_coding_source_provenance_digest'], 'metadata.discharge_coding_source_provenance_digest');
        $this->assertNonNegativeInt($metadata['location_sequence'], 'metadata.location_sequence');
        $this->assertText($metadata['source_ward_code'], 'metadata.source_ward_code', 1, 64);
        $this->assertText($metadata['source_bed_code'], 'metadata.source_bed_code', 1, 64);
        $this->assertEnum($metadata['encounter_status_before'], Encounter::BED_OCCUPYING_STATUSES, 'metadata.encounter_status_before');
        $this->assertExactValue($metadata['encounter_status_after'], Encounter::STATUS_READY_FOR_RM, 'metadata.encounter_status_after');
        $this->assertExactValue($metadata['inpatient_bed_released'], true, 'metadata.inpatient_bed_released');
        $this->assertSha256($metadata['payload_digest'], 'metadata.payload_digest');
    }

    /** @param array<string, mixed> $metadata */
    private function assertInpatientDischargeDenial(?string $resourceId, bool $actorPresent, ?string $reason, array $metadata): void
    {
        $this->assertActor($actorPresent);
        $this->assertPublicId($resourceId, 'resource_id');
        $this->assertReasonIn($reason, [
            'validation_failed', 'encounter_missing', 'not_inpatient', 'encounter_not_active',
            'encounter_cancelled', 'synthetic_only', 'placement_missing', 'source_bed_changed',
            'placement_inactive', 'summary_missing', 'summary_not_final',
            'physician_assignment_mismatch', 'stale_summary', 'summary_binding_invalid',
            'stale_location', 'duplicate_current_claim', 'already_discharged',
            'idempotency_key_conflict', 'receipt_binding_invalid', 'concurrent_change',
            'unauthorized_actor', 'persistence_unavailable',
            'summary_placement_stale',
            'discharge_coding_source_not_final', 'discharge_coding_source_binding_invalid',
            'discharge_coding_source_placement_stale',
            'active_pharmacy_prescriptions',
        ]);
        foreach (array_keys($metadata) as $key) {
            if (! in_array($key, ['current_summary_version', 'current_location_sequence'], true)) {
                throw new InvalidAuditEvent('Inpatient discharge denial metadata contains an unregistered key.');
            }
            $this->assertNonNegativeInt($metadata[$key], 'metadata.'.$key);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function assertInpatientBedTransferSuccess(?string $resourceId, bool $actorPresent, ?string $reason, array $metadata): void
    {
        $this->assertActor($actorPresent);
        $this->assertPublicId($resourceId, 'resource_id');
        $this->assertNullReason($reason);
        $this->assertExactKeys($metadata, [
            'event_public_id', 'sequence',
            'from_ward_public_id', 'from_ward_code', 'from_bed_public_id', 'from_bed_code',
            'to_ward_public_id', 'to_ward_code', 'to_bed_public_id', 'to_bed_code',
            'expected_sequence', 'payload_digest', 'request_correlation_id',
        ]);
        foreach (['event_public_id', 'from_ward_public_id', 'from_bed_public_id', 'to_ward_public_id', 'to_bed_public_id'] as $key) {
            $this->assertPublicIdValue($metadata[$key], 'metadata.'.$key);
        }
        foreach (['from_ward_code', 'from_bed_code', 'to_ward_code', 'to_bed_code'] as $key) {
            $this->assertText($metadata[$key], 'metadata.'.$key, 1, 64);
        }
        $this->assertPositiveInt($metadata['sequence'], 'metadata.sequence');
        $this->assertNonNegativeInt($metadata['expected_sequence'], 'metadata.expected_sequence');
        if ($metadata['sequence'] !== $metadata['expected_sequence'] + 1) {
            throw new InvalidAuditEvent('Transfer audit sequence must advance by exactly one.');
        }
        $this->assertSha256($metadata['payload_digest'], 'metadata.payload_digest');
        $this->assertNullablePublicId($metadata['request_correlation_id'], 'metadata.request_correlation_id');
    }

    /** @param array<string, mixed> $metadata */
    private function assertInpatientBedTransferDenial(?string $resourceId, bool $actorPresent, ?string $reason, array $metadata): void
    {
        $this->assertActor($actorPresent);
        $this->assertNullablePublicId($resourceId, 'resource_id');
        $this->assertReasonIn($reason, [
            'unauthorized_actor', 'validation_failed', 'encounter_missing', 'not_inpatient',
            'encounter_cancelled', 'encounter_closed', 'synthetic_only', 'placement_missing',
            'placement_unmanaged', 'placement_stale', 'source_bed_changed', 'source_inactive',
            'target_inactive', 'same_bed', 'service_class_change_not_authorized', 'stale_location',
            'target_occupied', 'duplicate_current_claim', 'idempotency_key_conflict', 'persistence_unavailable',
            'discharge_summary_final',
            'discharge_coding_source_final',
            'active_pharmacy_preparation',
        ]);
        $allowed = ['current_sequence'];
        foreach (array_keys($metadata) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new InvalidAuditEvent('Transfer denial metadata contains an unregistered key.');
            }
        }
        if (array_key_exists('current_sequence', $metadata)) {
            $this->assertNonNegativeInt($metadata['current_sequence'], 'metadata.current_sequence');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function assertRebuildAdminReconciled(?string $resourceId, bool $actorPresent, ?string $reason, array $metadata): void
    {
        if ($actorPresent) {
            throw new InvalidAuditEvent('Rebuild-admin reconciliation attribution must use its operator field.');
        }

        $this->assertPublicId($resourceId, 'resource_id');
        $this->assertText($reason, 'reason', 8, 255);
        $this->assertExactKeys($metadata, [
            'before',
            'after',
            'sessions_revoked',
            'operator',
            'reason',
            'disable_requested',
            'roles_changed',
            'status_changed',
            'mutated',
            'system_admin_bypass_remains',
            'note',
        ]);

        $before = $this->assertMap($metadata['before'], 'metadata.before');
        $after = $this->assertMap($metadata['after'], 'metadata.after');
        $this->assertExactKeys($before, ['roles', 'status']);
        $this->assertExactKeys($after, ['roles', 'status']);

        $canonical = [RoleCapabilityMatrix::ROLE_ADMIN];
        $knownDrift = [
            RoleCapabilityMatrix::ROLE_ADMIN,
            RoleCapabilityMatrix::ROLE_NURSE,
            RoleCapabilityMatrix::ROLE_PHYSICIAN,
            RoleCapabilityMatrix::ROLE_REGISTRAR,
            RoleCapabilityMatrix::ROLE_RMIK,
        ];
        $beforeRoles = $this->assertStringList($before['roles'], 'metadata.before.roles', 1, 5, 64);
        if ($beforeRoles !== $canonical && $beforeRoles !== $knownDrift) {
            throw new InvalidAuditEvent('Audit metadata.before.roles is not a registered reconciliation state.');
        }

        if ($this->assertStringList($after['roles'], 'metadata.after.roles', 1, 1, 64) !== $canonical) {
            throw new InvalidAuditEvent('Audit metadata.after.roles must be the canonical admin role.');
        }

        $this->assertStatus($before['status'], 'metadata.before.status');
        $this->assertStatus($after['status'], 'metadata.after.status');
        $this->assertNonNegativeInt($metadata['sessions_revoked'], 'metadata.sessions_revoked');
        $this->assertText($metadata['operator'], 'metadata.operator', 3, 255);
        $this->assertText($metadata['reason'], 'metadata.reason', 8, 255);

        if ($metadata['reason'] !== $reason) {
            throw new InvalidAuditEvent('Audit metadata.reason must match the top-level reason.');
        }

        foreach (['disable_requested', 'roles_changed', 'status_changed', 'mutated'] as $key) {
            $this->assertBool($metadata[$key], 'metadata.'.$key);
        }

        if ($metadata['system_admin_bypass_remains'] !== true) {
            throw new InvalidAuditEvent('Audit metadata.system_admin_bypass_remains must be true.');
        }

        if ($metadata['note'] !== 'is_system_administrator remains true; the system-admin capability bypass remains active.') {
            throw new InvalidAuditEvent('Audit metadata.note does not match the registered containment note.');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function assertTeachingRoleLifecycle(
        ?string $resourceId,
        bool $actorPresent,
        ?string $reason,
        array $metadata,
        bool $activated,
    ): void {
        if ($actorPresent) {
            throw new InvalidAuditEvent('Teaching-role lifecycle attribution must use its bounded operator field.');
        }

        $this->assertPublicId($resourceId, 'resource_id');
        $this->assertText($reason, 'reason', 8, 240);
        $this->assertExactKeys($metadata, [
            'target_email',
            'expected_role',
            'operator',
            'reason',
            'environment',
            'release_sha',
            'deployment_url',
            'canonical_host',
            'lease_public_id',
            'expires_at',
            'access_epoch',
            'activation_commitment',
            'login_state_commitment',
            'before',
            'after',
            'revoked',
            'login_material_rotated',
            'idempotent',
        ]);

        $roleByEmail = TeachingRoleAccessManager::ROSTER;
        $targetEmail = $metadata['target_email'] ?? null;
        if (! is_string($targetEmail)
            || ! array_key_exists($targetEmail, $roleByEmail)
            || ($metadata['expected_role'] ?? null) !== $roleByEmail[$targetEmail]) {
            throw new InvalidAuditEvent('Teaching-role audit target is not an exact registered email and role pair.');
        }

        $target = User::query()
            ->where(function ($query) use ($targetEmail, $roleByEmail): void {
                $query->where('email', $targetEmail)
                    ->orWhere('teaching_access_roster_key', $roleByEmail[$targetEmail]);
            })
            ->where('public_id', $resourceId)
            ->first();
        if (! $target instanceof User || $target->public_id !== $resourceId) {
            throw new InvalidAuditEvent('Teaching-role audit resource ID does not match its registered target account.');
        }

        $this->assertText($metadata['operator'], 'metadata.operator', 3, 120);
        $this->assertText($metadata['reason'], 'metadata.reason', 8, 240);
        if ($metadata['reason'] !== $reason) {
            throw new InvalidAuditEvent('Teaching-role audit metadata.reason must match the top-level reason.');
        }

        if (! is_string($metadata['environment'])
            || preg_match('/\A[a-z0-9][a-z0-9._-]{2,63}\z/', $metadata['environment']) !== 1) {
            throw new InvalidAuditEvent('Teaching-role audit environment is invalid.');
        }
        if (! is_string($metadata['release_sha'])
            || preg_match('/\A[a-f0-9]{40}\z/', $metadata['release_sha']) !== 1) {
            throw new InvalidAuditEvent('Teaching-role audit release SHA is invalid.');
        }
        foreach (['deployment_url', 'canonical_host'] as $key) {
            if (! is_string($metadata[$key])
                || preg_match('/\A[a-z0-9][a-z0-9.-]{2,253}\z/', $metadata[$key]) !== 1) {
                throw new InvalidAuditEvent('Teaching-role audit deployment binding is invalid.');
            }
        }
        $this->assertNonNegativeInt($metadata['access_epoch'], 'metadata.access_epoch');
        if ($metadata['access_epoch'] < 1) {
            throw new InvalidAuditEvent('Teaching-role audit access epoch must be positive.');
        }
        foreach (['activation_commitment', 'login_state_commitment'] as $key) {
            $value = $metadata[$key];
            if ($value !== null && (! is_string($value) || preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1)) {
                throw new InvalidAuditEvent('Teaching-role audit commitment is invalid.');
            }
        }
        if (! is_string($metadata['login_state_commitment'])) {
            throw new InvalidAuditEvent('Teaching-role audit login-state commitment is required.');
        }
        foreach (['lease_public_id', 'expires_at'] as $key) {
            if ($metadata[$key] !== null && ! is_string($metadata[$key])) {
                throw new InvalidAuditEvent('Teaching-role audit lease binding is invalid.');
            }
        }
        if ($activated) {
            $this->assertPublicId($metadata['lease_public_id'], 'metadata.lease_public_id');
            $this->assertText($metadata['expires_at'], 'metadata.expires_at', 20, 40);
            if (! is_string($metadata['activation_commitment'])) {
                throw new InvalidAuditEvent('Teaching-role activation requires its one-way activation commitment.');
            }
        } elseif ($metadata['activation_commitment'] !== null) {
            throw new InvalidAuditEvent('Teaching-role closure cannot carry an activation commitment.');
        }

        $before = $this->assertTeachingRoleState($metadata['before'], 'metadata.before');
        $after = $this->assertTeachingRoleState($metadata['after'], 'metadata.after');
        $revoked = $this->assertMap($metadata['revoked'], 'metadata.revoked');
        $this->assertExactKeys($revoked, ['sessions', 'passkeys', 'reset_records']);
        foreach (['sessions', 'passkeys', 'reset_records'] as $key) {
            $this->assertNonNegativeInt($revoked[$key], 'metadata.revoked.'.$key);
        }

        $this->assertBool($metadata['login_material_rotated'], 'metadata.login_material_rotated');
        $this->assertBool($metadata['idempotent'], 'metadata.idempotent');
        if ($metadata['idempotent'] === $metadata['login_material_rotated']) {
            throw new InvalidAuditEvent('Teaching-role audit idempotency and login-material rotation flags are inconsistent.');
        }

        $expectedStatus = $activated ? 'TEACHING_ACTIVE' : 'DISABLED';
        $expectedVerified = $activated;
        if ($after['status'] !== $expectedStatus
            || $after['verified'] !== $expectedVerified
            || $after['remember_present'] !== false
            || $after['mfa_present'] !== false
            || $after['sessions'] !== 0
            || $after['passkeys'] !== 0
            || $after['reset_records'] !== 0) {
            throw new InvalidAuditEvent('Teaching-role audit after state is not the registered closed lifecycle state.');
        }

        if ($metadata['idempotent'] === true && $before !== $after) {
            throw new InvalidAuditEvent('Idempotent teaching-role audit events require identical before and after states.');
        }
    }

    /** @return array<string, mixed> */
    private function assertTeachingRoleState(mixed $value, string $path): array
    {
        $state = $this->assertMap($value, $path);
        $this->assertExactKeys($state, [
            'status',
            'verified',
            'remember_present',
            'mfa_present',
            'sessions',
            'passkeys',
            'reset_records',
        ]);
        $this->assertStatus($state['status'], $path.'.status');
        foreach (['verified', 'remember_present', 'mfa_present'] as $key) {
            $this->assertBool($state[$key], $path.'.'.$key);
        }
        foreach (['sessions', 'passkeys', 'reset_records'] as $key) {
            $this->assertNonNegativeInt($state[$key], $path.'.'.$key);
        }

        return $state;
    }

    /** @param array<string, mixed> $metadata */
    private function assertTeachingReset(?string $resourceId, ?string $reason, array $metadata, bool $completed): void
    {
        if ($resourceId !== 'synthetic-reset') {
            throw new InvalidAuditEvent('Teaching reset resource_id must be synthetic-reset.');
        }

        if ($reason !== null) {
            $this->assertText($reason, 'reason', 1, 255);
        }

        $this->assertExactKeys($metadata, $completed
            ? ['boundary', 'deleted_patients', 'evidence_preserved', 'queue_counter_high_water_preserved', 'reset_correlation_id', 'collection_batch_count', 'collection_active_slot_count', 'collection_member_count', 'collection_event_count', 'collection_handoff_count', 'collection_operation_receipt_count', 'collection_active_slot_digest', 'collection_operation_receipt_digest', 'collection_evidence_digest']
            : ['boundary', 'evidence_preserved', 'queue_counter_high_water_preserved', 'reset_correlation_id', 'collection_batch_count', 'collection_active_slot_count', 'collection_member_count', 'collection_event_count', 'collection_handoff_count', 'collection_operation_receipt_count', 'collection_active_slot_digest', 'collection_operation_receipt_digest', 'collection_evidence_digest']);

        if ($metadata['boundary'] !== 'synthetic_patient_graph'
            || $metadata['evidence_preserved'] !== true
            || $metadata['queue_counter_high_water_preserved'] !== true) {
            throw new InvalidAuditEvent('Teaching reset evidence boundary is invalid.');
        }

        if ($completed) {
            $this->assertNonNegativeInt($metadata['deleted_patients'], 'metadata.deleted_patients');
        }
        $this->assertPublicIdValue($metadata['reset_correlation_id'], 'metadata.reset_correlation_id');
        foreach (['collection_batch_count', 'collection_active_slot_count', 'collection_member_count', 'collection_event_count', 'collection_handoff_count', 'collection_operation_receipt_count'] as $key) {
            $this->assertNonNegativeInt($metadata[$key], 'metadata.'.$key);
        }
        $this->assertSha256($metadata['collection_active_slot_digest'], 'metadata.collection_active_slot_digest');
        $this->assertSha256($metadata['collection_operation_receipt_digest'], 'metadata.collection_operation_receipt_digest');
        $this->assertSha256($metadata['collection_evidence_digest'], 'metadata.collection_evidence_digest');
    }

    /** @param array<string, mixed> $metadata */
    private function assertPatientRegistration(array $metadata): void
    {
        if (! array_key_exists('care_setting', $metadata)) {
            $this->assertExactKeys($metadata, [
                'patient_public_id', 'clinic_name', 'doctor_name', 'schedule_label', 'payer_type', 'queue_date', 'queue_number',
            ]);
            $this->assertClinicRegistrationFields($metadata);

            return;
        }

        if ($metadata['care_setting'] === Encounter::CARE_SETTING_EMERGENCY) {
            $this->assertExactKeys($metadata, [
                'care_setting', 'patient_public_id', 'clinic_name', 'doctor_name', 'schedule_label',
                'payer_type', 'case_type', 'accident_type', 'queue_date', 'queue_number',
            ]);
            $this->assertClinicRegistrationFields($metadata);
            $this->assertEnum($metadata['case_type'], Encounter::CASE_TYPE_VALUES, 'metadata.case_type');
            $this->assertEnum($metadata['accident_type'], Encounter::ACCIDENT_TYPE_VALUES, 'metadata.accident_type');

            return;
        }

        if ($metadata['care_setting'] === Encounter::CARE_SETTING_INPATIENT) {
            $this->assertExactKeys($metadata, [
                'care_setting', 'patient_public_id', 'ward_name', 'ward_class', 'bed_code',
                'continue_from', 'payer_type', 'queue_date', 'queue_number',
            ]);
            $this->assertNullablePublicId($metadata['patient_public_id'], 'metadata.patient_public_id');
            $this->assertText($metadata['ward_name'], 'metadata.ward_name', 1, 120);
            $this->assertText($metadata['ward_class'], 'metadata.ward_class', 1, 120);
            $this->assertText($metadata['bed_code'], 'metadata.bed_code', 1, 32);
            $this->assertEnum($metadata['continue_from'], Encounter::CONTINUE_FROM_VALUES, 'metadata.continue_from');
            $this->assertEnum($metadata['payer_type'], Encounter::PAYER_VALUES, 'metadata.payer_type');
            $this->assertDate($metadata['queue_date'], 'metadata.queue_date');
            $this->assertPositiveInt($metadata['queue_number'], 'metadata.queue_number');

            return;
        }

        throw new InvalidAuditEvent('Audit metadata.care_setting is not a registered patient-registration variant.');
    }

    /** @param array<string, mixed> $metadata */
    private function assertClinicRegistrationFields(array $metadata): void
    {
        $this->assertNullablePublicId($metadata['patient_public_id'], 'metadata.patient_public_id');
        $this->assertText($metadata['clinic_name'], 'metadata.clinic_name', 1, 255);
        $this->assertNullableText($metadata['doctor_name'], 'metadata.doctor_name', 255);
        $this->assertNullableText($metadata['schedule_label'], 'metadata.schedule_label', 255);
        $this->assertEnum($metadata['payer_type'], Encounter::PAYER_VALUES, 'metadata.payer_type');
        $this->assertDate($metadata['queue_date'], 'metadata.queue_date');
        $this->assertPositiveInt($metadata['queue_number'], 'metadata.queue_number');
    }

    /** @param array<string, mixed> $metadata */
    private function assertClinicalNote(array $metadata): void
    {
        $this->assertExactKeys($metadata, ['care_setting', 'entry_type']);
        $this->assertEnum(
            $metadata['care_setting'],
            [Encounter::CARE_SETTING_EMERGENCY, Encounter::CARE_SETTING_INPATIENT],
            'metadata.care_setting',
        );
        $this->assertEnum($metadata['entry_type'], ClinicalEntry::TYPE_VALUES, 'metadata.entry_type');
    }

    /** @param array<string, mixed> $metadata */
    private function assertClinicalNoteDenial(array $metadata): void
    {
        $this->assertExactKeys($metadata, ['care_setting']);
        $this->assertEnum($metadata['care_setting'], Encounter::CARE_SETTINGS, 'metadata.care_setting');
    }

    /** @param array<string, mixed> $metadata */
    private function assertEncounterCancellation(array $metadata): void
    {
        $this->assertExactKeys($metadata, [
            'care_setting',
            'cancellation_public_id',
            'reason_code',
            'prior_status',
            'new_status',
            'queue_date',
            'queue_number',
            'active_worklists_excluded',
            'inpatient_bed_released',
        ]);
        $this->assertEnum($metadata['care_setting'], Encounter::CARE_SETTINGS, 'metadata.care_setting');
        $this->assertPublicIdValue($metadata['cancellation_public_id'], 'metadata.cancellation_public_id');
        $this->assertEnum($metadata['reason_code'], EncounterCancellation::REASON_CODES, 'metadata.reason_code');
        $this->assertExactValue($metadata['prior_status'], Encounter::STATUS_REGISTERED, 'metadata.prior_status');
        $this->assertExactValue($metadata['new_status'], Encounter::STATUS_CANCELLED, 'metadata.new_status');
        $this->assertDate($metadata['queue_date'], 'metadata.queue_date');
        $this->assertNullablePositiveInt($metadata['queue_number'], 'metadata.queue_number');
        if ($metadata['active_worklists_excluded'] !== true) {
            throw new InvalidAuditEvent('Audit metadata.active_worklists_excluded must be true.');
        }
        $this->assertBool($metadata['inpatient_bed_released'], 'metadata.inpatient_bed_released');
        if ($metadata['inpatient_bed_released'] !== ($metadata['care_setting'] === Encounter::CARE_SETTING_INPATIENT)) {
            throw new InvalidAuditEvent('Audit metadata.inpatient_bed_released does not match the care setting.');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function assertEncounterCancellationDenial(?string $reason, array $metadata): void
    {
        $this->assertReasonIn($reason, [
            'encounter_not_registered',
            'clinical_activity_exists',
            'diagnostic_activity_exists',
            'location_activity_exists',
            'rm_activity_exists',
            'downstream_activity_exists',
            'idempotency_key_conflict',
            'already_cancelled',
            'pharmacy_evidence_exists',
        ]);
        $this->assertExactKeys($metadata, ['care_setting']);
        $this->assertEnum($metadata['care_setting'], Encounter::CARE_SETTINGS, 'metadata.care_setting');
    }

    /** @param array<string, mixed> $metadata */
    private function assertEncounterPrint(array $metadata): void
    {
        $this->assertExactKeys($metadata, ['documents', 'teaching_only', 'live_bpjs']);
        $documents = $this->assertStringList($metadata['documents'], 'metadata.documents', 1, 6, 32);
        foreach ($documents as $document) {
            if (! in_array($document, self::PRINT_DOCUMENTS, true)) {
                throw new InvalidAuditEvent('Audit metadata.documents contains an unknown document.');
            }
        }

        if ($metadata['teaching_only'] !== true || $metadata['live_bpjs'] !== false) {
            throw new InvalidAuditEvent('Print audit must retain its teaching-only and no-live-BPJS boundary.');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function assertEncounterPrintDenial(array $metadata): void
    {
        $this->assertExactKeys($metadata, ['documents']);
        $documents = $this->assertStringList($metadata['documents'], 'metadata.documents', 1, 6, 32);
        foreach ($documents as $document) {
            if (! in_array($document, self::PRINT_DOCUMENTS, true)) {
                throw new InvalidAuditEvent('Audit metadata.documents contains an unknown document.');
            }
        }
    }

    /** @param array<string, mixed> $metadata */
    private function assertAuthorizationDenial(array $metadata): void
    {
        $this->assertExactKeys($metadata, ['http_method', 'http_status']);
        $this->assertEnum($metadata['http_method'], ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], 'metadata.http_method');

        if ($metadata['http_status'] !== 403) {
            throw new InvalidAuditEvent('Authorization denial status must be 403.');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function assertDocumentation(
        string $action,
        string $resourceType,
        ?string $resourceId,
        bool $actorPresent,
        string $outcome,
        ?string $reason,
        array $metadata,
    ): void {
        $this->assertActor($actorPresent);
        $this->assertPublicId($resourceId, 'resource_id');
        $documentType = str_starts_with($action, 'clinical.nursing.')
            ? OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT
            : OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT;

        if ($outcome === 'SUCCESS' && $resourceType === 'outpatient_clinical_document') {
            $this->assertNullReason($reason);
            $this->assertExactKeys($metadata, ['encounter_id', 'document_type', 'version', 'author_user_public_id', 'document_state']);
            $this->assertPublicIdValue($metadata['encounter_id'], 'metadata.encounter_id');
            $this->assertExactValue($metadata['document_type'], $documentType, 'metadata.document_type');
            $this->assertPositiveInt($metadata['version'], 'metadata.version');
            $this->assertPublicIdValue($metadata['author_user_public_id'], 'metadata.author_user_public_id');
            $expectedState = str_ends_with($action, '.finalize')
                ? OutpatientClinicalDocument::STATE_FINAL
                : OutpatientClinicalDocument::STATE_DRAFT;
            $this->assertExactValue($metadata['document_state'], $expectedState, 'metadata.document_state');

            return;
        }

        if ($outcome !== 'DENIED' || $resourceType !== 'encounter') {
            throw new InvalidAuditEvent('Documentation audit event tuple is not registered.');
        }

        $this->assertReasonIn($reason, [
            'encounter_closed', 'encounter_cancelled', 'document_missing', 'validation_failed', 'stale_version', 'author_mismatch', 'document_final', 'active_pharmacy_prescriptions',
        ]);
        $required = ['document_type'];

        if ($reason === 'stale_version') {
            $required = ['document_type', 'expected_version', 'current_version'];
        } elseif ($reason === 'validation_failed') {
            $hasDigestList = array_key_exists('invalid_field_key_digests', $metadata);
            $hasDigestCount = array_key_exists('invalid_field_key_count', $metadata);
            if ($hasDigestList !== $hasDigestCount) {
                throw new InvalidAuditEvent('Invalid field-key digest metadata must include its total count.');
            }

            $detailGroups = array_filter([
                array_key_exists('invalid_field_key', $metadata),
                $hasDigestList,
                array_key_exists('missing_field_keys', $metadata),
            ]);
            if (count($detailGroups) > 1) {
                throw new InvalidAuditEvent('Validation denial metadata may contain only one field-key detail.');
            }

            if (array_key_exists('invalid_field_key', $metadata)) {
                $required[] = 'invalid_field_key';
            } elseif ($hasDigestList) {
                $required[] = 'invalid_field_key_digests';
                $required[] = 'invalid_field_key_count';
            } elseif (array_key_exists('missing_field_keys', $metadata)) {
                $required[] = 'missing_field_keys';
            }
        }

        $this->assertExactKeys($metadata, $required);
        $this->assertExactValue($metadata['document_type'], $documentType, 'metadata.document_type');

        if ($reason === 'stale_version') {
            $this->assertNonNegativeInt($metadata['expected_version'], 'metadata.expected_version');
            $this->assertNonNegativeInt($metadata['current_version'], 'metadata.current_version');
        }

        if (array_key_exists('invalid_field_key', $metadata)) {
            $this->assertText($metadata['invalid_field_key'], 'metadata.invalid_field_key', 1, 255);
        }
        if (array_key_exists('invalid_field_key_digests', $metadata)) {
            $digests = $this->assertStringList(
                $metadata['invalid_field_key_digests'],
                'metadata.invalid_field_key_digests',
                1,
                50,
                64,
            );
            foreach ($digests as $digest) {
                if (preg_match('/\A[a-f0-9]{64}\z/', $digest) !== 1) {
                    throw new InvalidAuditEvent('Audit invalid_field_key_digests must contain SHA-256 hex digests.');
                }
            }
            $this->assertPositiveInt($metadata['invalid_field_key_count'], 'metadata.invalid_field_key_count');
            if ($metadata['invalid_field_key_count'] < count($digests)) {
                throw new InvalidAuditEvent('Audit invalid_field_key_count cannot be smaller than the digest list.');
            }
        }
        if (array_key_exists('missing_field_keys', $metadata)) {
            $this->assertStringList($metadata['missing_field_keys'], 'metadata.missing_field_keys', 1, 50, 255);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function assertLabOrderSuccess(array $metadata): void
    {
        $this->assertExactKeys($metadata, ['encounter_id', 'test_code']);
        $this->assertPublicIdValue($metadata['encounter_id'], 'metadata.encounter_id');
        $this->assertEnum($metadata['test_code'], LabTestCatalog::codes(), 'metadata.test_code');
    }

    /** @param array<string, mixed> $metadata */
    private function assertLabOrderDenial(array $metadata): void
    {
        $this->assertExactKeys($metadata, ['test_code']);
        $this->assertEnum($metadata['test_code'], LabTestCatalog::codes(), 'metadata.test_code');
    }

    /** @param array<string, mixed> $metadata */
    private function assertLabResultSuccess(array $metadata): void
    {
        $this->assertExactKeys($metadata, ['encounter_id', 'test_code', 'result_status']);
        $this->assertPublicIdValue($metadata['encounter_id'], 'metadata.encounter_id');
        $this->assertEnum($metadata['test_code'], LabTestCatalog::codes(), 'metadata.test_code');
        $this->assertExactValue($metadata['result_status'], LabDiagnosticResult::STATUS_FINAL, 'metadata.result_status');
    }

    /** @param array<string, mixed> $metadata */
    private function assertLabResultDenial(array $metadata): void
    {
        $this->assertExactKeys($metadata, ['encounter_id']);
        $this->assertNullablePublicId($metadata['encounter_id'], 'metadata.encounter_id');
    }

    /** @param array<string, mixed> $metadata */
    private function assertAmendmentRequestSubmitSuccess(
        ?string $resourceId,
        bool $actorPresent,
        ?string $reason,
        array $metadata,
    ): void {
        $this->assertActor($actorPresent);
        $this->assertPublicId($resourceId, 'resource_id');
        $this->assertNullReason($reason);
        $this->assertExactKeys($metadata, [
            'encounter_id', 'original_document_id', 'original_document_version',
            'reason_code', 'request_state', 'request_version',
        ]);
        $this->assertPublicIdValue($metadata['encounter_id'], 'metadata.encounter_id');
        $this->assertPublicIdValue($metadata['original_document_id'], 'metadata.original_document_id');
        $this->assertPositiveInt($metadata['original_document_version'], 'metadata.original_document_version');
        $this->assertEnum($metadata['reason_code'], OutpatientPostClosureAmendmentRequest::REASON_CODES, 'metadata.reason_code');
        $this->assertExactValue($metadata['request_state'], OutpatientPostClosureAmendmentRequest::STATE_SUBMITTED, 'metadata.request_state');
        $this->assertExactValue($metadata['request_version'], 1, 'metadata.request_version');
    }

    /** @param array<string, mixed> $metadata */
    private function assertAmendmentRequestDecisionSuccess(
        ?string $resourceId,
        bool $actorPresent,
        ?string $reason,
        array $metadata,
    ): void {
        $this->assertActor($actorPresent);
        $this->assertPublicId($resourceId, 'resource_id');
        $this->assertNullReason($reason);
        $this->assertExactKeys($metadata, ['decision', 'encounter_id', 'request_state', 'request_version']);
        $this->assertPublicIdValue($metadata['encounter_id'], 'metadata.encounter_id');
        $this->assertEnum($metadata['decision'], [
            OutpatientPostClosureAmendmentRequest::STATE_APPROVED,
            OutpatientPostClosureAmendmentRequest::STATE_DENIED,
        ], 'metadata.decision');
        $this->assertExactValue($metadata['request_state'], $metadata['decision'], 'metadata.request_state');
        $this->assertPositiveInt($metadata['request_version'], 'metadata.request_version');
    }

    /** @param array<string, mixed> $metadata */
    private function assertAmendmentRequestDenial(
        ?string $resourceId,
        bool $actorPresent,
        ?string $reason,
        array $metadata,
        bool $decision,
    ): void {
        $this->assertActor($actorPresent);
        $this->assertPublicId($resourceId, 'resource_id');
        $this->assertExactKeys($metadata, ['amendment_request_id', 'encounter_id']);
        $this->assertPublicIdValue($metadata['encounter_id'], 'metadata.encounter_id');
        $this->assertNullablePublicId($metadata['amendment_request_id'], 'metadata.amendment_request_id');
        $common = [
            'not_outpatient', 'synthetic_only', 'encounter_not_closed',
            'original_document_missing', 'original_document_not_final',
            'original_document_version_mismatch', 'original_document_version_missing',
            'idempotency_key_conflict',
        ];
        $allowed = $decision
            ? array_merge($common, ['requester_cannot_decide', 'request_not_submitted', 'stale_version'])
            : array_merge($common, ['baseline_signoff_missing']);
        $this->assertReasonIn($reason, $allowed);
        if ($decision && $metadata['amendment_request_id'] !== $resourceId) {
            throw new InvalidAuditEvent('Decision denial amendment request reference must match resource_id.');
        }
        if (! $decision && $metadata['amendment_request_id'] !== null) {
            throw new InvalidAuditEvent('Submit denial must not claim an amendment request.');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function assertAmendmentAddendumSuccess(
        ?string $resourceId,
        bool $actorPresent,
        ?string $reason,
        array $metadata,
        bool $finalize,
    ): void {
        $this->assertActor($actorPresent);
        $this->assertPublicId($resourceId, 'resource_id');
        $this->assertNullReason($reason);
        $keys = [
            'addendum_state', 'addendum_version', 'amendment_request_id', 'content_digest',
            'definition_version', 'encounter_id', 'original_document_id', 'original_document_version',
        ];
        if ($finalize) {
            $keys = array_merge($keys, ['request_state', 'request_version']);
        }
        $this->assertExactKeys($metadata, $keys);
        $this->assertPublicIdValue($metadata['amendment_request_id'], 'metadata.amendment_request_id');
        $this->assertPublicIdValue($metadata['encounter_id'], 'metadata.encounter_id');
        $this->assertPublicIdValue($metadata['original_document_id'], 'metadata.original_document_id');
        $this->assertPositiveInt($metadata['original_document_version'], 'metadata.original_document_version');
        $this->assertPositiveInt($metadata['addendum_version'], 'metadata.addendum_version');
        $this->assertExactValue(
            $metadata['addendum_state'],
            $finalize ? OutpatientClinicalDocumentAddendum::STATE_FINAL : OutpatientClinicalDocumentAddendum::STATE_DRAFT,
            'metadata.addendum_state',
        );
        $this->assertExactValue($metadata['definition_version'], OutpatientClinicalDocumentAddendum::DEFINITION_VERSION, 'metadata.definition_version');
        if (! is_string($metadata['content_digest']) || preg_match('/\A[a-f0-9]{64}\z/', $metadata['content_digest']) !== 1) {
            throw new InvalidAuditEvent('Audit metadata.content_digest must be a lowercase SHA-256 digest.');
        }
        if ($finalize) {
            $this->assertExactValue($metadata['request_state'], OutpatientPostClosureAmendmentRequest::STATE_CONSUMED, 'metadata.request_state');
            $this->assertPositiveInt($metadata['request_version'], 'metadata.request_version');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function assertAmendmentAddendumDenial(
        ?string $resourceId,
        bool $actorPresent,
        ?string $reason,
        array $metadata,
    ): void {
        $this->assertActor($actorPresent);
        $this->assertPublicId($resourceId, 'resource_id');
        $this->assertExactKeys($metadata, ['amendment_request_id', 'encounter_id']);
        $this->assertPublicIdValue($metadata['amendment_request_id'], 'metadata.amendment_request_id');
        $this->assertPublicIdValue($metadata['encounter_id'], 'metadata.encounter_id');
        if ($metadata['amendment_request_id'] !== $resourceId) {
            throw new InvalidAuditEvent('Addendum denial request reference must match resource_id.');
        }
        $this->assertReasonIn($reason, [
            'not_outpatient', 'synthetic_only', 'encounter_not_closed',
            'request_not_approved', 'request_denied', 'request_already_consumed',
            'actor_not_approved_author', 'original_document_missing',
            'original_document_not_final', 'original_document_version_mismatch',
            'original_document_version_missing', 'addendum_missing',
            'addendum_already_final', 'stale_version', 'idempotency_key_conflict',
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function assertRmAmendmentSuccess(
        ?string $resourceId,
        bool $actorPresent,
        ?string $reason,
        array $metadata,
        bool $signoff,
    ): void {
        $this->assertActor($actorPresent);
        $this->assertPublicId($resourceId, 'resource_id');
        $this->assertNullReason($reason);
        $this->assertExactKeys($metadata, [
            'addendum_id', 'amendment_request_id', 'baseline_review_id', 'definition_version',
            'encounter_id', 'failed_item_ids', 'review_version', 'source_fingerprint',
        ]);
        foreach (['addendum_id', 'amendment_request_id', 'baseline_review_id', 'encounter_id'] as $key) {
            $this->assertPublicIdValue($metadata[$key], 'metadata.'.$key);
        }
        $this->assertExactValue($metadata['definition_version'], OutpatientRmAmendmentReview::DEFINITION_VERSION, 'metadata.definition_version');
        $this->assertPositiveInt($metadata['review_version'], 'metadata.review_version');
        if (! is_string($metadata['source_fingerprint']) || preg_match('/\A[a-f0-9]{64}\z/', $metadata['source_fingerprint']) !== 1) {
            throw new InvalidAuditEvent('Audit metadata.source_fingerprint must be a lowercase SHA-256 digest.');
        }
        if (! is_array($metadata['failed_item_ids']) || ! array_is_list($metadata['failed_item_ids'])) {
            throw new InvalidAuditEvent('Audit metadata.failed_item_ids must be a list.');
        }
        foreach ($metadata['failed_item_ids'] as $item) {
            $this->assertEnum($item, ['BASELINE_SIGNOFF', 'FINAL_ADDENDUM', 'CURRENT_SOURCE_REFERENCE', 'NO_ACTIVE_LAB_ORDERS'], 'metadata.failed_item_ids[]');
        }
        if ($signoff && $metadata['failed_item_ids'] !== []) {
            throw new InvalidAuditEvent('Successful amendment sign-off cannot retain failed items.');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function assertRmAmendmentDenial(
        ?string $resourceId,
        bool $actorPresent,
        ?string $reason,
        array $metadata,
    ): void {
        $this->assertActor($actorPresent);
        $this->assertPublicId($resourceId, 'resource_id');
        $this->assertExactKeys($metadata, ['amendment_request_id', 'encounter_id']);
        $this->assertPublicIdValue($metadata['amendment_request_id'], 'metadata.amendment_request_id');
        $this->assertPublicIdValue($metadata['encounter_id'], 'metadata.encounter_id');
        if ($metadata['amendment_request_id'] !== $resourceId) {
            throw new InvalidAuditEvent('RMIK amendment denial request reference must match resource_id.');
        }
        $this->assertReasonIn($reason, [
            'not_outpatient', 'synthetic_only', 'encounter_not_closed', 'request_not_consumed',
            'final_addendum_missing', 'original_source_stale', 'baseline_signoff_missing',
            'stale_version', 'source_stale', 'active_lab_orders', 'checklist_incomplete',
            'idempotency_key_conflict',
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function assertInpatientMasterMutation(
        string $action,
        ?string $resourceId,
        bool $actorPresent,
        ?string $reason,
        array $metadata,
    ): void {
        $this->assertActor($actorPresent);
        $this->assertPublicId($resourceId, 'resource_id');
        $this->assertNullReason($reason);
        $this->assertExactKeys($metadata, [
            'after_digest', 'before_digest', 'code', 'reason_code', 'state', 'version', 'ward_id',
        ]);
        $this->assertText($metadata['code'], 'metadata.code', 1, 64);
        $this->assertEnum($metadata['reason_code'], [
            'INITIAL_SETUP', 'DATA_CORRECTION', 'OPERATIONAL_CHANGE', 'RETIREMENT',
        ], 'metadata.reason_code');
        $this->assertEnum($metadata['state'], ['ACTIVE', 'RETIRED'], 'metadata.state');
        $this->assertPositiveInt($metadata['version'], 'metadata.version');
        foreach (['before_digest', 'after_digest'] as $digest) {
            if ($metadata[$digest] !== null
                && (! is_string($metadata[$digest]) || preg_match('/\A[a-f0-9]{64}\z/', $metadata[$digest]) !== 1)) {
                throw new InvalidAuditEvent("Audit metadata.{$digest} must be a lowercase SHA-256 digest or null.");
            }
        }
        if (str_ends_with($action, '.create') && $metadata['before_digest'] !== null) {
            throw new InvalidAuditEvent('Inpatient master create audit must not claim a before digest.');
        }
        if (! str_ends_with($action, '.create') && $metadata['before_digest'] === null) {
            throw new InvalidAuditEvent('Inpatient master update/retire audit requires a before digest.');
        }
        if (str_contains($action, '.bed.')) {
            $this->assertPublicIdValue($metadata['ward_id'], 'metadata.ward_id');
        } elseif ($metadata['ward_id'] !== null) {
            throw new InvalidAuditEvent('Ward master audit cannot claim a parent ward ID.');
        }
        if (str_ends_with($action, '.retire') && $metadata['state'] !== 'RETIRED') {
            throw new InvalidAuditEvent('Inpatient master retire audit must record RETIRED state.');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function assertCompleteness(
        string $action,
        string $resourceType,
        ?string $resourceId,
        bool $actorPresent,
        string $outcome,
        ?string $reason,
        array $metadata,
    ): void {
        $this->assertActor($actorPresent);
        $this->assertPublicId($resourceId, 'resource_id');

        if ($outcome === 'SUCCESS' && $resourceType === 'outpatient_rm_completeness_review') {
            $this->assertNullReason($reason);
            $this->assertExactKeys($metadata, [
                'encounter_id', 'review_version', 'definition_version', 'source_fingerprint', 'failed_item_ids',
            ]);
            $this->assertPublicIdValue($metadata['encounter_id'], 'metadata.encounter_id');
            $this->assertPositiveInt($metadata['review_version'], 'metadata.review_version');
            $this->assertExactValue(
                $metadata['definition_version'],
                OutpatientRmCompletenessReview::DEFINITION_VERSION,
                'metadata.definition_version',
            );
            if (! is_string($metadata['source_fingerprint']) || preg_match('/\A[a-f0-9]{64}\z/', $metadata['source_fingerprint']) !== 1) {
                throw new InvalidAuditEvent('Audit metadata.source_fingerprint must be a SHA-256 hex digest.');
            }
            $failed = $this->assertStringList($metadata['failed_item_ids'], 'metadata.failed_item_ids', 0, 12, 64);
            $this->assertCompletenessItems($failed);

            return;
        }

        if ($outcome !== 'DENIED' || $resourceType !== 'encounter') {
            throw new InvalidAuditEvent('Completeness audit event tuple is not registered.');
        }

        $allowedReasons = ['encounter_not_ready', 'encounter_cancelled', 'stale_version', 'source_stale'];
        if ($action === 'rmik.completeness.signoff') {
            $allowedReasons = array_merge($allowedReasons, [
                'active_lab_orders', 'unresolved_lab_specimens', 'lab_result_not_acknowledged',
                'active_radiology_orders', 'radiology_report_not_acknowledged', 'checklist_incomplete',
                'active_pharmacy_prescriptions',
            ]);
        }
        $this->assertReasonIn($reason, $allowedReasons);

        if (in_array($reason, [
            'active_lab_orders', 'unresolved_lab_specimens', 'lab_result_not_acknowledged',
            'active_radiology_orders', 'radiology_report_not_acknowledged', 'checklist_incomplete',
            'active_pharmacy_prescriptions',
        ], true)) {
            $this->assertExactKeys($metadata, ['failed_item_ids']);
            $failed = $this->assertStringList($metadata['failed_item_ids'], 'metadata.failed_item_ids', 1, 12, 64);
            $this->assertCompletenessItems($failed);

            return;
        }

        $this->assertExactKeys($metadata, []);
    }

    /** @param array<string,mixed> $metadata */
    private function assertInpatientRm(
        string $action,
        string $resourceType,
        ?string $resourceId,
        bool $actorPresent,
        string $outcome,
        ?string $reason,
        array $metadata,
    ): void {
        $this->assertActor($actorPresent);
        $this->assertPublicId($resourceId, 'resource_id');

        if ($outcome === 'SUCCESS' && $action === 'rmik.inpatient.coding.draft.save' && $resourceType === 'inpatient_rm_coding') {
            $this->assertNullReason($reason);
            $this->assertExactKeys($metadata, [
                'encounter_public_id', 'definition_version', 'profile', 'coding_version',
                'source_version_public_id', 'source_content_digest', 'coding_digest', 'assignment_count',
            ]);
            $this->assertPublicIdValue($metadata['encounter_public_id'], 'metadata.encounter_public_id');
            $this->assertExactValue($metadata['definition_version'], InpatientRmCoding::DEFINITION_VERSION, 'metadata.definition_version');
            $this->assertExactValue($metadata['profile'], InpatientRmCoding::PROFILE, 'metadata.profile');
            $this->assertPositiveInt($metadata['coding_version'], 'metadata.coding_version');
            $this->assertPublicIdValue($metadata['source_version_public_id'], 'metadata.source_version_public_id');
            $this->assertSha256($metadata['source_content_digest'], 'metadata.source_content_digest');
            $this->assertSha256($metadata['coding_digest'], 'metadata.coding_digest');
            $this->assertPositiveInt($metadata['assignment_count'], 'metadata.assignment_count');

            return;
        }

        if ($outcome === 'SUCCESS'
            && in_array($action, ['rmik.inpatient.completeness.review.save', 'rmik.inpatient.episode.signoff'], true)
            && $resourceType === 'inpatient_rm_completeness_review') {
            $this->assertNullReason($reason);
            $this->assertExactKeys($metadata, [
                'encounter_public_id', 'definition_version', 'review_version', 'source_fingerprint',
                'coding_version', 'coding_digest', 'failed_item_ids', 'encounter_status_after',
            ]);
            $this->assertPublicIdValue($metadata['encounter_public_id'], 'metadata.encounter_public_id');
            $this->assertExactValue($metadata['definition_version'], InpatientRmCompletenessReview::DEFINITION_VERSION, 'metadata.definition_version');
            $this->assertPositiveInt($metadata['review_version'], 'metadata.review_version');
            $this->assertSha256($metadata['source_fingerprint'], 'metadata.source_fingerprint');
            $this->assertPositiveInt($metadata['coding_version'], 'metadata.coding_version');
            $this->assertSha256($metadata['coding_digest'], 'metadata.coding_digest');
            $failed = $this->assertStringList($metadata['failed_item_ids'], 'metadata.failed_item_ids', 0, 12, 64);
            foreach ($failed as $item) {
                $this->assertEnum($item, [
                    'IDENTITY_LINKED', 'ROUTINE_DISCHARGE_RECORDED', 'FINAL_DISCHARGE_SUMMARY',
                    'FINAL_DISCHARGE_CODING_SOURCE', 'NO_INPATIENT_DRAFT_DOCUMENTS',
                    'NO_ACTIVE_LAB_ORDERS', 'NO_UNRESOLVED_LAB_SPECIMENS',
                    'NO_UNACKNOWLEDGED_VERIFIED_LAB_RESULTS', 'NO_ACTIVE_RADIOLOGY_ORDERS',
                    'NO_UNACKNOWLEDGED_VERIFIED_RADIOLOGY_REPORTS', 'MANUAL_CODING_COMPLETE',
                    'NO_ACTIVE_MEDICATION_PRESCRIPTIONS',
                ], 'metadata.failed_item_ids[]');
            }
            $expectedStatus = $action === 'rmik.inpatient.episode.signoff' ? Encounter::STATUS_CLOSED : Encounter::STATUS_READY_FOR_RM;
            $this->assertExactValue($metadata['encounter_status_after'], $expectedStatus, 'metadata.encounter_status_after');
            if ($action === 'rmik.inpatient.episode.signoff' && $failed !== []) {
                throw new InvalidAuditEvent('Successful inpatient RMIK signoff cannot retain blockers.');
            }

            return;
        }

        if ($outcome !== 'DENIED' || $resourceType !== 'encounter') {
            throw new InvalidAuditEvent('Inpatient RMIK audit event tuple is not registered.');
        }
        $this->assertReasonIn($reason, [
            'unauthorized_actor', 'validation_failed', 'encounter_missing', 'not_inpatient',
            'synthetic_only', 'encounter_not_ready', 'encounter_closed', 'routine_discharge_missing',
            'source_binding_invalid', 'source_binding_stale', 'stale_coding_version', 'coding_final',
            'assignment_coverage_invalid', 'assignment_binding_invalid', 'stale_review_version',
            'coding_not_current_complete', 'coding_binding_invalid', 'review_not_current_complete',
            'checklist_incomplete', 'final_snapshot_invalid', 'idempotency_key_conflict',
            'receipt_binding_invalid', 'concurrent_change',
            'active_pharmacy_prescriptions',
        ]);
        foreach (array_keys($metadata) as $key) {
            if (! in_array($key, ['current_version', 'failed_item_ids'], true)) {
                throw new InvalidAuditEvent('Inpatient RMIK denial metadata contains an unregistered key.');
            }
        }
        if (array_key_exists('current_version', $metadata)) {
            $this->assertNonNegativeInt($metadata['current_version'], 'metadata.current_version');
        }
        if (array_key_exists('failed_item_ids', $metadata)) {
            $failed = $this->assertStringList($metadata['failed_item_ids'], 'metadata.failed_item_ids', 1, 12, 64);
            foreach ($failed as $item) {
                $this->assertEnum($item, [
                    'IDENTITY_LINKED', 'ROUTINE_DISCHARGE_RECORDED', 'FINAL_DISCHARGE_SUMMARY',
                    'FINAL_DISCHARGE_CODING_SOURCE', 'NO_INPATIENT_DRAFT_DOCUMENTS',
                    'NO_ACTIVE_LAB_ORDERS', 'NO_UNRESOLVED_LAB_SPECIMENS',
                    'NO_UNACKNOWLEDGED_VERIFIED_LAB_RESULTS', 'NO_ACTIVE_RADIOLOGY_ORDERS',
                    'NO_UNACKNOWLEDGED_VERIFIED_RADIOLOGY_REPORTS', 'NO_ACTIVE_MEDICATION_PRESCRIPTIONS',
                    'MANUAL_CODING_COMPLETE',
                ], 'metadata.failed_item_ids[]');
            }
        }
    }

    /** @param list<string> $items */
    private function assertCompletenessItems(array $items): void
    {
        foreach ($items as $item) {
            if (! in_array($item, self::COMPLETENESS_ITEMS, true)) {
                throw new InvalidAuditEvent('Audit failed_item_ids contains an unknown item.');
            }
        }
    }

    private function isDocumentationAction(string $action): bool
    {
        return in_array($action, [
            'clinical.nursing.draft.save',
            'clinical.nursing.finalize',
            'clinical.medical.draft.save',
            'clinical.medical.finalize',
        ], true);
    }

    /** @param array<string, mixed> $metadata */
    private function assertInpatientDocumentation(
        string $resourceType,
        ?string $resourceId,
        bool $actorPresent,
        string $outcome,
        ?string $reason,
        array $metadata,
    ): void {
        $this->assertActor($actorPresent);
        $this->assertPublicId($resourceId, 'resource_id');

        if ($outcome === 'SUCCESS' && $resourceType === 'inpatient_clinical_document') {
            $this->assertNullReason($reason);
            $this->assertExactKeys($metadata, [
                'encounter_id', 'document_type', 'document_state', 'document_version', 'service_date',
                'definition_version', 'author_user_public_id', 'ward_public_id', 'ward_code',
                'bed_public_id', 'bed_code', 'expected_version', 'payload_digest',
            ]);
            $this->assertPublicIdValue($metadata['encounter_id'], 'metadata.encounter_id');
            $this->assertEnum($metadata['document_type'], InpatientClinicalDocument::TYPES, 'metadata.document_type');
            $this->assertEnum($metadata['document_state'], [InpatientClinicalDocument::STATE_DRAFT, InpatientClinicalDocument::STATE_FINAL], 'metadata.document_state');
            $this->assertPositiveInt($metadata['document_version'], 'metadata.document_version');
            $this->assertDate($metadata['service_date'], 'metadata.service_date');
            $this->assertExactValue($metadata['definition_version'], InpatientClinicalDocument::DEFINITION_VERSION, 'metadata.definition_version');
            $this->assertPublicIdValue($metadata['author_user_public_id'], 'metadata.author_user_public_id');
            $this->assertPublicIdValue($metadata['ward_public_id'], 'metadata.ward_public_id');
            $this->assertText($metadata['ward_code'], 'metadata.ward_code', 1, 64);
            $this->assertPublicIdValue($metadata['bed_public_id'], 'metadata.bed_public_id');
            $this->assertText($metadata['bed_code'], 'metadata.bed_code', 1, 64);
            $this->assertNonNegativeInt($metadata['expected_version'], 'metadata.expected_version');
            $this->assertSha256($metadata['payload_digest'], 'metadata.payload_digest');

            return;
        }

        if ($outcome !== 'DENIED' || $resourceType !== 'encounter') {
            throw new InvalidAuditEvent('Inpatient documentation audit event tuple is not registered.');
        }
        $this->assertReasonIn($reason, [
            'validation_failed', 'encounter_missing', 'not_inpatient', 'encounter_cancelled', 'encounter_closed',
            'synthetic_only', 'placement_missing', 'placement_unmanaged', 'placement_stale', 'placement_inactive',
            'document_missing', 'stale_version', 'document_final', 'idempotency_key_conflict',
        ]);
        $allowed = [
            'document_type', 'definition_version', 'expected_version', 'current_version', 'invalid_field_key',
            'invalid_field_key_digests', 'invalid_field_key_count', 'missing_field_keys',
        ];
        foreach (array_keys($metadata) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new InvalidAuditEvent('Inpatient documentation denial metadata contains an unregistered key.');
            }
        }
        if (! array_key_exists('document_type', $metadata) || ! array_key_exists('definition_version', $metadata)) {
            throw new InvalidAuditEvent('Inpatient documentation denial metadata is incomplete.');
        }
        $this->assertEnum($metadata['document_type'], InpatientClinicalDocument::TYPES, 'metadata.document_type');
        $this->assertExactValue($metadata['definition_version'], InpatientClinicalDocument::DEFINITION_VERSION, 'metadata.definition_version');
        foreach (['expected_version', 'current_version'] as $key) {
            if (array_key_exists($key, $metadata)) {
                $this->assertNonNegativeInt($metadata[$key], 'metadata.'.$key);
            }
        }
        if (array_key_exists('invalid_field_key', $metadata)) {
            $this->assertText($metadata['invalid_field_key'], 'metadata.invalid_field_key', 1, 255);
        }
        if (array_key_exists('invalid_field_key_digests', $metadata)) {
            $digests = $this->assertStringList($metadata['invalid_field_key_digests'], 'metadata.invalid_field_key_digests', 1, 50, 64);
            foreach ($digests as $digest) {
                $this->assertSha256($digest, 'metadata.invalid_field_key_digests[]');
            }
            $this->assertPositiveInt($metadata['invalid_field_key_count'] ?? null, 'metadata.invalid_field_key_count');
        }
        if (array_key_exists('missing_field_keys', $metadata)) {
            $this->assertStringList($metadata['missing_field_keys'], 'metadata.missing_field_keys', 1, 5, 64);
        }
    }

    private function isInpatientDocumentationAction(string $action): bool
    {
        return in_array($action, [
            'clinical.inpatient.nursing.draft.save',
            'clinical.inpatient.nursing.finalize',
            'clinical.inpatient.medical.draft.save',
            'clinical.inpatient.medical.finalize',
        ], true);
    }

    /** @param array<string, mixed> $metadata */
    private function assertInpatientDischargeSummary(
        string $resourceType,
        ?string $resourceId,
        bool $actorPresent,
        string $outcome,
        ?string $reason,
        array $metadata,
    ): void {
        $this->assertActor($actorPresent);
        $this->assertPublicId($resourceId, 'resource_id');

        if ($outcome === 'SUCCESS' && $resourceType === 'inpatient_discharge_summary') {
            $this->assertNullReason($reason);
            $this->assertExactKeys($metadata, [
                'encounter_public_id', 'summary_state', 'summary_version', 'definition_version',
                'assigned_physician_user_public_id', 'location_sequence', 'ward_public_id', 'ward_code',
                'bed_public_id', 'bed_code', 'location_event_public_id', 'location_event_type',
                'history_baseline', 'history_complete', 'expected_version', 'payload_digest',
            ]);
            $this->assertPublicIdValue($metadata['encounter_public_id'], 'metadata.encounter_public_id');
            $this->assertEnum($metadata['summary_state'], [InpatientDischargeSummary::STATE_DRAFT, InpatientDischargeSummary::STATE_FINAL], 'metadata.summary_state');
            $this->assertPositiveInt($metadata['summary_version'], 'metadata.summary_version');
            $this->assertExactValue($metadata['definition_version'], InpatientDischargeSummary::DEFINITION_VERSION, 'metadata.definition_version');
            $this->assertPublicIdValue($metadata['assigned_physician_user_public_id'], 'metadata.assigned_physician_user_public_id');
            $this->assertNonNegativeInt($metadata['location_sequence'], 'metadata.location_sequence');
            $this->assertPublicIdValue($metadata['ward_public_id'], 'metadata.ward_public_id');
            $this->assertText($metadata['ward_code'], 'metadata.ward_code', 1, 64);
            $this->assertPublicIdValue($metadata['bed_public_id'], 'metadata.bed_public_id');
            $this->assertText($metadata['bed_code'], 'metadata.bed_code', 1, 64);
            $this->assertBool($metadata['history_complete'], 'metadata.history_complete');
            if ($metadata['location_sequence'] === 0) {
                $this->assertExactValue($metadata['location_event_public_id'], null, 'metadata.location_event_public_id');
                $this->assertExactValue($metadata['location_event_type'], null, 'metadata.location_event_type');
                $this->assertExactValue($metadata['history_baseline'], InpatientDischargeSummary::HISTORY_BASELINE_LEGACY_CURRENT_PLACEMENT, 'metadata.history_baseline');
                $this->assertExactValue($metadata['history_complete'], false, 'metadata.history_complete');
            } else {
                $this->assertPublicIdValue($metadata['location_event_public_id'], 'metadata.location_event_public_id');
                $this->assertEnum($metadata['location_event_type'], ['ADMISSION_LOCATION', 'BED_TRANSFER'], 'metadata.location_event_type');
                $this->assertExactValue($metadata['history_baseline'], null, 'metadata.history_baseline');
            }
            $this->assertNonNegativeInt($metadata['expected_version'], 'metadata.expected_version');
            $this->assertSha256($metadata['payload_digest'], 'metadata.payload_digest');

            return;
        }

        if ($outcome !== 'DENIED' || $resourceType !== 'encounter') {
            throw new InvalidAuditEvent('Inpatient discharge summary audit event tuple is not registered.');
        }
        $this->assertReasonIn($reason, [
            'validation_failed', 'encounter_missing', 'not_inpatient', 'encounter_cancelled', 'encounter_closed',
            'synthetic_only', 'placement_missing', 'placement_unmanaged', 'placement_stale', 'placement_inactive',
            'placement_history_missing', 'summary_missing', 'stale_version', 'summary_final',
            'physician_assignment_mismatch', 'idempotency_key_conflict', 'receipt_binding_invalid',
        ]);
        $allowed = [
            'definition_version', 'expected_version', 'current_version', 'invalid_field_key_digest',
            'invalid_field_key_digests', 'invalid_field_key_count', 'missing_field_keys',
        ];
        foreach (array_keys($metadata) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new InvalidAuditEvent('Inpatient discharge summary denial metadata contains an unregistered key.');
            }
        }
        $this->assertExactValue($metadata['definition_version'] ?? null, InpatientDischargeSummary::DEFINITION_VERSION, 'metadata.definition_version');
        foreach (['expected_version', 'current_version'] as $key) {
            if (array_key_exists($key, $metadata)) {
                $this->assertNonNegativeInt($metadata[$key], 'metadata.'.$key);
            }
        }
        if (array_key_exists('invalid_field_key_digest', $metadata)) {
            $this->assertSha256($metadata['invalid_field_key_digest'], 'metadata.invalid_field_key_digest');
        }
        if (array_key_exists('invalid_field_key_digests', $metadata)) {
            $digests = $this->assertStringList($metadata['invalid_field_key_digests'], 'metadata.invalid_field_key_digests', 1, 50, 64);
            foreach ($digests as $digest) {
                $this->assertSha256($digest, 'metadata.invalid_field_key_digests[]');
            }
            $this->assertPositiveInt($metadata['invalid_field_key_count'] ?? null, 'metadata.invalid_field_key_count');
        }
        if (array_key_exists('missing_field_keys', $metadata)) {
            $this->assertStringList($metadata['missing_field_keys'], 'metadata.missing_field_keys', 1, 5, 64);
        }
    }

    private function isInpatientDischargeSummaryAction(string $action): bool
    {
        return in_array($action, [
            'clinical.inpatient.discharge-summary.draft.save',
            'clinical.inpatient.discharge-summary.finalize',
        ], true);
    }

    /** @param array<string,mixed> $metadata */
    private function assertInpatientDischargeCodingSource(string $resourceType, ?string $resourceId, bool $actorPresent, string $outcome, ?string $reason, array $metadata): void
    {
        $this->assertActor($actorPresent);
        if ($outcome === 'SUCCESS' && $resourceType === 'inpatient_discharge_coding_source') {
            $this->assertPublicId($resourceId, 'resource_id');
            $this->assertNullReason($reason);
            $this->assertExactKeys($metadata, ['encounter_public_id', 'source_state', 'source_version', 'definition_version', 'assigned_physician_user_public_id', 'location_sequence', 'ward_public_id', 'ward_code', 'bed_public_id', 'bed_code', 'location_event_public_id', 'location_event_type', 'history_baseline', 'history_complete', 'expected_version', 'payload_digest']);
            $this->assertPublicIdValue($metadata['encounter_public_id'], 'metadata.encounter_public_id');
            $this->assertEnum($metadata['source_state'], [InpatientDischargeCodingSource::STATE_DRAFT, InpatientDischargeCodingSource::STATE_FINAL], 'metadata.source_state');
            $this->assertPositiveInt($metadata['source_version'], 'metadata.source_version');
            $this->assertExactValue($metadata['definition_version'], InpatientDischargeCodingSource::DEFINITION_VERSION, 'metadata.definition_version');
            $this->assertPublicIdValue($metadata['assigned_physician_user_public_id'], 'metadata.assigned_physician_user_public_id');
            $this->assertNonNegativeInt($metadata['location_sequence'], 'metadata.location_sequence');
            $this->assertPublicIdValue($metadata['ward_public_id'], 'metadata.ward_public_id');
            $this->assertText($metadata['ward_code'], 'metadata.ward_code', 1, 64);
            $this->assertPublicIdValue($metadata['bed_public_id'], 'metadata.bed_public_id');
            $this->assertText($metadata['bed_code'], 'metadata.bed_code', 1, 64);
            $this->assertBool($metadata['history_complete'], 'metadata.history_complete');
            if ($metadata['location_sequence'] === 0) {
                $this->assertExactValue($metadata['location_event_public_id'], null, 'metadata.location_event_public_id');
                $this->assertExactValue($metadata['location_event_type'], null, 'metadata.location_event_type');
                $this->assertExactValue($metadata['history_baseline'], InpatientDischargeSummary::HISTORY_BASELINE_LEGACY_CURRENT_PLACEMENT, 'metadata.history_baseline');
                $this->assertExactValue($metadata['history_complete'], false, 'metadata.history_complete');
            } else {
                $this->assertPublicIdValue($metadata['location_event_public_id'], 'metadata.location_event_public_id');
                $this->assertEnum($metadata['location_event_type'], ['ADMISSION_LOCATION', 'BED_TRANSFER'], 'metadata.location_event_type');
                $this->assertExactValue($metadata['history_baseline'], null, 'metadata.history_baseline');
            }
            $this->assertNonNegativeInt($metadata['expected_version'], 'metadata.expected_version');
            $this->assertSha256($metadata['payload_digest'], 'metadata.payload_digest');

            return;
        }
        if ($outcome !== 'DENIED' || ! in_array($resourceType, ['encounter', 'inpatient_discharge_coding_source'], true)) {
            throw new InvalidAuditEvent('Inpatient discharge coding source tuple is not registered.');
        }
        if ($reason === 'unauthorized_actor') {
            $this->assertExactValue($resourceId, null, 'resource_id');
            $this->assertExactKeys($metadata, []);

            return;
        }
        $this->assertPublicId($resourceId, 'resource_id');
        $this->assertReasonIn($reason, ['validation_failed', 'encounter_missing', 'not_inpatient', 'encounter_cancelled', 'encounter_closed', 'synthetic_only', 'placement_missing', 'placement_stale', 'placement_inactive', 'discharge_summary_missing', 'physician_assignment_mismatch', 'author_mismatch', 'source_missing', 'stale_version', 'source_final', 'idempotency_key_conflict', 'receipt_binding_invalid', 'concurrent_change', 'persistence_unavailable']);
        foreach (array_keys($metadata) as $key) {
            if (! in_array($key, ['expected_version', 'current_version'], true)) {
                throw new InvalidAuditEvent('Inpatient discharge coding source denial metadata contains an unregistered key.');
            }
        }
    }

    private function isInpatientDischargeCodingSourceAction(string $action): bool
    {
        return in_array($action, ['clinical.inpatient.discharge-coding-source.draft.save', 'clinical.inpatient.discharge-coding-source.finalize'], true);
    }

    private function assertSha256(mixed $value, string $path): void
    {
        if (! is_string($value) || preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw new InvalidAuditEvent("Audit {$path} must be a lowercase SHA-256 digest.");
        }
    }

    private function assertActor(bool $actorPresent): void
    {
        if (! $actorPresent) {
            throw new InvalidAuditEvent('Audit event requires an attributed actor.');
        }
    }

    private function assertPublicId(?string $value, string $path): void
    {
        $this->assertPublicIdValue($value, $path);
    }

    private function assertPublicIdValue(mixed $value, string $path): void
    {
        if (! is_string($value) || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $value) !== 1) {
            throw new InvalidAuditEvent("Audit {$path} must be a ULID.");
        }
    }

    private function assertNullablePublicId(mixed $value, string $path): void
    {
        if ($value !== null) {
            $this->assertPublicIdValue($value, $path);
        }
    }

    private function assertNullableSha256(mixed $value, string $path): void
    {
        if ($value !== null) {
            $this->assertSha256($value, $path);
        }
    }

    private function assertRouteName(?string $value): void
    {
        if ($value === null || mb_strlen($value) > 255 || preg_match('/\A[A-Za-z0-9._:-]+\z/', $value) !== 1) {
            throw new InvalidAuditEvent('Authorization audit resource_id must be a bounded route name.');
        }
    }

    private function assertNullReason(?string $reason): void
    {
        if ($reason !== null) {
            throw new InvalidAuditEvent('Audit reason must be null for this event.');
        }
    }

    /** @param array<string,mixed> $metadata */
    private function assertInpatientSummaryAddendumDenial(
        ?string $resourceId,
        bool $actorPresent,
        ?string $reason,
        array $metadata,
        bool $requestExpected,
    ): void {
        $this->assertActor($actorPresent);
        $this->assertPublicId($resourceId, 'resource_id');
        $this->assertExactKeys($metadata, ['encounter_public_id', 'request_public_id']);
        if ($reason === 'unauthorized_actor') {
            $this->assertNullablePublicId($metadata['encounter_public_id'], 'metadata.encounter_public_id');
        } else {
            $this->assertPublicIdValue($metadata['encounter_public_id'], 'metadata.encounter_public_id');
        }
        $this->assertNullablePublicId($metadata['request_public_id'], 'metadata.request_public_id');
        $this->assertReasonIn($reason, [
            'unauthorized_actor', 'validation_failed', 'request_note_required', 'encounter_not_closed',
            'baseline_invalid', 'active_request_exists', 'request_not_submitted', 'self_decision_forbidden',
            'decision_note_required', 'request_not_authorized', 'stale_addendum_version', 'addendum_empty',
            'stale_review_version', 'source_binding_stale', 'review_not_current_complete',
            'checklist_incomplete', 'idempotency_key_conflict', 'receipt_binding_invalid',
            'concurrent_change',
        ]);
        if ($requestExpected && $metadata['request_public_id'] !== $resourceId) {
            throw new InvalidAuditEvent('Summary addendum denial request reference must match resource_id.');
        }
        if (! $requestExpected && $metadata['request_public_id'] !== null) {
            throw new InvalidAuditEvent('Summary addendum submit denial must not claim a request.');
        }
    }

    private function assertExactReason(?string $reason, string $expected): void
    {
        if ($reason !== $expected) {
            throw new InvalidAuditEvent("Audit reason must be [{$expected}].");
        }
    }

    /** @param array<string, mixed> $metadata */
    private function assertEmergencyOperation(array $metadata): void
    {
        $this->assertExactKeys($metadata, ['operation']);

        if (! is_string($metadata['operation']) || ! in_array($metadata['operation'], self::EMERGENCY_OPERATIONS, true)) {
            throw new InvalidAuditEvent('Emergency operation is not registered.');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function assertWarehouseSuccess(string $resourceId, array $metadata): void
    {
        $operation = $metadata['operation'] ?? null;
        if (! is_string($operation) || ! array_key_exists($operation, self::WAREHOUSE_SUCCESS_CONTRACTS)) {
            throw new InvalidAuditEvent('Warehouse success operation is not allowed.');
        }

        $keys = [
            'operation', 'entity_type', 'state', 'version',
            'result_public_id', 'operation_receipt_public_id', 'result_content_digest',
        ];

        if (in_array($operation, ['WAREHOUSE_SUPPLIER_CREATE', 'WAREHOUSE_SUPPLIER_REVISE', 'WAREHOUSE_SUPPLIER_RETIRE'], true)) {
            $keys[] = 'supplier_public_id';
        }

        if (in_array($operation, [
            'WAREHOUSE_PURCHASE_ORDER_CREATE', 'WAREHOUSE_PURCHASE_ORDER_REVISE', 'WAREHOUSE_PURCHASE_ORDER_SUBMIT',
            'WAREHOUSE_PURCHASE_ORDER_REVIEW', 'WAREHOUSE_RECEIPT_RECORD',
        ], true)) {
            array_push($keys, 'supplier_public_id', 'purchase_order_public_id', 'purchase_order_fingerprint');
        }

        if (in_array($operation, ['WAREHOUSE_SUPPLIER_RETURN_REQUEST', 'WAREHOUSE_SUPPLIER_RETURN_REVIEW'], true)) {
            $keys[] = 'supplier_public_id';
        }

        if (in_array($operation, [
            'WAREHOUSE_RECEIPT_RECORD', 'WAREHOUSE_TRANSFER_DISPATCH', 'WAREHOUSE_TRANSFER_REVIEW',
            'WAREHOUSE_SUPPLIER_RETURN_REVIEW', 'WAREHOUSE_UNIT_RETURN_REQUEST',
            'WAREHOUSE_UNIT_RETURN_REVIEW', 'WAREHOUSE_CORRECTION_COMPENSATE',
        ], true)) {
            array_push($keys, 'before_total_quantity', 'after_total_quantity', 'before_total_value', 'after_total_value');
        }

        $this->assertExactKeys($metadata, $keys);

        $contract = self::WAREHOUSE_SUCCESS_CONTRACTS[$operation];
        $this->assertExactValue($metadata['entity_type'], $contract['entity'], 'metadata.entity_type');
        if (! is_string($metadata['state']) || ! in_array($metadata['state'], $contract['states'], true)) {
            throw new InvalidAuditEvent('Warehouse success operation/state pair is not allowed.');
        }
        $this->assertPositiveInt($metadata['version'], 'metadata.version');
        $this->assertPublicIdValue($metadata['result_public_id'], 'metadata.result_public_id');
        $this->assertPublicIdValue($metadata['operation_receipt_public_id'], 'metadata.operation_receipt_public_id');
        $this->assertSha256($metadata['result_content_digest'], 'metadata.result_content_digest');

        if (array_key_exists('supplier_public_id', $metadata)) {
            $this->assertPublicIdValue($metadata['supplier_public_id'], 'metadata.supplier_public_id');
        }
        if (array_key_exists('purchase_order_public_id', $metadata)) {
            $this->assertPublicIdValue($metadata['purchase_order_public_id'], 'metadata.purchase_order_public_id');
            $this->assertSha256($metadata['purchase_order_fingerprint'], 'metadata.purchase_order_fingerprint');
        }
        foreach (['before_total_quantity', 'after_total_quantity', 'before_total_value', 'after_total_value'] as $total) {
            if (array_key_exists($total, $metadata)) {
                $this->assertNonNegativeInt($metadata[$total], 'metadata.'.$total);
            }
        }

        if ($metadata['result_public_id'] !== $resourceId
            || ($contract['entity'] === 'SUPPLIER' && $metadata['supplier_public_id'] !== $resourceId)
            || ($contract['entity'] === 'PURCHASE_ORDER' && $metadata['purchase_order_public_id'] !== $resourceId)) {
            throw new InvalidAuditEvent('Warehouse success resource binding does not match its entity public ID.');
        }
    }

    /** @param list<string> $allowed */
    private function assertReasonIn(?string $reason, array $allowed): void
    {
        if ($reason === null || ! in_array($reason, $allowed, true)) {
            throw new InvalidAuditEvent('Audit reason is not registered for this event.');
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $expected
     */
    private function assertExactKeys(array $metadata, array $expected): void
    {
        $actual = array_keys($metadata);
        sort($actual);
        sort($expected);

        if ($actual !== $expected) {
            throw new InvalidAuditEvent('Audit metadata keys do not match the registered schema.');
        }
    }

    /** @return array<string, mixed> */
    private function assertMap(mixed $value, string $path): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidAuditEvent("Audit {$path} must be an object-shaped map.");
        }

        return $value;
    }

    private function assertText(mixed $value, string $path, int $minimum, int $maximum): void
    {
        if (! is_string($value) || mb_strlen($value) < $minimum || mb_strlen($value) > $maximum) {
            throw new InvalidAuditEvent("Audit {$path} must be a bounded string.");
        }
    }

    private function assertNullableText(mixed $value, string $path, int $maximum): void
    {
        if ($value !== null) {
            $this->assertText($value, $path, 1, $maximum);
        }
    }

    /** @param list<string> $allowed */
    private function assertEnum(mixed $value, array $allowed, string $path): void
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new InvalidAuditEvent("Audit {$path} is not an allowed value.");
        }
    }

    private function assertExactValue(mixed $value, mixed $expected, string $path): void
    {
        if ($value !== $expected) {
            throw new InvalidAuditEvent("Audit {$path} does not match the registered value.");
        }
    }

    private function assertBool(mixed $value, string $path): void
    {
        if (! is_bool($value)) {
            throw new InvalidAuditEvent("Audit {$path} must be a boolean.");
        }
    }

    private function assertNonNegativeInt(mixed $value, string $path): void
    {
        if (! is_int($value) || $value < 0) {
            throw new InvalidAuditEvent("Audit {$path} must be a non-negative integer.");
        }
    }

    private function assertPositiveInt(mixed $value, string $path): void
    {
        if (! is_int($value) || $value < 1) {
            throw new InvalidAuditEvent("Audit {$path} must be a positive integer.");
        }
    }

    private function assertNullablePositiveInt(mixed $value, string $path): void
    {
        if ($value !== null) {
            $this->assertPositiveInt($value, $path);
        }
    }

    private function assertStatus(mixed $value, string $path): void
    {
        if (! is_string($value) || preg_match('/\A[A-Z][A-Z0-9_]{0,31}\z/', $value) !== 1) {
            throw new InvalidAuditEvent("Audit {$path} must be a bounded status code.");
        }
    }

    private function assertDate(mixed $value, string $path): void
    {
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidAuditEvent("Audit {$path} must be a valid YYYY-MM-DD date.");
        }
    }

    /** @return list<string> */
    private function assertStringList(mixed $value, string $path, int $minimum, int $maximum, int $maxStringLength): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) < $minimum || count($value) > $maximum) {
            throw new InvalidAuditEvent("Audit {$path} must be a bounded list.");
        }

        foreach ($value as $item) {
            $this->assertText($item, $path.'[]', 1, $maxStringLength);
        }

        return $value;
    }
}
