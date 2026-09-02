<?php

namespace Tests\Unit\Audit;

use App\Models\ClinicalEntry;
use App\Models\Encounter;
use App\Models\LabDiagnosticResult;
use App\Models\OutpatientClinicalDocument;
use App\Models\OutpatientRmCompletenessReview;
use App\Support\Audit\AuditEventSchemaRegistry;
use App\Support\Audit\InvalidAuditEvent;
use App\Support\Clinical\OutpatientDocumentationDefinition;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AuditEventSchemaRegistryTest extends TestCase
{
    private const ULID = '01J00000000000000000000000';

    /**
     * @param array{
     *   action: string,
     *   resourceType: string,
     *   resourceId: string|null,
     *   actorPresent: bool,
     *   outcome: string,
     *   reason: string|null,
     *   metadata: array<string, mixed>
     * } $event
     */
    #[DataProvider('productionEvents')]
    public function test_it_accepts_every_registered_production_variant(array $event): void
    {
        (new AuditEventSchemaRegistry)->assertAllows(...$event);

        $this->addToAssertionCount(1);
    }

    /**
     * @param array{
     *   action: string,
     *   resourceType: string,
     *   resourceId: string|null,
     *   actorPresent: bool,
     *   outcome: string,
     *   reason: string|null,
     *   metadata: array<string, mixed>
     * } $event
     */
    #[DataProvider('invalidEvents')]
    public function test_it_rejects_unregistered_or_malformed_events(array $event): void
    {
        $this->expectException(InvalidAuditEvent::class);

        (new AuditEventSchemaRegistry)->assertAllows(...$event);
    }

    public function test_document_audit_prefix_rejects_unknown_types_instead_of_falling_back_to_medical(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OutpatientDocumentationDefinition::auditPrefix('UNREGISTERED_DOCUMENT_TYPE');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function productionEvents(): iterable
    {
        yield 'finance tariff group creation' => [self::event(
            'finance.tariff.mutate',
            'finance_tariff_record',
            true,
            metadata: [
                'operation' => 'FINANCE_COST_COMPONENT_GROUP_CREATE',
                'entity_type' => 'COST_COMPONENT_GROUP',
                'state' => 'ACTIVE',
                'version' => 1,
                'effective_from' => null,
                'replayed' => false,
                'future_activation' => false,
            ],
        )];
        yield 'finance tariff future item revision replay' => [self::event(
            'finance.tariff.mutate',
            'finance_tariff_record',
            true,
            metadata: [
                'operation' => 'FINANCE_TARIFF_ITEM_APPEND_VERSION',
                'entity_type' => 'TARIFF_ITEM',
                'state' => 'ACTIVE',
                'version' => 2,
                'effective_from' => '2026-10-01',
                'replayed' => true,
                'future_activation' => true,
            ],
        )];
        yield 'finance tariff item retirement' => [self::event(
            'finance.tariff.mutate',
            'finance_tariff_record',
            true,
            metadata: [
                'operation' => 'FINANCE_TARIFF_ITEM_RETIRE',
                'entity_type' => 'TARIFF_ITEM',
                'state' => 'RETIRED',
                'version' => 3,
                'effective_from' => '2026-11-01',
                'replayed' => false,
                'future_activation' => true,
            ],
        )];
        yield 'finance radiology tariff binding future revision' => [self::event(
            'finance.tariff.mutate',
            'finance_tariff_record',
            true,
            metadata: [
                'operation' => 'FINANCE_RADIOLOGY_TARIFF_BINDING_APPEND_VERSION',
                'entity_type' => 'RADIOLOGY_TARIFF_BINDING',
                'state' => 'ACTIVE',
                'version' => 2,
                'effective_from' => '2026-10-01',
                'replayed' => false,
                'future_activation' => true,
                'care_setting' => 'OUTPATIENT',
            ],
        )];
        yield 'finance radiology tariff binding integrity denial' => [self::event(
            'finance.tariff.mutate',
            'finance_tariff_record',
            true,
            resourceId: null,
            outcome: 'DENIED',
            reason: 'source_integrity_failure',
            metadata: [
                'operation' => 'FINANCE_RADIOLOGY_TARIFF_BINDING_CREATE',
                'entity_type' => 'RADIOLOGY_TARIFF_BINDING',
                'care_setting' => 'INPATIENT',
            ],
        )];
        yield 'finance laboratory tariff binding future revision' => [self::event(
            'finance.tariff.mutate',
            'finance_tariff_record',
            true,
            metadata: [
                'operation' => 'FINANCE_LABORATORY_TARIFF_BINDING_APPEND_VERSION',
                'entity_type' => 'LABORATORY_TARIFF_BINDING',
                'state' => 'ACTIVE',
                'version' => 2,
                'effective_from' => '2026-10-01',
                'replayed' => false,
                'future_activation' => true,
                'care_setting' => 'EMERGENCY',
            ],
        )];
        foreach ([
            'role_not_permitted', 'resource_not_found', 'validation_failed',
            'stale_version', 'stale_digest', 'master_retired',
            'active_components_remain', 'dependent_tariffs_remain', 'upstream_inactive',
            'effective_date_not_after_latest', 'retroactive_effective_date',
            'binding_retired', 'master_version_mismatch', 'tariff_not_effective',
            'source_binding_invalid',
            'idempotency_key_conflict', 'receipt_corrupt',
            'concurrent_state_conflict',
        ] as $reason) {
            yield 'finance tariff denial '.$reason => [self::event(
                'finance.tariff.mutate',
                'finance_tariff_record',
                true,
                resourceId: null,
                outcome: 'DENIED',
                reason: $reason,
                metadata: [
                    'operation' => 'FINANCE_TARIFF_ITEM_APPEND_VERSION',
                    'entity_type' => 'TARIFF_ITEM',
                ],
            )];
        }

        yield 'finance source sync before first version' => [self::event(
            'finance.workflow.mutate',
            'finance_record',
            true,
            metadata: ['operation' => 'FINANCE_SOURCE_SYNC', 'state' => 'OPEN_NO_VERSION', 'version' => 0],
        )];
        yield 'finance source sync after issue' => [self::event(
            'finance.workflow.mutate',
            'finance_record',
            true,
            metadata: ['operation' => 'FINANCE_SOURCE_SYNC', 'state' => 'NEW_SOURCE_PENDING', 'version' => 2],
        )];
        yield 'finance bill issue' => [self::event(
            'finance.workflow.mutate',
            'finance_record',
            true,
            metadata: ['operation' => 'FINANCE_BILL_ISSUE', 'state' => 'ISSUED_CURRENT', 'version' => 1],
        )];
        yield 'finance exact cash settlement bound to immutable result' => [self::event(
            'finance.workflow.mutate',
            'finance_record',
            true,
            metadata: [
                'operation' => 'FINANCE_CASH_SETTLEMENT',
                'state' => 'ISSUED_CURRENT',
                'version' => 1,
                'settlement_public_id' => self::ULID,
                'settlement_content_digest' => str_repeat('a', 64),
                'settlement_result_digest' => str_repeat('b', 64),
            ],
        )];
        yield 'finance denial before lookup' => [self::event(
            'finance.workflow.mutate',
            'finance_record',
            true,
            resourceId: null,
            outcome: 'DENIED',
            reason: 'role_not_permitted',
            metadata: ['operation' => 'FINANCE_BILL_ISSUE'],
        )];
        yield 'finance issue denial for unresolved radiology source' => [self::event(
            'finance.workflow.mutate',
            'finance_record',
            true,
            resourceId: null,
            outcome: 'DENIED',
            reason: 'unresolved_radiology_source',
            metadata: ['operation' => 'FINANCE_BILL_ISSUE'],
        )];
        yield 'finance issue denial for unresolved laboratory source' => [self::event(
            'finance.workflow.mutate',
            'finance_record',
            true,
            resourceId: null,
            outcome: 'DENIED',
            reason: 'unresolved_laboratory_source',
            metadata: ['operation' => 'FINANCE_BILL_ISSUE'],
        )];

        yield 'rebuild admin reconciliation' => [self::event(
            'authorization.rebuild_admin.reconciled',
            'user',
            false,
            metadata: [
                'before' => ['roles' => ['admin', 'nurse', 'physician', 'registrar', 'rmik'], 'status' => 'ACTIVE'],
                'after' => ['roles' => ['admin'], 'status' => 'ACTIVE'],
                'sessions_revoked' => 1,
                'operator' => 'Daniel UEU',
                'reason' => 'Contain synthetic role drift.',
                'disable_requested' => false,
                'roles_changed' => true,
                'status_changed' => false,
                'mutated' => true,
                'system_admin_bypass_remains' => true,
                'note' => 'is_system_administrator remains true; the system-admin capability bypass remains active.',
            ],
            reason: 'Contain synthetic role drift.',
        )];

        yield 'teaching reset started' => [self::event(
            'teaching.reset.started',
            'simulation',
            false,
            resourceId: 'synthetic-reset',
            metadata: [
                'boundary' => 'synthetic_patient_graph',
                'evidence_preserved' => true,
                'queue_counter_high_water_preserved' => true,
                'reset_correlation_id' => self::ULID,
                'collection_batch_count' => 1,
                'collection_active_slot_count' => 1,
                'collection_member_count' => 2,
                'collection_event_count' => 0,
                'collection_handoff_count' => 0,
                'collection_operation_receipt_count' => 3,
                'collection_active_slot_digest' => str_repeat('a', 64),
                'collection_operation_receipt_digest' => str_repeat('b', 64),
                'collection_evidence_digest' => str_repeat('c', 64),
            ],
            reason: 'artisan_simulation_reset',
        )];
        yield 'teaching reset completed' => [self::event(
            'teaching.reset.completed',
            'simulation',
            false,
            resourceId: 'synthetic-reset',
            metadata: [
                'boundary' => 'synthetic_patient_graph',
                'deleted_patients' => 2,
                'evidence_preserved' => true,
                'queue_counter_high_water_preserved' => true,
                'reset_correlation_id' => self::ULID,
                'collection_batch_count' => 1,
                'collection_active_slot_count' => 1,
                'collection_member_count' => 2,
                'collection_event_count' => 0,
                'collection_handoff_count' => 0,
                'collection_operation_receipt_count' => 3,
                'collection_active_slot_digest' => str_repeat('a', 64),
                'collection_operation_receipt_digest' => str_repeat('b', 64),
                'collection_evidence_digest' => str_repeat('c', 64),
            ],
            reason: 'artisan_simulation_reset',
        )];

        yield 'outpatient registration' => [self::event('patient.register', 'encounter', true, metadata: [
            'patient_public_id' => self::ULID,
            'clinic_name' => 'Poli Penyakit Dalam',
            'doctor_name' => 'dr. Synthetic',
            'schedule_label' => 'Senin pagi',
            'payer_type' => Encounter::PAYER_UMUM,
            'queue_date' => '2026-08-26',
            'queue_number' => 1,
        ])];
        yield 'emergency registration' => [self::event('patient.register', 'encounter', true, metadata: [
            'care_setting' => Encounter::CARE_SETTING_EMERGENCY,
            'patient_public_id' => self::ULID,
            'clinic_name' => 'IGD',
            'doctor_name' => 'dr. Synthetic',
            'schedule_label' => '24 jam',
            'payer_type' => Encounter::PAYER_UMUM,
            'case_type' => Encounter::CASE_NON_BEDAH,
            'accident_type' => Encounter::ACCIDENT_NONE,
            'queue_date' => '2026-08-26',
            'queue_number' => 1,
        ])];
        yield 'inpatient registration' => [self::event('patient.register', 'encounter', true, metadata: [
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'patient_public_id' => self::ULID,
            'ward_name' => 'Ward Synthetic',
            'ward_class' => 'Kelas I',
            'bed_code' => 'A-01',
            'continue_from' => Encounter::CONTINUE_LANGSUNG,
            'payer_type' => Encounter::PAYER_UMUM,
            'queue_date' => '2026-08-26',
            'queue_number' => 1,
        ])];

        foreach ([Encounter::CARE_SETTING_EMERGENCY, Encounter::CARE_SETTING_INPATIENT] as $setting) {
            yield 'clinical note '.$setting => [self::event('clinical.note.write', 'encounter', true, metadata: [
                'care_setting' => $setting,
                'entry_type' => ClinicalEntry::TYPE_MEDICAL_ASSESSMENT,
            ])];
        }

        yield 'teaching print' => [self::event('encounter.print', 'encounter', true, metadata: [
            'documents' => ['bukti', 'sep'],
            'teaching_only' => true,
            'live_bpjs' => false,
        ])];
        yield 'authorization denial' => [self::event(
            'authorization.denied',
            'http_route',
            true,
            resourceId: 'pemeriksaan.laboratorium.results.store',
            outcome: 'DENIED',
            reason: 'authorization_check_failed',
            metadata: ['http_method' => 'POST', 'http_status' => 403],
        )];

        foreach (self::documentationActions() as $action => [$documentType, $state]) {
            yield $action.' success' => [self::event($action, 'outpatient_clinical_document', true, metadata: [
                'encounter_id' => self::ULID,
                'document_type' => $documentType,
                'version' => 1,
                'author_user_public_id' => self::ULID,
                'document_state' => $state,
            ])];
        }

        yield 'nursing draft denial' => [self::documentationDenial('clinical.nursing.draft.save', OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT, 'encounter_closed')];
        yield 'nursing final denial' => [self::documentationDenial('clinical.nursing.finalize', OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT, 'document_missing')];
        yield 'medical draft validation denial' => [self::documentationDenial(
            'clinical.medical.draft.save',
            OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT,
            'validation_failed',
            [
                'invalid_field_key_digests' => [hash('sha256', 'string:unsupported_field')],
                'invalid_field_key_count' => 1,
            ],
        )];
        yield 'medical final stale denial' => [self::documentationDenial(
            'clinical.medical.finalize',
            OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT,
            'stale_version',
            ['expected_version' => 1, 'current_version' => 2],
        )];
        yield 'author mismatch denial' => [self::documentationDenial('clinical.medical.draft.save', OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT, 'author_mismatch')];
        yield 'document final denial' => [self::documentationDenial('clinical.medical.finalize', OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT, 'document_final')];
        yield 'missing fields denial' => [self::documentationDenial(
            'clinical.medical.finalize',
            OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT,
            'validation_failed',
            ['missing_field_keys' => ['anamnesis']],
        )];

        yield 'lab order success' => [self::event('clinical.lab.order.create', 'lab_service_request', true, metadata: [
            'encounter_id' => self::ULID,
            'test_code' => 'HB',
        ])];
        yield 'lab order denial' => [self::event(
            'clinical.lab.order.create',
            'encounter',
            true,
            outcome: 'DENIED',
            reason: 'encounter_closed',
            metadata: ['test_code' => 'HB'],
        )];
        yield 'lab result success' => [self::event('clinical.lab.result.write', 'lab_service_request', true, metadata: [
            'encounter_id' => self::ULID,
            'test_code' => 'HB',
            'result_status' => LabDiagnosticResult::STATUS_FINAL,
        ])];
        foreach (['encounter_closed', 'result_already_final', 'order_not_active'] as $reason) {
            yield 'lab result denial '.$reason => [self::event(
                'clinical.lab.result.write',
                'lab_service_request',
                true,
                outcome: 'DENIED',
                reason: $reason,
                metadata: ['encounter_id' => self::ULID],
            )];
        }

        yield 'completeness review success' => [self::completenessSuccess('rmik.completeness.review.save', ['NURSING_FINAL'])];
        yield 'completeness signoff success' => [self::completenessSuccess('rmik.completeness.signoff', [])];
        foreach (['encounter_not_ready', 'stale_version', 'source_stale'] as $reason) {
            yield 'review denial '.$reason => [self::completenessDenial('rmik.completeness.review.save', $reason)];
            yield 'signoff denial '.$reason => [self::completenessDenial('rmik.completeness.signoff', $reason)];
        }
        yield 'signoff active lab denial' => [self::completenessDenial('rmik.completeness.signoff', 'active_lab_orders', ['failed_item_ids' => ['NO_ACTIVE_LAB_ORDERS']])];
        yield 'signoff checklist denial' => [self::completenessDenial('rmik.completeness.signoff', 'checklist_incomplete', ['failed_item_ids' => ['MEDICAL_FINAL']])];
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidEvents(): iterable
    {
        yield 'finance tariff operation entity pair is closed' => [self::event(
            'finance.tariff.mutate',
            'finance_tariff_record',
            true,
            metadata: [
                'operation' => 'FINANCE_COST_COMPONENT_CREATE',
                'entity_type' => 'TARIFF_ITEM',
                'state' => 'ACTIVE',
                'version' => 1,
                'effective_from' => null,
                'replayed' => false,
                'future_activation' => false,
            ],
        )];
        yield 'finance tariff non-item cannot be future effective' => [self::event(
            'finance.tariff.mutate',
            'finance_tariff_record',
            true,
            metadata: [
                'operation' => 'FINANCE_TARIFF_CATALOGUE_REVISE',
                'entity_type' => 'TARIFF_CATALOGUE',
                'state' => 'ACTIVE',
                'version' => 2,
                'effective_from' => '2026-10-01',
                'replayed' => false,
                'future_activation' => true,
            ],
        )];
        yield 'finance tariff denial reason is closed' => [self::event(
            'finance.tariff.mutate',
            'finance_tariff_record',
            true,
            outcome: 'DENIED',
            reason: 'invented_tariff_reason',
            metadata: [
                'operation' => 'FINANCE_TARIFF_ITEM_APPEND_VERSION',
                'entity_type' => 'TARIFF_ITEM',
            ],
        )];

        yield 'finance issue cannot use version zero' => [self::event(
            'finance.workflow.mutate',
            'finance_record',
            true,
            metadata: ['operation' => 'FINANCE_BILL_ISSUE', 'state' => 'ISSUED_CURRENT', 'version' => 0],
        )];
        yield 'finance pending state cannot use version zero' => [self::event(
            'finance.workflow.mutate',
            'finance_record',
            true,
            metadata: ['operation' => 'FINANCE_SOURCE_SYNC', 'state' => 'NEW_SOURCE_PENDING', 'version' => 0],
        )];
        yield 'finance cash settlement cannot omit immutable result binding' => [self::event(
            'finance.workflow.mutate',
            'finance_record',
            true,
            metadata: ['operation' => 'FINANCE_CASH_SETTLEMENT', 'state' => 'ISSUED_CURRENT', 'version' => 1],
        )];
        yield 'finance denial reason must be closed' => [self::event(
            'finance.workflow.mutate',
            'finance_record',
            true,
            outcome: 'DENIED',
            reason: 'invented_finance_reason',
            metadata: ['operation' => 'FINANCE_SOURCE_SYNC'],
        )];

        yield 'unknown action' => [self::event('unknown.action', 'encounter', true)];
        yield 'wrong tuple resource' => [self::event('patient.register', 'patient', true)];
        yield 'invalid public id' => [self::event('clinical.note.write', 'encounter', true, resourceId: 'internal-123', metadata: [
            'care_setting' => Encounter::CARE_SETTING_EMERGENCY,
            'entry_type' => ClinicalEntry::TYPE_NURSING_INTAKE,
        ])];
        yield 'missing actor' => [self::event('clinical.note.write', 'encounter', false, metadata: [
            'care_setting' => Encounter::CARE_SETTING_EMERGENCY,
            'entry_type' => ClinicalEntry::TYPE_NURSING_INTAKE,
        ])];
        yield 'extra key' => [self::event('encounter.print', 'encounter', true, metadata: [
            'documents' => ['bukti'], 'teaching_only' => true, 'live_bpjs' => false, 'extra' => true,
        ])];
        yield 'wrong scalar type' => [self::event('patient.register', 'encounter', true, metadata: [
            'patient_public_id' => self::ULID,
            'clinic_name' => 'Poli',
            'doctor_name' => null,
            'schedule_label' => null,
            'payer_type' => Encounter::PAYER_UMUM,
            'queue_date' => '2026-08-26',
            'queue_number' => '1',
        ])];
        yield 'invalid queue date' => [self::event('patient.register', 'encounter', true, metadata: [
            'patient_public_id' => self::ULID,
            'clinic_name' => 'Poli',
            'doctor_name' => null,
            'schedule_label' => null,
            'payer_type' => Encounter::PAYER_UMUM,
            'queue_date' => '2026-02-30',
            'queue_number' => 1,
        ])];
        yield 'wrong enum' => [self::event('clinical.note.write', 'encounter', true, metadata: [
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'entry_type' => ClinicalEntry::TYPE_NURSING_INTAKE,
        ])];
        yield 'wrong reason' => [self::event(
            'authorization.denied',
            'http_route',
            true,
            resourceId: 'protected.route',
            outcome: 'DENIED',
            reason: 'anything_else',
            metadata: ['http_method' => 'GET', 'http_status' => 403],
        )];
        yield 'associative list' => [self::completenessSuccess('rmik.completeness.review.save', ['NURSING_FINAL' => 'NURSING_FINAL'])];
        yield 'unexpected nested role' => [self::event(
            'authorization.rebuild_admin.reconciled',
            'user',
            false,
            metadata: [
                'before' => ['roles' => ['admin', 'unknown'], 'status' => 'ACTIVE'],
                'after' => ['roles' => ['admin'], 'status' => 'ACTIVE'],
                'sessions_revoked' => 0,
                'operator' => 'Daniel UEU',
                'reason' => 'Contain synthetic role drift.',
                'disable_requested' => false,
                'roles_changed' => true,
                'status_changed' => false,
                'mutated' => true,
                'system_admin_bypass_remains' => true,
                'note' => 'is_system_administrator remains true; the system-admin capability bypass remains active.',
            ],
            reason: 'Contain synthetic role drift.',
        )];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{action: string, resourceType: string, resourceId: string|null, actorPresent: bool, outcome: string, reason: string|null, metadata: array<string, mixed>}
     */
    private static function event(
        string $action,
        string $resourceType,
        bool $actorPresent,
        ?string $resourceId = self::ULID,
        string $outcome = 'SUCCESS',
        ?string $reason = null,
        array $metadata = [],
    ): array {
        return compact('action', 'resourceType', 'resourceId', 'actorPresent', 'outcome', 'reason', 'metadata');
    }

    /** @return array<string, array{string, string}> */
    private static function documentationActions(): array
    {
        return [
            'clinical.nursing.draft.save' => [OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT, OutpatientClinicalDocument::STATE_DRAFT],
            'clinical.nursing.finalize' => [OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT, OutpatientClinicalDocument::STATE_FINAL],
            'clinical.medical.draft.save' => [OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT, OutpatientClinicalDocument::STATE_DRAFT],
            'clinical.medical.finalize' => [OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT, OutpatientClinicalDocument::STATE_FINAL],
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{action: string, resourceType: string, resourceId: string|null, actorPresent: bool, outcome: string, reason: string|null, metadata: array<string, mixed>}
     */
    private static function documentationDenial(string $action, string $documentType, string $reason, array $extra = []): array
    {
        return self::event(
            $action,
            'encounter',
            true,
            outcome: 'DENIED',
            reason: $reason,
            metadata: array_merge(['document_type' => $documentType], $extra),
        );
    }

    /**
     * @param  array<array-key, string>  $failedItems
     * @return array{action: string, resourceType: string, resourceId: string|null, actorPresent: bool, outcome: string, reason: string|null, metadata: array<string, mixed>}
     */
    private static function completenessSuccess(string $action, array $failedItems): array
    {
        return self::event($action, 'outpatient_rm_completeness_review', true, metadata: [
            'encounter_id' => self::ULID,
            'review_version' => 1,
            'definition_version' => OutpatientRmCompletenessReview::DEFINITION_VERSION,
            'source_fingerprint' => str_repeat('a', 64),
            'failed_item_ids' => $failedItems,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{action: string, resourceType: string, resourceId: string|null, actorPresent: bool, outcome: string, reason: string|null, metadata: array<string, mixed>}
     */
    private static function completenessDenial(string $action, string $reason, array $metadata = []): array
    {
        return self::event($action, 'encounter', true, outcome: 'DENIED', reason: $reason, metadata: $metadata);
    }
}
