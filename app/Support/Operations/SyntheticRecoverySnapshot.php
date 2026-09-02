<?php

namespace App\Support\Operations;

use App\Models\Encounter;
use App\Models\FinanceBill;
use App\Models\FinanceBillVersion;
use App\Models\FinanceCashDepositHandoff;
use App\Models\FinanceCashierCollectionBatch;
use App\Models\FinanceCashierCollectionEvent;
use App\Models\FinanceCashierCollectionMember;
use App\Models\FinanceCashierCollectionOperationReceipt;
use App\Models\FinanceCashSettlement;
use App\Models\FinanceChargeEvent;
use App\Models\FinanceOperationReceipt;
use App\Models\FinanceSettlementCorrectionCase;
use App\Models\FinanceSettlementCorrectionEvent;
use App\Models\FinanceSettlementCorrectionOperationReceipt;
use App\Models\FinanceSettlementOperationReceipt;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use App\Support\Database\SchemaQualifier;
use App\Support\Finance\FinanceAccommodationSourceAdapter;
use App\Support\Finance\FinanceAccommodationTariffContentDigest;
use App\Support\Finance\FinanceCanonicalJson;
use App\Support\Finance\FinanceCashierCollectionFingerprint;
use App\Support\Finance\FinanceCashierCollectionService;
use App\Support\Finance\FinanceCashSettlementCorrectionFingerprint;
use App\Support\Finance\FinanceCashSettlementCorrectionService;
use App\Support\Finance\FinanceCashSettlementFingerprint;
use App\Support\Finance\FinanceCashSettlementNetPolicy;
use App\Support\Finance\FinanceCashSettlementService;
use App\Support\Finance\FinanceDenied;
use App\Support\Finance\FinanceEvidenceFingerprint;
use App\Support\Finance\FinanceLaboratorySourceAdapter;
use App\Support\Finance\FinanceLaboratoryTariffContentDigest;
use App\Support\Finance\FinanceRadiologySourceAdapter;
use App\Support\Finance\FinanceRadiologyTariffContentDigest;
use App\Support\Finance\FinanceSourceCoordinator;
use App\Support\Finance\FinanceTariffContentDigest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class SyntheticRecoverySnapshot
{
    public const DATABASE_NAME_PATTERN = '/\Asimrs_recovery_[0-9a-f]{12}_(source|restore)\z/';

    private const SENTINEL_ROUTE = 'recovery.rehearsal.synthetic';

    private const GOVERNED_OPTIONAL_MIGRATION = '2026_09_03_000200_create_medication_replenishment_warehouse_custody_tables';

    /**
     * Capture a canonical, value-minimized within-run fingerprint of a
     * disposable PostgreSQL 17 recovery database. No patient or account values
     * are emitted; generated fixture identifiers can differ between attempts.
     *
     * @return array<string, mixed>
     */
    public function capture(): array
    {
        $this->assertSafeBoundary();

        $snapshot = DB::transaction(function (): array {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            $tableNames = DB::table('information_schema.tables')
                ->where('table_schema', 'laravel')
                ->where('table_type', 'BASE TABLE')
                ->orderBy('table_name')
                ->pluck('table_name')
                ->map(fn (mixed $name): string => (string) $name)
                ->all();
            $migrationRows = DB::table(SchemaQualifier::table('migrations'))
                ->orderBy('id')
                ->get(['migration', 'batch'])
                ->map(fn (object $row): array => [
                    'migration' => (string) $row->migration,
                    'batch' => (int) $row->batch,
                ])
                ->values()
                ->all();
            $expectedMigrations = $this->migrationFileRows();

            $this->assertMigrationLedgerMatchesCheckout($migrationRows, $expectedMigrations);

            $counts = [
                'base_tables' => count($tableNames),
                'migrations' => count($migrationRows),
                'users' => DB::table(SchemaQualifier::table('users'))->count(),
                'patients' => DB::table(SchemaQualifier::table('patients'))->count(),
                'synthetic_patients' => DB::table(SchemaQualifier::table('patients'))->where('is_synthetic', true)->count(),
                'non_synthetic_patients' => DB::table(SchemaQualifier::table('patients'))->where('is_synthetic', false)->count(),
                'encounters' => DB::table(SchemaQualifier::table('encounters'))->count(),
                'clinical_entries' => DB::table(SchemaQualifier::table('clinical_entries'))->count(),
                'lab_service_requests' => DB::table(SchemaQualifier::table('lab_service_requests'))->count(),
                'lab_diagnostic_results' => DB::table(SchemaQualifier::table('lab_diagnostic_results'))->count(),
                'laboratory_masters' => DB::table(SchemaQualifier::table('laboratory_examination_masters'))->count(),
                'laboratory_master_versions' => DB::table(SchemaQualifier::table('laboratory_examination_master_versions'))->count(),
                'laboratory_orders' => DB::table(SchemaQualifier::table('laboratory_orders'))->count(),
                'laboratory_order_cancellations' => DB::table(SchemaQualifier::table('laboratory_order_cancellations'))->count(),
                'laboratory_specimen_attempts' => DB::table(SchemaQualifier::table('laboratory_specimen_attempts'))->count(),
                'laboratory_specimen_events' => DB::table(SchemaQualifier::table('laboratory_specimen_events'))->count(),
                'laboratory_result_versions' => DB::table(SchemaQualifier::table('laboratory_result_versions'))->count(),
                'laboratory_critical_communications' => DB::table(SchemaQualifier::table('laboratory_critical_communications'))->count(),
                'laboratory_result_acknowledgements' => DB::table(SchemaQualifier::table('laboratory_result_acknowledgements'))->count(),
                'laboratory_operation_receipts' => DB::table(SchemaQualifier::table('laboratory_operation_receipts'))->count(),
                'radiology_masters' => DB::table(SchemaQualifier::table('radiology_examination_masters'))->count(),
                'radiology_master_versions' => DB::table(SchemaQualifier::table('radiology_examination_master_versions'))->count(),
                'radiology_orders' => DB::table(SchemaQualifier::table('radiology_orders'))->count(),
                'radiology_order_cancellations' => DB::table(SchemaQualifier::table('radiology_order_cancellations'))->count(),
                'radiology_performances' => DB::table(SchemaQualifier::table('radiology_performances'))->count(),
                'radiology_report_versions' => DB::table(SchemaQualifier::table('radiology_report_versions'))->count(),
                'radiology_report_acknowledgements' => DB::table(SchemaQualifier::table('radiology_report_acknowledgements'))->count(),
                'radiology_operation_receipts' => DB::table(SchemaQualifier::table('radiology_operation_receipts'))->count(),
                'emergency_triage_vocabularies' => DB::table(SchemaQualifier::table('emergency_triage_vocabularies'))->count(),
                'emergency_triage_vocabulary_versions' => DB::table(SchemaQualifier::table('emergency_triage_vocabulary_versions'))->count(),
                'emergency_triage_assessments' => DB::table(SchemaQualifier::table('emergency_triage_assessments'))->count(),
                'emergency_clinical_documents' => DB::table(SchemaQualifier::table('emergency_clinical_documents'))->count(),
                'emergency_clinical_document_versions' => DB::table(SchemaQualifier::table('emergency_clinical_document_versions'))->count(),
                'emergency_follow_up_proposals' => DB::table(SchemaQualifier::table('emergency_result_follow_up_proposals'))->count(),
                'emergency_follow_up_acceptances' => DB::table(SchemaQualifier::table('emergency_result_follow_up_acceptances'))->count(),
                'emergency_dispositions' => DB::table(SchemaQualifier::table('emergency_dispositions'))->count(),
                'emergency_correction_intents' => DB::table(SchemaQualifier::table('emergency_disposition_correction_intents'))->count(),
                'emergency_correction_intent_events' => DB::table(SchemaQualifier::table('emergency_disposition_correction_intent_events'))->count(),
                'emergency_inpatient_handoffs' => DB::table(SchemaQualifier::table('emergency_inpatient_handoffs'))->count(),
                'emergency_handoff_compensations' => DB::table(SchemaQualifier::table('emergency_handoff_compensations'))->count(),
                'emergency_operation_receipts' => DB::table(SchemaQualifier::table('emergency_operation_receipts'))->count(),
                'pharmacy_medicines' => DB::table(SchemaQualifier::table('pharmacy_medicines'))->count(),
                'pharmacy_medicine_code_reservations' => DB::table(SchemaQualifier::table('pharmacy_medicine_code_reservations'))->count(),
                'pharmacy_medicine_versions' => DB::table(SchemaQualifier::table('pharmacy_medicine_versions'))->count(),
                'pharmacy_depots' => DB::table(SchemaQualifier::table('pharmacy_depots'))->count(),
                'pharmacy_depot_code_reservations' => DB::table(SchemaQualifier::table('pharmacy_depot_code_reservations'))->count(),
                'pharmacy_depot_versions' => DB::table(SchemaQualifier::table('pharmacy_depot_versions'))->count(),
                'pharmacy_inventory_mutexes' => DB::table(SchemaQualifier::table('pharmacy_inventory_mutexes'))->count(),
                'pharmacy_stock_lots' => DB::table(SchemaQualifier::table('pharmacy_stock_lots'))->count(),
                'pharmacy_stock_movements' => DB::table(SchemaQualifier::table('pharmacy_stock_movements'))->count(),
                'pharmacy_prescriptions' => DB::table(SchemaQualifier::table('pharmacy_prescriptions'))->count(),
                'pharmacy_prescription_versions' => DB::table(SchemaQualifier::table('pharmacy_prescription_versions'))->count(),
                'pharmacy_prescription_items' => DB::table(SchemaQualifier::table('pharmacy_prescription_items'))->count(),
                'pharmacy_verifications' => DB::table(SchemaQualifier::table('pharmacy_verifications'))->count(),
                'pharmacy_preparations' => DB::table(SchemaQualifier::table('pharmacy_preparations'))->count(),
                'pharmacy_preparation_allocations' => DB::table(SchemaQualifier::table('pharmacy_preparation_allocations'))->count(),
                'pharmacy_handovers' => DB::table(SchemaQualifier::table('pharmacy_handovers'))->count(),
                'pharmacy_handover_items' => DB::table(SchemaQualifier::table('pharmacy_handover_items'))->count(),
                'pharmacy_returns' => DB::table(SchemaQualifier::table('pharmacy_returns'))->count(),
                'pharmacy_return_items' => DB::table(SchemaQualifier::table('pharmacy_return_items'))->count(),
                'pharmacy_financial_source_events' => DB::table(SchemaQualifier::table('pharmacy_financial_source_events'))->count(),
                'pharmacy_operation_receipts' => DB::table(SchemaQualifier::table('pharmacy_operation_receipts'))->count(),
                'finance_charge_events' => DB::table(SchemaQualifier::table('finance_charge_events'))->count(),
                'finance_bills' => DB::table(SchemaQualifier::table('finance_bills'))->count(),
                'finance_bill_versions' => DB::table(SchemaQualifier::table('finance_bill_versions'))->count(),
                'finance_bill_lines' => DB::table(SchemaQualifier::table('finance_bill_lines'))->count(),
                'finance_operation_receipts' => DB::table(SchemaQualifier::table('finance_operation_receipts'))->count(),
                'finance_cash_settlements' => DB::table(SchemaQualifier::table('finance_cash_settlements'))->count(),
                'finance_settlement_operation_receipts' => DB::table(SchemaQualifier::table('finance_settlement_operation_receipts'))->count(),
                'finance_settlement_correction_cases' => DB::table(SchemaQualifier::table('finance_settlement_correction_cases'))->count(),
                'finance_settlement_correction_events' => DB::table(SchemaQualifier::table('finance_settlement_correction_events'))->count(),
                'finance_settlement_correction_operation_receipts' => DB::table(SchemaQualifier::table('finance_settlement_correction_operation_receipts'))->count(),
                'finance_cashier_collection_batches' => DB::table(SchemaQualifier::table('finance_cashier_collection_batches'))->count(),
                'finance_cashier_collection_active_slots' => DB::table(SchemaQualifier::table('finance_cashier_collection_active_slots'))->count(),
                'finance_cashier_collection_members' => DB::table(SchemaQualifier::table('finance_cashier_collection_members'))->count(),
                'finance_cashier_collection_events' => DB::table(SchemaQualifier::table('finance_cashier_collection_events'))->count(),
                'finance_cash_deposit_handoffs' => DB::table(SchemaQualifier::table('finance_cash_deposit_handoffs'))->count(),
                'finance_cashier_collection_operation_receipts' => DB::table(SchemaQualifier::table('finance_cashier_collection_operation_receipts'))->count(),
                'finance_laboratory_tariff_bindings' => DB::table(SchemaQualifier::table('finance_laboratory_tariff_bindings'))->count(),
                'finance_laboratory_tariff_binding_versions' => DB::table(SchemaQualifier::table('finance_laboratory_tariff_binding_versions'))->count(),
                'finance_laboratory_tariff_operation_receipts' => DB::table(SchemaQualifier::table('finance_laboratory_tariff_operation_receipts'))->count(),
                'finance_laboratory_source_events' => DB::table(SchemaQualifier::table('finance_laboratory_source_events'))->count(),
                'finance_accommodation_tariff_bindings' => DB::table(SchemaQualifier::table('finance_accommodation_tariff_bindings'))->count(),
                'finance_accommodation_tariff_binding_versions' => DB::table(SchemaQualifier::table('finance_accommodation_tariff_binding_versions'))->count(),
                'finance_accommodation_tariff_operation_receipts' => DB::table(SchemaQualifier::table('finance_accommodation_tariff_operation_receipts'))->count(),
                'finance_accommodation_source_events' => DB::table(SchemaQualifier::table('finance_accommodation_source_events'))->count(),
                'finance_radiology_tariff_bindings' => DB::table(SchemaQualifier::table('finance_radiology_tariff_bindings'))->count(),
                'finance_radiology_tariff_binding_versions' => DB::table(SchemaQualifier::table('finance_radiology_tariff_binding_versions'))->count(),
                'finance_radiology_tariff_operation_receipts' => DB::table(SchemaQualifier::table('finance_radiology_tariff_operation_receipts'))->count(),
                'finance_radiology_source_events' => DB::table(SchemaQualifier::table('finance_radiology_source_events'))->count(),
                'finance_cost_component_groups' => DB::table(SchemaQualifier::table('finance_cost_component_groups'))->count(),
                'finance_cost_component_group_versions' => DB::table(SchemaQualifier::table('finance_cost_component_group_versions'))->count(),
                'finance_cost_components' => DB::table(SchemaQualifier::table('finance_cost_components'))->count(),
                'finance_cost_component_versions' => DB::table(SchemaQualifier::table('finance_cost_component_versions'))->count(),
                'finance_tariff_catalogues' => DB::table(SchemaQualifier::table('finance_tariff_catalogues'))->count(),
                'finance_tariff_catalogue_versions' => DB::table(SchemaQualifier::table('finance_tariff_catalogue_versions'))->count(),
                'finance_tariff_items' => DB::table(SchemaQualifier::table('finance_tariff_items'))->count(),
                'finance_tariff_item_versions' => DB::table(SchemaQualifier::table('finance_tariff_item_versions'))->count(),
                'finance_tariff_code_reservations' => DB::table(SchemaQualifier::table('finance_tariff_code_reservations'))->count(),
                'finance_tariff_operation_receipts' => DB::table(SchemaQualifier::table('finance_tariff_operation_receipts'))->count(),
                'emergency_linked_inpatient_encounters' => DB::table(SchemaQualifier::table('emergency_inpatient_handoffs'))->distinct()->count('target_encounter_id'),
                'inpatient_location_events' => DB::table(SchemaQualifier::table('inpatient_location_events'))->count(),
                'inpatient_beds' => DB::table(SchemaQualifier::table('inpatient_beds'))->count(),
                'inpatient_patient_claim_mutexes' => DB::table(SchemaQualifier::table('inpatient_patient_claim_mutexes'))->count(),
                'active_inpatient_patient_claims' => DB::table(SchemaQualifier::table('encounters'))->whereNotNull('active_inpatient_patient_id')->count(),
                'outpatient_documents' => DB::table(SchemaQualifier::table('outpatient_clinical_documents'))->count(),
                'outpatient_document_versions' => DB::table(SchemaQualifier::table('outpatient_clinical_document_versions'))->count(),
                'rm_completeness_reviews' => DB::table(SchemaQualifier::table('outpatient_rm_completeness_reviews'))->count(),
                'audit_events' => AuditEvent::query()->count(),
                'recovery_sentinel_events' => AuditEvent::query()
                    ->where('action', 'authorization.denied')
                    ->where('resource_type', 'http_route')
                    ->where('resource_id', self::SENTINEL_ROUTE)
                    ->where('outcome', 'DENIED')
                    ->count(),
            ];

            $orphans = [
                'encounter_without_patient' => $this->orphanCount('encounters', 'patients', 'patient_id'),
                'clinical_entry_without_encounter' => $this->orphanCount('clinical_entries', 'encounters', 'encounter_id'),
                'lab_request_without_encounter' => $this->orphanCount('lab_service_requests', 'encounters', 'encounter_id'),
                'lab_result_without_request' => $this->orphanCount('lab_diagnostic_results', 'lab_service_requests', 'lab_service_request_id'),
                'laboratory_master_version_without_master' => $this->orphanCount('laboratory_examination_master_versions', 'laboratory_examination_masters', 'laboratory_examination_master_id'),
                'laboratory_order_without_encounter' => $this->orphanCount('laboratory_orders', 'encounters', 'encounter_id'),
                'laboratory_order_without_master' => $this->orphanCount('laboratory_orders', 'laboratory_examination_masters', 'master_id'),
                'laboratory_cancellation_without_order' => $this->orphanCount('laboratory_order_cancellations', 'laboratory_orders', 'laboratory_order_id'),
                'laboratory_specimen_without_order' => $this->orphanCount('laboratory_specimen_attempts', 'laboratory_orders', 'laboratory_order_id'),
                'laboratory_specimen_event_without_attempt' => $this->orphanCount('laboratory_specimen_events', 'laboratory_specimen_attempts', 'laboratory_specimen_attempt_id'),
                'laboratory_result_without_order' => $this->orphanCount('laboratory_result_versions', 'laboratory_orders', 'laboratory_order_id'),
                'laboratory_result_without_specimen' => $this->orphanCount('laboratory_result_versions', 'laboratory_specimen_attempts', 'laboratory_specimen_attempt_id'),
                'laboratory_communication_without_result' => $this->orphanCount('laboratory_critical_communications', 'laboratory_result_versions', 'laboratory_result_version_id'),
                'laboratory_acknowledgement_without_result' => $this->orphanCount('laboratory_result_acknowledgements', 'laboratory_result_versions', 'laboratory_result_version_id'),
                'laboratory_acknowledgement_without_emergency_acceptance' => $this->orphanCount('laboratory_result_acknowledgements', 'emergency_result_follow_up_acceptances', 'emergency_follow_up_acceptance_id'),
                'radiology_master_version_without_master' => $this->orphanCount('radiology_examination_master_versions', 'radiology_examination_masters', 'radiology_examination_master_id'),
                'radiology_order_without_encounter' => $this->orphanCount('radiology_orders', 'encounters', 'encounter_id'),
                'radiology_order_without_master' => $this->orphanCount('radiology_orders', 'radiology_examination_masters', 'master_id'),
                'radiology_cancellation_without_order' => $this->orphanCount('radiology_order_cancellations', 'radiology_orders', 'radiology_order_id'),
                'radiology_performance_without_order' => $this->orphanCount('radiology_performances', 'radiology_orders', 'radiology_order_id'),
                'radiology_report_without_order' => $this->orphanCount('radiology_report_versions', 'radiology_orders', 'radiology_order_id'),
                'radiology_acknowledgement_without_report' => $this->orphanCount('radiology_report_acknowledgements', 'radiology_report_versions', 'radiology_report_version_id'),
                'radiology_acknowledgement_without_emergency_acceptance' => $this->orphanCount('radiology_report_acknowledgements', 'emergency_result_follow_up_acceptances', 'emergency_follow_up_acceptance_id'),
                'emergency_vocabulary_version_without_vocabulary' => $this->orphanCount('emergency_triage_vocabulary_versions', 'emergency_triage_vocabularies', 'emergency_triage_vocabulary_id'),
                'emergency_assessment_without_encounter' => $this->orphanCount('emergency_triage_assessments', 'encounters', 'encounter_id'),
                'emergency_assessment_without_vocabulary_version' => $this->orphanCount('emergency_triage_assessments', 'emergency_triage_vocabulary_versions', 'vocabulary_version_id'),
                'emergency_document_without_encounter' => $this->orphanCount('emergency_clinical_documents', 'encounters', 'encounter_id'),
                'emergency_document_version_without_document' => $this->orphanCount('emergency_clinical_document_versions', 'emergency_clinical_documents', 'emergency_clinical_document_id'),
                'emergency_follow_up_proposal_without_encounter' => $this->orphanCount('emergency_result_follow_up_proposals', 'encounters', 'encounter_id'),
                'emergency_follow_up_acceptance_without_proposal' => $this->orphanCount('emergency_result_follow_up_acceptances', 'emergency_result_follow_up_proposals', 'proposal_id'),
                'emergency_disposition_without_encounter' => $this->orphanCount('emergency_dispositions', 'encounters', 'encounter_id'),
                'emergency_intent_without_encounter' => $this->orphanCount('emergency_disposition_correction_intents', 'encounters', 'encounter_id'),
                'emergency_intent_without_disposition' => $this->orphanCount('emergency_disposition_correction_intents', 'emergency_dispositions', 'current_disposition_id'),
                'emergency_intent_without_handoff' => $this->orphanCount('emergency_disposition_correction_intents', 'emergency_inpatient_handoffs', 'handoff_id'),
                'emergency_intent_event_without_intent' => $this->orphanCount('emergency_disposition_correction_intent_events', 'emergency_disposition_correction_intents', 'correction_intent_id'),
                'emergency_handoff_without_source' => $this->orphanCount('emergency_inpatient_handoffs', 'encounters', 'source_encounter_id'),
                'emergency_handoff_without_target' => $this->orphanCount('emergency_inpatient_handoffs', 'encounters', 'target_encounter_id'),
                'emergency_handoff_without_disposition' => $this->orphanCount('emergency_inpatient_handoffs', 'emergency_dispositions', 'disposition_id'),
                'emergency_handoff_without_location' => $this->orphanCount('emergency_inpatient_handoffs', 'inpatient_location_events', 'inpatient_location_event_id'),
                'emergency_handoff_without_bed' => $this->orphanCount('emergency_inpatient_handoffs', 'inpatient_beds', 'inpatient_bed_id'),
                'emergency_compensation_without_handoff' => $this->orphanCount('emergency_handoff_compensations', 'emergency_inpatient_handoffs', 'handoff_id'),
                'emergency_compensation_without_intent' => $this->orphanCount('emergency_handoff_compensations', 'emergency_disposition_correction_intents', 'correction_intent_id'),
                'emergency_compensation_without_replacement' => $this->orphanCount('emergency_handoff_compensations', 'emergency_dispositions', 'replacement_disposition_id'),
                'pharmacy_medicine_version_without_medicine' => $this->orphanCount('pharmacy_medicine_versions', 'pharmacy_medicines', 'medicine_id'),
                'pharmacy_depot_version_without_depot' => $this->orphanCount('pharmacy_depot_versions', 'pharmacy_depots', 'depot_id'),
                'pharmacy_inventory_mutex_without_depot' => $this->orphanCount('pharmacy_inventory_mutexes', 'pharmacy_depots', 'depot_id'),
                'pharmacy_inventory_mutex_without_medicine' => $this->orphanCount('pharmacy_inventory_mutexes', 'pharmacy_medicines', 'medicine_id'),
                'pharmacy_lot_without_depot' => $this->orphanCount('pharmacy_stock_lots', 'pharmacy_depots', 'depot_id'),
                'pharmacy_lot_without_medicine' => $this->orphanCount('pharmacy_stock_lots', 'pharmacy_medicines', 'medicine_id'),
                'pharmacy_prescription_without_encounter' => $this->orphanCount('pharmacy_prescriptions', 'encounters', 'encounter_id'),
                'pharmacy_prescription_without_patient' => $this->orphanCount('pharmacy_prescriptions', 'patients', 'patient_id'),
                'pharmacy_prescription_without_depot' => $this->orphanCount('pharmacy_prescriptions', 'pharmacy_depots', 'depot_id'),
                'pharmacy_prescription_version_without_prescription' => $this->orphanCount('pharmacy_prescription_versions', 'pharmacy_prescriptions', 'prescription_id'),
                'pharmacy_item_without_prescription' => $this->orphanCount('pharmacy_prescription_items', 'pharmacy_prescriptions', 'prescription_id'),
                'pharmacy_item_without_medicine' => $this->orphanCount('pharmacy_prescription_items', 'pharmacy_medicines', 'medicine_id'),
                'pharmacy_verification_without_prescription' => $this->orphanCount('pharmacy_verifications', 'pharmacy_prescriptions', 'prescription_id'),
                'pharmacy_preparation_without_prescription' => $this->orphanCount('pharmacy_preparations', 'pharmacy_prescriptions', 'prescription_id'),
                'pharmacy_allocation_without_preparation' => $this->orphanCount('pharmacy_preparation_allocations', 'pharmacy_preparations', 'preparation_id'),
                'pharmacy_allocation_without_item' => $this->orphanCount('pharmacy_preparation_allocations', 'pharmacy_prescription_items', 'prescription_item_id'),
                'pharmacy_allocation_without_lot' => $this->orphanCount('pharmacy_preparation_allocations', 'pharmacy_stock_lots', 'stock_lot_id'),
                'pharmacy_handover_without_prescription' => $this->orphanCount('pharmacy_handovers', 'pharmacy_prescriptions', 'prescription_id'),
                'pharmacy_handover_without_preparation' => $this->orphanCount('pharmacy_handovers', 'pharmacy_preparations', 'preparation_id'),
                'pharmacy_handover_item_without_handover' => $this->orphanCount('pharmacy_handover_items', 'pharmacy_handovers', 'handover_id'),
                'pharmacy_handover_item_without_item' => $this->orphanCount('pharmacy_handover_items', 'pharmacy_prescription_items', 'prescription_item_id'),
                'pharmacy_handover_item_without_lot' => $this->orphanCount('pharmacy_handover_items', 'pharmacy_stock_lots', 'stock_lot_id'),
                'pharmacy_return_without_handover' => $this->orphanCount('pharmacy_returns', 'pharmacy_handovers', 'handover_id'),
                'pharmacy_return_item_without_return' => $this->orphanCount('pharmacy_return_items', 'pharmacy_returns', 'return_id'),
                'pharmacy_return_item_without_handover_item' => $this->orphanCount('pharmacy_return_items', 'pharmacy_handover_items', 'handover_item_id'),
                'pharmacy_stock_movement_without_lot' => $this->orphanCount('pharmacy_stock_movements', 'pharmacy_stock_lots', 'stock_lot_id'),
                'pharmacy_financial_event_without_prescription' => $this->orphanCount('pharmacy_financial_source_events', 'pharmacy_prescriptions', 'prescription_id'),
                'pharmacy_financial_event_without_item' => $this->orphanCount('pharmacy_financial_source_events', 'pharmacy_prescription_items', 'prescription_item_id'),
                'finance_charge_without_pharmacy_source' => $this->financeTypedSourceOrphanCount(FinanceChargeEvent::SOURCE_PHARMACY),
                'finance_charge_without_radiology_source' => $this->financeTypedSourceOrphanCount(FinanceChargeEvent::SOURCE_RADIOLOGY),
                'finance_charge_without_laboratory_source' => $this->financeTypedSourceOrphanCount(FinanceChargeEvent::SOURCE_LABORATORY),
                'finance_charge_without_accommodation_source' => $this->financeTypedSourceOrphanCount(FinanceChargeEvent::SOURCE_ACCOMMODATION),
                'finance_charge_typed_source_mismatches' => $this->financeTypedSourceMismatchCount(),
                'finance_charge_without_encounter' => $this->orphanCount('finance_charge_events', 'encounters', 'encounter_id'),
                'finance_charge_without_patient' => $this->orphanCount('finance_charge_events', 'patients', 'patient_id'),
                'finance_charge_without_importer' => $this->orphanCount('finance_charge_events', 'users', 'imported_by_user_id'),
                'finance_bill_without_encounter' => $this->orphanCount('finance_bills', 'encounters', 'encounter_id'),
                'finance_bill_without_patient' => $this->orphanCount('finance_bills', 'patients', 'patient_id'),
                'finance_version_without_bill' => $this->orphanCount('finance_bill_versions', 'finance_bills', 'bill_id'),
                'finance_version_without_previous_version' => $this->orphanCount('finance_bill_versions', 'finance_bill_versions', 'previous_version_id'),
                'finance_version_without_issuer' => $this->orphanCount('finance_bill_versions', 'users', 'issued_by_user_id'),
                'finance_version_without_encounter' => $this->orphanCount('finance_bill_versions', 'encounters', 'encounter_id'),
                'finance_version_without_patient' => $this->orphanCount('finance_bill_versions', 'patients', 'patient_id'),
                'finance_line_without_version' => $this->orphanCount('finance_bill_lines', 'finance_bill_versions', 'bill_version_id'),
                'finance_line_without_charge' => $this->orphanCount('finance_bill_lines', 'finance_charge_events', 'charge_event_id'),
                'finance_receipt_without_actor' => $this->orphanCount('finance_operation_receipts', 'users', 'actor_user_id'),
                'finance_cash_settlement_without_bill' => $this->orphanCount('finance_cash_settlements', 'finance_bills', 'bill_id'),
                'finance_cash_settlement_without_bill_version' => $this->orphanCount('finance_cash_settlements', 'finance_bill_versions', 'bill_version_id'),
                'finance_cash_settlement_without_encounter' => $this->orphanCount('finance_cash_settlements', 'encounters', 'encounter_id'),
                'finance_cash_settlement_without_patient' => $this->orphanCount('finance_cash_settlements', 'patients', 'patient_id'),
                'finance_cash_settlement_without_cashier' => $this->orphanCount('finance_cash_settlements', 'users', 'cashier_user_id'),
                'finance_settlement_receipt_without_actor' => $this->orphanCount('finance_settlement_operation_receipts', 'users', 'actor_user_id'),
                'finance_settlement_correction_case_without_settlement' => $this->orphanCount('finance_settlement_correction_cases', 'finance_cash_settlements', 'settlement_id'),
                'finance_settlement_correction_case_without_bill' => $this->orphanCount('finance_settlement_correction_cases', 'finance_bills', 'bill_id'),
                'finance_settlement_correction_case_without_bill_version' => $this->orphanCount('finance_settlement_correction_cases', 'finance_bill_versions', 'bill_version_id'),
                'finance_settlement_correction_case_without_requester' => $this->orphanCount('finance_settlement_correction_cases', 'users', 'requesting_cashier_user_id'),
                'finance_settlement_correction_event_without_case' => $this->orphanCount('finance_settlement_correction_events', 'finance_settlement_correction_cases', 'correction_case_id'),
                'finance_settlement_correction_event_without_actor' => $this->orphanCount('finance_settlement_correction_events', 'users', 'actor_user_id'),
                'finance_settlement_correction_receipt_without_actor' => $this->orphanCount('finance_settlement_correction_operation_receipts', 'users', 'actor_user_id'),
                'finance_settlement_correction_receipt_without_case' => $this->orphanCount('finance_settlement_correction_operation_receipts', 'finance_settlement_correction_cases', 'correction_case_id'),
                'finance_settlement_correction_receipt_without_event' => $this->orphanCount('finance_settlement_correction_operation_receipts', 'finance_settlement_correction_events', 'correction_event_id'),
                'finance_collection_slot_without_batch' => $this->orphanCount('finance_cashier_collection_active_slots', 'finance_cashier_collection_batches', 'collection_batch_id'),
                'finance_collection_member_without_batch' => $this->orphanCount('finance_cashier_collection_members', 'finance_cashier_collection_batches', 'collection_batch_id'),
                'finance_collection_member_without_settlement' => $this->orphanCount('finance_cashier_collection_members', 'finance_cash_settlements', 'settlement_id'),
                'finance_collection_event_without_batch' => $this->orphanCount('finance_cashier_collection_events', 'finance_cashier_collection_batches', 'collection_batch_id'),
                'finance_collection_handoff_without_batch' => $this->orphanCount('finance_cash_deposit_handoffs', 'finance_cashier_collection_batches', 'collection_batch_id'),
                'finance_collection_handoff_without_verified_event' => $this->orphanCount('finance_cash_deposit_handoffs', 'finance_cashier_collection_events', 'verified_event_id'),
                'finance_collection_receipt_without_batch' => $this->orphanCount('finance_cashier_collection_operation_receipts', 'finance_cashier_collection_batches', 'collection_batch_id'),
                'finance_laboratory_binding_without_master' => $this->orphanCount('finance_laboratory_tariff_bindings', 'laboratory_examination_masters', 'laboratory_master_id'),
                'finance_laboratory_binding_without_master_version' => $this->orphanCount('finance_laboratory_tariff_bindings', 'laboratory_examination_master_versions', 'laboratory_master_version_id'),
                'finance_laboratory_binding_version_without_binding' => $this->orphanCount('finance_laboratory_tariff_binding_versions', 'finance_laboratory_tariff_bindings', 'binding_id'),
                'finance_laboratory_binding_version_without_tariff' => $this->orphanCount('finance_laboratory_tariff_binding_versions', 'finance_tariff_items', 'tariff_item_id'),
                'finance_laboratory_binding_version_without_actor' => $this->orphanCount('finance_laboratory_tariff_binding_versions', 'users', 'actor_user_id'),
                'finance_laboratory_receipt_without_actor' => $this->orphanCount('finance_laboratory_tariff_operation_receipts', 'users', 'actor_user_id'),
                'finance_laboratory_source_without_result' => $this->orphanCount('finance_laboratory_source_events', 'laboratory_result_versions', 'laboratory_result_version_id'),
                'finance_laboratory_source_without_order' => $this->orphanCount('finance_laboratory_source_events', 'laboratory_orders', 'laboratory_order_id'),
                'finance_laboratory_source_without_specimen' => $this->orphanCount('finance_laboratory_source_events', 'laboratory_specimen_attempts', 'laboratory_specimen_attempt_id'),
                'finance_laboratory_source_without_communication' => $this->orphanCount('finance_laboratory_source_events', 'laboratory_critical_communications', 'laboratory_critical_communication_id'),
                'finance_laboratory_source_without_binding_version' => $this->orphanCount('finance_laboratory_source_events', 'finance_laboratory_tariff_binding_versions', 'binding_version_id'),
                'finance_laboratory_source_without_tariff_version' => $this->orphanCount('finance_laboratory_source_events', 'finance_tariff_item_versions', 'tariff_item_version_id'),
                'finance_laboratory_source_without_encounter' => $this->orphanCount('finance_laboratory_source_events', 'encounters', 'encounter_id'),
                'finance_laboratory_source_without_patient' => $this->orphanCount('finance_laboratory_source_events', 'patients', 'patient_id'),
                'finance_laboratory_source_without_importer' => $this->orphanCount('finance_laboratory_source_events', 'users', 'imported_by_user_id'),
                'finance_accommodation_binding_without_bed' => $this->orphanCount('finance_accommodation_tariff_bindings', 'inpatient_beds', 'inpatient_bed_id'),
                'finance_accommodation_binding_without_bed_version' => $this->orphanCount('finance_accommodation_tariff_bindings', 'inpatient_bed_versions', 'inpatient_bed_version_id'),
                'finance_accommodation_binding_version_without_binding' => $this->orphanCount('finance_accommodation_tariff_binding_versions', 'finance_accommodation_tariff_bindings', 'binding_id'),
                'finance_accommodation_binding_version_without_tariff' => $this->orphanCount('finance_accommodation_tariff_binding_versions', 'finance_tariff_items', 'tariff_item_id'),
                'finance_accommodation_binding_version_without_actor' => $this->orphanCount('finance_accommodation_tariff_binding_versions', 'users', 'actor_user_id'),
                'finance_accommodation_receipt_without_actor' => $this->orphanCount('finance_accommodation_tariff_operation_receipts', 'users', 'actor_user_id'),
                'finance_accommodation_source_without_opening_location' => $this->orphanCount('finance_accommodation_source_events', 'inpatient_location_events', 'inpatient_location_event_id'),
                'finance_accommodation_source_without_bed_version' => $this->orphanCount('finance_accommodation_source_events', 'inpatient_bed_versions', 'inpatient_bed_version_id'),
                'finance_accommodation_source_without_closing_location' => $this->orphanCount('finance_accommodation_source_events', 'inpatient_location_events', 'closing_location_event_id'),
                'finance_accommodation_source_without_discharge' => $this->orphanCount('finance_accommodation_source_events', 'inpatient_discharges', 'inpatient_discharge_id'),
                'finance_accommodation_source_without_binding_version' => $this->orphanCount('finance_accommodation_source_events', 'finance_accommodation_tariff_binding_versions', 'binding_version_id'),
                'finance_accommodation_source_without_tariff_version' => $this->orphanCount('finance_accommodation_source_events', 'finance_tariff_item_versions', 'tariff_item_version_id'),
                'finance_accommodation_source_without_encounter' => $this->orphanCount('finance_accommodation_source_events', 'encounters', 'encounter_id'),
                'finance_accommodation_source_without_patient' => $this->orphanCount('finance_accommodation_source_events', 'patients', 'patient_id'),
                'finance_accommodation_source_without_importer' => $this->orphanCount('finance_accommodation_source_events', 'users', 'imported_by_user_id'),
                'finance_radiology_binding_without_master' => $this->orphanCount('finance_radiology_tariff_bindings', 'radiology_examination_masters', 'radiology_master_id'),
                'finance_radiology_binding_without_master_version' => $this->orphanCount('finance_radiology_tariff_bindings', 'radiology_examination_master_versions', 'radiology_master_version_id'),
                'finance_radiology_binding_version_without_binding' => $this->orphanCount('finance_radiology_tariff_binding_versions', 'finance_radiology_tariff_bindings', 'binding_id'),
                'finance_radiology_binding_version_without_tariff' => $this->orphanCount('finance_radiology_tariff_binding_versions', 'finance_tariff_items', 'tariff_item_id'),
                'finance_radiology_binding_version_without_actor' => $this->orphanCount('finance_radiology_tariff_binding_versions', 'users', 'actor_user_id'),
                'finance_radiology_receipt_without_actor' => $this->orphanCount('finance_radiology_tariff_operation_receipts', 'users', 'actor_user_id'),
                'finance_radiology_source_without_performance' => $this->orphanCount('finance_radiology_source_events', 'radiology_performances', 'radiology_performance_id'),
                'finance_radiology_source_without_order' => $this->orphanCount('finance_radiology_source_events', 'radiology_orders', 'radiology_order_id'),
                'finance_radiology_source_without_binding_version' => $this->orphanCount('finance_radiology_source_events', 'finance_radiology_tariff_binding_versions', 'binding_version_id'),
                'finance_radiology_source_without_tariff_version' => $this->orphanCount('finance_radiology_source_events', 'finance_tariff_item_versions', 'tariff_item_version_id'),
                'finance_radiology_source_without_encounter' => $this->orphanCount('finance_radiology_source_events', 'encounters', 'encounter_id'),
                'finance_radiology_source_without_patient' => $this->orphanCount('finance_radiology_source_events', 'patients', 'patient_id'),
                'finance_radiology_source_without_importer' => $this->orphanCount('finance_radiology_source_events', 'users', 'imported_by_user_id'),
                'finance_tariff_group_version_without_group' => $this->orphanCount('finance_cost_component_group_versions', 'finance_cost_component_groups', 'group_id'),
                'finance_tariff_group_version_without_actor' => $this->orphanCount('finance_cost_component_group_versions', 'users', 'actor_user_id'),
                'finance_tariff_component_without_group' => $this->orphanCount('finance_cost_components', 'finance_cost_component_groups', 'group_id'),
                'finance_tariff_component_version_without_component' => $this->orphanCount('finance_cost_component_versions', 'finance_cost_components', 'component_id'),
                'finance_tariff_component_version_without_actor' => $this->orphanCount('finance_cost_component_versions', 'users', 'actor_user_id'),
                'finance_tariff_catalogue_version_without_catalogue' => $this->orphanCount('finance_tariff_catalogue_versions', 'finance_tariff_catalogues', 'catalogue_id'),
                'finance_tariff_catalogue_version_without_actor' => $this->orphanCount('finance_tariff_catalogue_versions', 'users', 'actor_user_id'),
                'finance_tariff_item_without_catalogue' => $this->orphanCount('finance_tariff_items', 'finance_tariff_catalogues', 'catalogue_id'),
                'finance_tariff_item_without_component' => $this->orphanCount('finance_tariff_items', 'finance_cost_components', 'component_id'),
                'finance_tariff_item_version_without_item' => $this->orphanCount('finance_tariff_item_versions', 'finance_tariff_items', 'tariff_item_id'),
                'finance_tariff_item_version_without_actor' => $this->orphanCount('finance_tariff_item_versions', 'users', 'actor_user_id'),
                'finance_tariff_reservation_without_actor' => $this->orphanCount('finance_tariff_code_reservations', 'users', 'actor_user_id'),
                'finance_tariff_receipt_without_actor' => $this->orphanCount('finance_tariff_operation_receipts', 'users', 'actor_user_id'),
                'inpatient_location_without_encounter' => $this->orphanCount('inpatient_location_events', 'encounters', 'encounter_id'),
                'inpatient_claim_mutex_without_patient' => $this->orphanCount('inpatient_patient_claim_mutexes', 'patients', 'patient_id'),
                'outpatient_document_without_encounter' => $this->orphanCount('outpatient_clinical_documents', 'encounters', 'encounter_id'),
                'outpatient_version_without_document' => $this->orphanCount('outpatient_clinical_document_versions', 'outpatient_clinical_documents', 'outpatient_clinical_document_id'),
                'rm_review_without_encounter' => $this->orphanCount('outpatient_rm_completeness_reviews', 'encounters', 'encounter_id'),
                'audit_user_reference_missing' => AuditEvent::query()
                    ->whereNotNull('actor_user_id')
                    ->whereDoesntHave('actor')
                    ->count(),
            ];

            $integrity = [
                'emergency_cross_patient_handoffs' => $this->crossPatientHandoffCount(),
                'duplicate_active_inpatient_claims' => $this->duplicateActiveInpatientClaimCount(),
                'uncompensated_handoff_to_cancelled_child' => $this->uncompensatedCancelledChildCount(),
                'compensated_handoff_retaining_bed_or_claim' => $this->compensatedRetainedBedOrClaimCount(),
                'compensated_handoff_child_not_cancelled' => $this->compensatedNonCancelledChildCount(),
                'pharmacy_stock_balance_mismatches' => $this->pharmacyStockBalanceMismatchCount(),
                'pharmacy_financial_source_mismatches' => $this->pharmacyFinancialSourceMismatchCount(),
                'pharmacy_prescription_state_mismatches' => $this->pharmacyPrescriptionStateMismatchCount(),
                'pharmacy_receipt_result_mismatches' => $this->pharmacyReceiptResultMismatchCount(),
                'finance_retained_source_mismatches' => $this->financeRetainedSourceMismatchCount(),
                'finance_line_source_mismatches' => $this->financeLineSourceMismatchCount(),
                'finance_version_control_mismatches' => $this->financeVersionControlMismatchCount(),
                'finance_previous_version_chain_mismatches' => $this->financePreviousVersionChainMismatchCount(),
                'finance_bill_head_mismatches' => $this->financeBillHeadMismatchCount(),
                'finance_receipt_result_mismatches' => $this->financeReceiptResultMismatchCount(),
                'finance_cash_settlement_mismatches' => $this->financeCashSettlementMismatchCount(),
                'finance_cash_settlement_net_equation_mismatches' => $this->financeCashSettlementNetEquationMismatchCount(),
                'finance_settlement_receipt_mismatches' => $this->financeSettlementReceiptMismatchCount(),
                'finance_cash_settlement_audit_mismatches' => $this->financeCashSettlementAuditMismatchCount(),
                'finance_settlement_correction_mismatches' => $this->financeSettlementCorrectionMismatchCount(),
                'finance_settlement_correction_receipt_mismatches' => $this->financeSettlementCorrectionReceiptMismatchCount(),
                'finance_settlement_correction_audit_mismatches' => $this->financeSettlementCorrectionAuditMismatchCount(),
                'finance_cashier_collection_mismatches' => $this->financeCashierCollectionMismatchCount(),
                'teaching_reset_pair_mismatches' => $this->teachingResetPairMismatchCount(),
                'finance_tariff_version_chain_mismatches' => $this->financeTariffVersionChainMismatchCount(),
                'finance_tariff_head_mismatches' => $this->financeTariffHeadMismatchCount(),
                'finance_tariff_effective_period_mismatches' => $this->financeTariffEffectivePeriodMismatchCount(),
                'finance_tariff_upstream_mismatches' => $this->financeTariffUpstreamMismatchCount(),
                'finance_tariff_code_reservation_mismatches' => $this->financeTariffCodeReservationMismatchCount(),
                'finance_tariff_receipt_result_mismatches' => $this->financeTariffReceiptResultMismatchCount(),
                'finance_radiology_binding_version_chain_mismatches' => $this->financeRadiologyBindingVersionChainMismatchCount(),
                'finance_radiology_binding_head_mismatches' => $this->financeRadiologyBindingHeadMismatchCount(),
                'finance_radiology_binding_upstream_mismatches' => $this->financeRadiologyBindingUpstreamMismatchCount(),
                'finance_radiology_binding_receipt_result_mismatches' => $this->financeRadiologyBindingReceiptResultMismatchCount(),
                'finance_radiology_source_mismatches' => $this->financeRadiologySourceMismatchCount(),
                'finance_laboratory_binding_version_chain_mismatches' => $this->financeLaboratoryBindingVersionChainMismatchCount(),
                'finance_laboratory_binding_head_mismatches' => $this->financeLaboratoryBindingHeadMismatchCount(),
                'finance_laboratory_binding_upstream_mismatches' => $this->financeLaboratoryBindingUpstreamMismatchCount(),
                'finance_laboratory_binding_receipt_result_mismatches' => $this->financeLaboratoryBindingReceiptResultMismatchCount(),
                'finance_laboratory_source_mismatches' => $this->financeLaboratorySourceMismatchCount(),
                'finance_accommodation_binding_version_chain_mismatches' => $this->financeAccommodationBindingVersionChainMismatchCount(),
                'finance_accommodation_binding_head_mismatches' => $this->financeAccommodationBindingHeadMismatchCount(),
                'finance_accommodation_binding_upstream_mismatches' => $this->financeAccommodationBindingUpstreamMismatchCount(),
                'finance_accommodation_binding_receipt_result_mismatches' => $this->financeAccommodationBindingReceiptResultMismatchCount(),
                'finance_accommodation_source_mismatches' => $this->financeAccommodationSourceMismatchCount(),
            ];

            if ($counts['patients'] < 1 || $counts['synthetic_patients'] !== $counts['patients']) {
                throw new RuntimeException('The recovery fixture must contain only synthetic patients.');
            }

            if ($counts['recovery_sentinel_events'] !== 1) {
                throw new RuntimeException('The recovery audit sentinel is missing or duplicated.');
            }

            if (array_sum($orphans) !== 0) {
                throw new RuntimeException('The recovery fixture contains orphaned application relationships.');
            }

            if (array_sum($integrity) !== 0) {
                throw new RuntimeException('The recovery fixture violates application domain integrity.');
            }

            $payload = [
                'schema_version' => 1,
                'kind' => 'SIMRS_SYNTHETIC_RECOVERY_SNAPSHOT',
                'boundary' => [
                    'application_mode' => 'SIMULATION',
                    'synthetic_only' => true,
                    'database_engine' => 'postgresql',
                    'database_major' => 17,
                    'application_schema' => 'laravel',
                    'disposable_database' => true,
                    'hosted_readiness_claim' => false,
                ],
                'counts' => $counts,
                'orphans' => $orphans,
                'integrity' => $integrity,
                'digests' => [
                    'table_names_sha256' => $this->digest($tableNames),
                    'migration_ledger_sha256' => $this->digest($migrationRows),
                    'migration_file_set_sha256' => $this->digest($expectedMigrations),
                    'users_sha256' => $this->tableDigest('users', ['id', 'public_id', 'status', 'is_system_administrator']),
                    'patients_sha256' => $this->tableDigest('patients', ['id', 'public_id', 'medical_record_number', 'is_synthetic', 'created_by_user_id']),
                    'encounters_sha256' => $this->tableDigest('encounters', ['id', 'public_id', 'patient_id', 'care_setting', 'status', 'registered_by_user_id']),
                    'clinical_entries_sha256' => $this->tableDigest('clinical_entries', ['id', 'public_id', 'encounter_id', 'author_user_id', 'entry_type']),
                    'lab_requests_sha256' => $this->tableDigest('lab_service_requests', ['id', 'public_id', 'encounter_id', 'requested_by_user_id', 'status']),
                    'lab_results_sha256' => $this->tableDigest('lab_diagnostic_results', ['id', 'public_id', 'lab_service_request_id', 'entered_by_user_id', 'status']),
                    'laboratory_masters_sha256' => $this->tableDigest('laboratory_examination_masters', ['id', 'public_id', 'examination_code', 'state', 'version']),
                    'laboratory_master_versions_sha256' => $this->tableDigest('laboratory_examination_master_versions', ['id', 'public_id', 'laboratory_examination_master_id', 'version', 'state', 'content_digest']),
                    'laboratory_orders_sha256' => $this->tableDigest('laboratory_orders', ['id', 'public_id', 'encounter_id', 'master_id', 'ordered_by_user_id', 'status', 'version']),
                    'laboratory_order_cancellations_sha256' => $this->tableDigest('laboratory_order_cancellations', ['id', 'public_id', 'laboratory_order_id', 'actor_user_id', 'reason_code']),
                    'laboratory_specimen_attempts_sha256' => $this->tableDigest('laboratory_specimen_attempts', ['id', 'public_id', 'laboratory_order_id', 'attempt_number', 'collector_user_id', 'state', 'version']),
                    'laboratory_specimen_events_sha256' => $this->tableDigest('laboratory_specimen_events', ['id', 'public_id', 'laboratory_specimen_attempt_id', 'actor_user_id', 'event_type', 'reason_code']),
                    'laboratory_result_versions_sha256' => $this->tableDigest('laboratory_result_versions', ['id', 'public_id', 'laboratory_order_id', 'laboratory_specimen_attempt_id', 'author_user_id', 'version', 'state', 'content_digest']),
                    'laboratory_critical_communications_sha256' => $this->tableDigest('laboratory_critical_communications', ['id', 'public_id', 'laboratory_result_version_id', 'actor_user_id', 'recipient_user_id', 'outcome', 'content_digest']),
                    'laboratory_result_acknowledgements_sha256' => $this->tableDigest('laboratory_result_acknowledgements', ['id', 'public_id', 'laboratory_result_version_id', 'actor_user_id', 'emergency_follow_up_acceptance_id', 'result_fingerprint']),
                    'laboratory_operation_receipts_sha256' => $this->tableDigest('laboratory_operation_receipts', ['id', 'public_id', 'actor_user_id', 'operation', 'result_type', 'result_public_id', 'result_version', 'result_state', 'result_digest']),
                    'radiology_masters_sha256' => $this->tableDigest('radiology_examination_masters', ['id', 'public_id', 'examination_code', 'state', 'version']),
                    'radiology_master_versions_sha256' => $this->tableDigest('radiology_examination_master_versions', ['id', 'public_id', 'radiology_examination_master_id', 'version', 'state', 'content_digest']),
                    'radiology_orders_sha256' => $this->tableDigest('radiology_orders', ['id', 'public_id', 'encounter_id', 'master_id', 'ordered_by_user_id', 'status', 'version']),
                    'radiology_order_cancellations_sha256' => $this->tableDigest('radiology_order_cancellations', ['id', 'public_id', 'radiology_order_id', 'actor_user_id', 'reason_code']),
                    'radiology_performances_sha256' => $this->tableDigest('radiology_performances', ['id', 'public_id', 'radiology_order_id', 'performed_by_user_id']),
                    'radiology_report_versions_sha256' => $this->tableDigest('radiology_report_versions', ['id', 'public_id', 'radiology_order_id', 'author_user_id', 'base_verified_version_id', 'version', 'state', 'content_digest']),
                    'radiology_report_acknowledgements_sha256' => $this->tableDigest('radiology_report_acknowledgements', ['id', 'public_id', 'radiology_report_version_id', 'actor_user_id', 'emergency_follow_up_acceptance_id', 'report_fingerprint']),
                    'radiology_operation_receipts_sha256' => $this->tableDigest('radiology_operation_receipts', ['id', 'public_id', 'actor_user_id', 'operation', 'result_type', 'result_public_id', 'result_version', 'result_state', 'result_digest']),
                    'emergency_triage_vocabularies_sha256' => $this->tableDigest('emergency_triage_vocabularies', ['id', 'public_id', 'vocabulary_code', 'state', 'version', 'current_content_digest']),
                    'emergency_triage_vocabulary_versions_sha256' => $this->tableDigest('emergency_triage_vocabulary_versions', ['id', 'public_id', 'emergency_triage_vocabulary_id', 'actor_user_id', 'version', 'state', 'content_digest']),
                    'emergency_triage_assessments_sha256' => $this->tableDigest('emergency_triage_assessments', ['id', 'public_id', 'encounter_id', 'vocabulary_version_id', 'assessor_user_id', 'prior_assessment_id', 'assessment_number', 'assessment_type', 'category_code', 'content_digest']),
                    'emergency_clinical_documents_sha256' => $this->tableDigest('emergency_clinical_documents', ['id', 'public_id', 'encounter_id', 'document_type', 'state', 'version', 'current_content_digest']),
                    'emergency_clinical_document_versions_sha256' => $this->tableDigest('emergency_clinical_document_versions', ['id', 'public_id', 'emergency_clinical_document_id', 'author_user_id', 'version', 'state', 'content_digest']),
                    'emergency_follow_up_proposals_sha256' => $this->tableDigest('emergency_result_follow_up_proposals', ['id', 'public_id', 'encounter_id', 'proposed_by_user_id', 'proposed_to_user_id', 'prior_proposal_id', 'order_type', 'order_public_id', 'result_fingerprint', 'content_digest']),
                    'emergency_follow_up_acceptances_sha256' => $this->tableDigest('emergency_result_follow_up_acceptances', ['id', 'public_id', 'proposal_id', 'accepted_by_user_id', 'proposal_fingerprint', 'content_digest']),
                    'emergency_dispositions_sha256' => $this->tableDigest('emergency_dispositions', ['id', 'public_id', 'encounter_id', 'physician_user_id', 'prior_disposition_id', 'version', 'disposition_type', 'content_digest']),
                    'emergency_correction_intents_sha256' => $this->tableDigest('emergency_disposition_correction_intents', ['id', 'public_id', 'encounter_id', 'current_disposition_id', 'handoff_id', 'physician_user_id', 'replacement_type', 'content_digest']),
                    'emergency_correction_intent_events_sha256' => $this->tableDigest('emergency_disposition_correction_intent_events', ['id', 'public_id', 'correction_intent_id', 'actor_user_id', 'event_type', 'intent_fingerprint', 'content_digest']),
                    'emergency_inpatient_handoffs_sha256' => $this->tableDigest('emergency_inpatient_handoffs', ['id', 'public_id', 'source_encounter_id', 'disposition_id', 'target_encounter_id', 'inpatient_location_event_id', 'inpatient_bed_id', 'actor_user_id', 'inpatient_bed_version', 'content_digest']),
                    'emergency_handoff_compensations_sha256' => $this->tableDigest('emergency_handoff_compensations', ['id', 'public_id', 'handoff_id', 'correction_intent_id', 'replacement_disposition_id', 'actor_user_id', 'content_digest']),
                    'emergency_operation_receipts_sha256' => $this->tableDigest('emergency_operation_receipts', ['id', 'public_id', 'actor_user_id', 'operation', 'result_type', 'result_public_id', 'result_version', 'result_state', 'result_digest']),
                    'pharmacy_medicines_sha256' => $this->tableDigest('pharmacy_medicines', ['id', 'public_id', 'medicine_code', 'state', 'version', 'current_content_digest']),
                    'pharmacy_medicine_code_reservations_sha256' => $this->tableDigest('pharmacy_medicine_code_reservations', ['id', 'public_id', 'actor_user_id', 'normalized_code']),
                    'pharmacy_medicine_versions_sha256' => $this->tableDigest('pharmacy_medicine_versions', ['id', 'public_id', 'medicine_id', 'actor_user_id', 'version', 'state', 'content_digest']),
                    'pharmacy_depots_sha256' => $this->tableDigest('pharmacy_depots', ['id', 'public_id', 'depot_code', 'state', 'version', 'current_content_digest']),
                    'pharmacy_depot_code_reservations_sha256' => $this->tableDigest('pharmacy_depot_code_reservations', ['id', 'public_id', 'actor_user_id', 'normalized_code']),
                    'pharmacy_depot_versions_sha256' => $this->tableDigest('pharmacy_depot_versions', ['id', 'public_id', 'depot_id', 'actor_user_id', 'version', 'state', 'content_digest']),
                    'pharmacy_inventory_mutexes_sha256' => $this->tableDigest('pharmacy_inventory_mutexes', ['id', 'depot_id', 'medicine_id', 'lock_version']),
                    'pharmacy_stock_lots_sha256' => $this->tableDigest('pharmacy_stock_lots', ['id', 'public_id', 'medicine_id', 'depot_id', 'lot_code', 'available_quantity', 'quarantined_quantity', 'state', 'version', 'content_digest']),
                    'pharmacy_stock_movements_sha256' => $this->tableDigest('pharmacy_stock_movements', ['id', 'public_id', 'stock_lot_id', 'handover_item_id', 'movement_type', 'available_delta', 'quarantined_delta', 'available_balance_after', 'quarantined_balance_after', 'content_digest']),
                    'pharmacy_prescriptions_sha256' => $this->tableDigest('pharmacy_prescriptions', ['id', 'public_id', 'encounter_id', 'patient_id', 'depot_id', 'status', 'version', 'current_content_digest']),
                    'pharmacy_prescription_versions_sha256' => $this->tableDigest('pharmacy_prescription_versions', ['id', 'public_id', 'prescription_id', 'actor_user_id', 'version', 'state', 'content_digest']),
                    'pharmacy_prescription_items_sha256' => $this->tableDigest('pharmacy_prescription_items', ['id', 'public_id', 'prescription_id', 'medicine_id', 'requested_quantity', 'verified_quantity', 'content_digest']),
                    'pharmacy_verifications_sha256' => $this->tableDigest('pharmacy_verifications', ['id', 'public_id', 'prescription_id', 'pharmacist_user_id', 'decision', 'content_digest']),
                    'pharmacy_preparations_sha256' => $this->tableDigest('pharmacy_preparations', ['id', 'public_id', 'prescription_id', 'technician_user_id', 'sequence', 'state', 'content_digest']),
                    'pharmacy_preparation_allocations_sha256' => $this->tableDigest('pharmacy_preparation_allocations', ['id', 'public_id', 'preparation_id', 'prescription_item_id', 'stock_lot_id', 'quantity', 'content_digest']),
                    'pharmacy_handovers_sha256' => $this->tableDigest('pharmacy_handovers', ['id', 'public_id', 'prescription_id', 'preparation_id', 'sequence', 'state', 'content_digest']),
                    'pharmacy_handover_items_sha256' => $this->tableDigest('pharmacy_handover_items', ['id', 'public_id', 'handover_id', 'prescription_item_id', 'stock_lot_id', 'quantity', 'content_digest']),
                    'pharmacy_returns_sha256' => $this->tableDigest('pharmacy_returns', ['id', 'public_id', 'handover_id', 'pharmacist_user_id', 'content_digest']),
                    'pharmacy_return_items_sha256' => $this->tableDigest('pharmacy_return_items', ['id', 'public_id', 'return_id', 'handover_item_id', 'condition', 'quantity', 'content_digest']),
                    'pharmacy_financial_source_events_sha256' => $this->tableDigest('pharmacy_financial_source_events', ['id', 'public_id', 'prescription_id', 'prescription_item_id', 'event_type', 'quantity', 'amount', 'source_type', 'source_public_id', 'content_digest']),
                    'pharmacy_operation_receipts_sha256' => $this->tableDigest('pharmacy_operation_receipts', ['id', 'public_id', 'actor_user_id', 'operation', 'result_type', 'result_public_id', 'result_version', 'result_state', 'result_digest']),
                    'finance_charge_events_sha256' => $this->tableDigest('finance_charge_events', ['id', 'public_id', 'pharmacy_financial_source_event_id', 'finance_radiology_source_event_id', 'finance_laboratory_source_event_id', 'finance_accommodation_source_event_id', 'encounter_id', 'patient_id', 'imported_by_user_id', 'source_domain', 'source_table', 'source_public_id', 'source_content_digest', 'event_type', 'care_setting', 'quantity', 'unit_amount', 'signed_amount', 'content_digest', 'occurred_at']),
                    'finance_bills_sha256' => $this->tableDigest('finance_bills', ['id', 'public_id', 'bill_number', 'encounter_id', 'patient_id', 'care_setting', 'state', 'current_version', 'current_source_event_count', 'current_source_set_digest', 'current_issued_source_set_digest', 'current_content_digest']),
                    'finance_bill_versions_sha256' => $this->tableDigest('finance_bill_versions', ['id', 'public_id', 'bill_id', 'previous_version_id', 'issued_by_user_id', 'encounter_id', 'patient_id', 'version', 'source_set_digest', 'source_event_count', 'gross_amount', 'reversal_amount', 'net_amount', 'content_digest']),
                    'finance_bill_lines_sha256' => $this->tableDigest('finance_bill_lines', ['id', 'public_id', 'bill_version_id', 'charge_event_id', 'line_number', 'source_domain', 'source_public_id', 'source_content_digest', 'event_type', 'quantity', 'unit_amount', 'signed_amount', 'charge_event_content_digest', 'content_digest']),
                    'finance_operation_receipts_sha256' => $this->tableDigest('finance_operation_receipts', ['id', 'public_id', 'actor_user_id', 'operation', 'result_type', 'result_public_id', 'result_version', 'result_state', 'result_source_event_count', 'result_source_set_digest', 'result_digest']),
                    'finance_cash_settlements_sha256' => $this->tableDigest('finance_cash_settlements', ['id', 'public_id', 'receipt_number', 'bill_id', 'bill_version_id', 'encounter_id', 'patient_id', 'cashier_user_id', 'cashier_name_snapshot', 'bill_public_id_snapshot', 'bill_number_snapshot', 'bill_version_public_id_snapshot', 'bill_version_snapshot', 'encounter_public_id_snapshot', 'patient_public_id_snapshot', 'patient_name_snapshot', 'medical_record_number_snapshot', 'care_setting', 'coverage_profile_snapshot', 'coverage_label_snapshot', 'coverage_exclusion_snapshot', 'payment_method', 'state', 'amount', 'prior_net_collected_amount_snapshot', 'source_set_digest', 'bill_version_content_digest', 'content_digest', 'settled_at']),
                    'finance_settlement_operation_receipts_sha256' => $this->tableDigest('finance_settlement_operation_receipts', ['id', 'public_id', 'actor_user_id', 'operation', 'idempotency_key', 'payload_digest', 'settlement_public_id', 'bill_version_public_id', 'amount', 'source_set_digest', 'bill_version_content_digest', 'settlement_content_digest', 'result_digest', 'request_correlation_id', 'completed_at']),
                    'finance_settlement_correction_cases_sha256' => $this->tableDigest('finance_settlement_correction_cases', ['id', 'public_id', 'correction_number', 'settlement_id', 'bill_id', 'bill_version_id', 'requesting_cashier_user_id', 'requesting_cashier_name_snapshot', 'settlement_public_id_snapshot', 'receipt_number_snapshot', 'amount', 'settlement_content_digest', 'bill_public_id_snapshot', 'bill_version_public_id_snapshot', 'bill_version_snapshot', 'reason_code', 'explanation', 'content_digest', 'requested_at', 'created_at']),
                    'finance_settlement_correction_events_sha256' => $this->tableDigest('finance_settlement_correction_events', ['id', 'public_id', 'correction_case_id', 'sequence', 'event_type', 'previous_event_digest', 'actor_user_id', 'actor_name_snapshot', 'explanation', 'amount', 'original_settlement_content_digest', 'approval_event_digest', 'content_digest', 'occurred_at', 'created_at']),
                    'finance_settlement_correction_operation_receipts_sha256' => $this->tableDigest('finance_settlement_correction_operation_receipts', ['id', 'public_id', 'actor_user_id', 'correction_case_id', 'correction_event_id', 'operation', 'idempotency_key', 'payload_digest', 'result_type', 'result_public_id', 'case_content_digest', 'event_content_digest', 'result_digest', 'request_correlation_id', 'completed_at', 'created_at', 'updated_at']),
                    'finance_cashier_collection_batches_sha256' => $this->tableDigest('finance_cashier_collection_batches', ['id', 'public_id', 'batch_number', 'cashier_user_id', 'cashier_name_snapshot', 'content_digest', 'opened_at', 'created_at']),
                    'finance_cashier_collection_active_slots_sha256' => $this->tableDigest('finance_cashier_collection_active_slots', ['cashier_user_id', 'collection_batch_id', 'created_at']),
                    'finance_cashier_collection_members_sha256' => $this->tableDigest('finance_cashier_collection_members', ['id', 'public_id', 'collection_batch_id', 'settlement_id', 'cashier_user_id', 'cashier_name_snapshot', 'settlement_public_id_snapshot', 'receipt_number_snapshot', 'amount', 'settlement_content_digest', 'content_digest', 'collected_at', 'created_at']),
                    'finance_cashier_collection_events_sha256' => $this->tableDigest('finance_cashier_collection_events', ['id', 'public_id', 'collection_batch_id', 'sequence', 'event_type', 'previous_event_digest', 'actor_user_id', 'actor_name_snapshot', 'membership_count', 'gross_amount', 'completed_refund_amount', 'expected_net_amount', 'counted_amount', 'variance_amount', 'membership_digest', 'explanation', 'content_digest', 'occurred_at', 'created_at']),
                    'finance_cash_deposit_handoffs_sha256' => $this->tableDigest('finance_cash_deposit_handoffs', ['id', 'public_id', 'handoff_number', 'collection_batch_id', 'verified_event_id', 'cashier_user_id', 'supervisor_user_id', 'cashier_name_snapshot', 'supervisor_name_snapshot', 'batch_public_id_snapshot', 'batch_number_snapshot', 'membership_count', 'gross_amount', 'completed_refund_amount', 'expected_net_amount', 'counted_amount', 'batch_content_digest', 'verified_event_digest', 'content_digest', 'handed_off_at', 'created_at']),
                    'finance_cashier_collection_operation_receipts_sha256' => $this->tableDigest('finance_cashier_collection_operation_receipts', ['id', 'public_id', 'actor_user_id', 'collection_batch_id', 'collection_event_id', 'deposit_handoff_id', 'operation', 'idempotency_key', 'payload_digest', 'result_type', 'result_public_id', 'batch_content_digest', 'event_content_digest', 'handoff_content_digest', 'result_digest', 'request_correlation_id', 'completed_at', 'created_at', 'updated_at']),
                    'finance_radiology_tariff_bindings_sha256' => $this->tableDigest('finance_radiology_tariff_bindings', ['id', 'public_id', 'radiology_master_id', 'radiology_master_version_id', 'radiology_master_version_public_id', 'radiology_master_version', 'radiology_master_content_digest', 'radiology_master_code', 'care_setting', 'state', 'version', 'latest_effective_from', 'current_content_digest']),
                    'finance_radiology_tariff_binding_versions_sha256' => $this->tableDigest('finance_radiology_tariff_binding_versions', ['id', 'public_id', 'binding_id', 'tariff_item_id', 'actor_user_id', 'version', 'tariff_item_public_id', 'tariff_item_code', 'state', 'effective_from', 'previous_content_digest', 'content_digest']),
                    'finance_radiology_tariff_operation_receipts_sha256' => $this->tableDigest('finance_radiology_tariff_operation_receipts', ['id', 'public_id', 'actor_user_id', 'operation', 'idempotency_key', 'payload_digest', 'result_type', 'result_public_id', 'result_version', 'result_state', 'result_digest']),
                    'finance_radiology_source_events_sha256' => $this->tableDigest('finance_radiology_source_events', ['id', 'public_id', 'radiology_performance_id', 'radiology_order_id', 'binding_version_id', 'tariff_item_version_id', 'encounter_id', 'patient_id', 'imported_by_user_id', 'performance_public_id', 'performed_at', 'order_public_id', 'encounter_public_id', 'patient_public_id', 'care_setting', 'radiology_master_version_public_id', 'radiology_master_version', 'radiology_master_code', 'radiology_master_content_digest', 'binding_public_id', 'binding_version_public_id', 'binding_version', 'binding_content_digest', 'tariff_item_public_id', 'tariff_item_version_public_id', 'tariff_item_code', 'tariff_content_digest', 'component_public_id', 'component_code', 'component_content_digest', 'service_date', 'event_type', 'quantity', 'unit_amount', 'signed_amount', 'description', 'content_digest']),
                    'finance_laboratory_tariff_bindings_sha256' => $this->tableDigest('finance_laboratory_tariff_bindings', ['id', 'public_id', 'laboratory_master_id', 'laboratory_master_version_id', 'laboratory_master_version_public_id', 'laboratory_master_version', 'laboratory_master_content_digest', 'laboratory_master_code', 'care_setting', 'state', 'version', 'latest_effective_from', 'current_content_digest']),
                    'finance_laboratory_tariff_binding_versions_sha256' => $this->tableDigest('finance_laboratory_tariff_binding_versions', ['id', 'public_id', 'binding_id', 'tariff_item_id', 'actor_user_id', 'version', 'tariff_item_public_id', 'tariff_item_code', 'state', 'effective_from', 'previous_content_digest', 'content_digest']),
                    'finance_laboratory_tariff_operation_receipts_sha256' => $this->tableDigest('finance_laboratory_tariff_operation_receipts', ['id', 'public_id', 'actor_user_id', 'operation', 'idempotency_key', 'payload_digest', 'result_type', 'result_public_id', 'result_version', 'result_state', 'result_digest']),
                    'finance_laboratory_source_events_sha256' => $this->tableDigest('finance_laboratory_source_events', ['id', 'public_id', 'laboratory_result_version_id', 'laboratory_order_id', 'laboratory_specimen_attempt_id', 'laboratory_critical_communication_id', 'binding_version_id', 'tariff_item_version_id', 'encounter_id', 'patient_id', 'imported_by_user_id', 'result_public_id', 'result_version', 'result_content_digest', 'result_evidence_digest', 'verified_at', 'order_public_id', 'order_snapshot_digest', 'specimen_public_id', 'specimen_attempt_number', 'specimen_label_identifier', 'specimen_content_digest', 'critical_communication_public_id', 'critical_communication_content_digest', 'completion_evidence_digest', 'encounter_public_id', 'patient_public_id', 'care_setting', 'laboratory_master_version_public_id', 'laboratory_master_version', 'laboratory_master_code', 'laboratory_master_content_digest', 'binding_public_id', 'binding_version_public_id', 'binding_version', 'binding_content_digest', 'tariff_item_public_id', 'tariff_item_version_public_id', 'tariff_item_code', 'tariff_content_digest', 'component_public_id', 'component_code', 'component_content_digest', 'service_date', 'event_type', 'quantity', 'unit_amount', 'signed_amount', 'description', 'content_digest']),
                    'finance_accommodation_tariff_bindings_sha256' => $this->tableDigest('finance_accommodation_tariff_bindings', ['id', 'public_id', 'inpatient_bed_id', 'inpatient_bed_version_id', 'inpatient_bed_version_public_id', 'inpatient_bed_version', 'inpatient_bed_content_digest', 'ward_public_id', 'ward_code', 'bed_public_id', 'bed_code', 'service_class', 'care_setting', 'pricing_unit', 'state', 'version', 'latest_effective_from', 'current_content_digest']),
                    'finance_accommodation_tariff_binding_versions_sha256' => $this->tableDigest('finance_accommodation_tariff_binding_versions', ['id', 'public_id', 'binding_id', 'tariff_item_id', 'actor_user_id', 'version', 'tariff_item_public_id', 'tariff_item_code', 'state', 'effective_from', 'previous_content_digest', 'content_digest']),
                    'finance_accommodation_tariff_operation_receipts_sha256' => $this->tableDigest('finance_accommodation_tariff_operation_receipts', ['id', 'public_id', 'actor_user_id', 'operation', 'idempotency_key', 'payload_digest', 'result_type', 'result_public_id', 'result_version', 'result_state', 'result_digest']),
                    'finance_accommodation_source_events_sha256' => $this->tableDigest('finance_accommodation_source_events', ['id', 'public_id', 'inpatient_location_event_id', 'inpatient_bed_version_id', 'closing_location_event_id', 'inpatient_discharge_id', 'binding_version_id', 'tariff_item_version_id', 'encounter_id', 'patient_id', 'imported_by_user_id', 'opening_location_event_public_id', 'opening_location_event_digest', 'closing_type', 'closing_public_id', 'closing_content_digest', 'interval_start_at', 'interval_end_at', 'occupancy_anchor_at', 'encounter_public_id', 'patient_public_id', 'care_setting', 'ward_public_id', 'ward_code', 'bed_public_id', 'bed_code', 'bed_display_name', 'room_label', 'service_class', 'inpatient_bed_version_public_id', 'inpatient_bed_version', 'inpatient_bed_content_digest', 'binding_public_id', 'binding_version_public_id', 'binding_version', 'binding_content_digest', 'tariff_item_public_id', 'tariff_item_version_public_id', 'tariff_item_code', 'tariff_content_digest', 'component_public_id', 'component_code', 'component_content_digest', 'service_date', 'pricing_unit', 'event_type', 'quantity', 'unit_amount', 'signed_amount', 'description', 'content_digest']),
                    'finance_cost_component_groups_sha256' => $this->tableDigest('finance_cost_component_groups', ['id', 'public_id', 'group_code', 'display_name', 'state', 'version', 'current_content_digest']),
                    'finance_cost_component_group_versions_sha256' => $this->tableDigest('finance_cost_component_group_versions', ['id', 'public_id', 'group_id', 'actor_user_id', 'version', 'display_name', 'state', 'previous_content_digest', 'content_digest']),
                    'finance_cost_components_sha256' => $this->tableDigest('finance_cost_components', ['id', 'public_id', 'group_id', 'component_code', 'display_name', 'description', 'terminology_label', 'state', 'version', 'current_content_digest']),
                    'finance_cost_component_versions_sha256' => $this->tableDigest('finance_cost_component_versions', ['id', 'public_id', 'component_id', 'actor_user_id', 'version', 'group_public_id', 'group_code', 'display_name', 'description', 'terminology_label', 'state', 'previous_content_digest', 'content_digest']),
                    'finance_tariff_catalogues_sha256' => $this->tableDigest('finance_tariff_catalogues', ['id', 'public_id', 'catalogue_code', 'display_name', 'state', 'version', 'current_content_digest']),
                    'finance_tariff_catalogue_versions_sha256' => $this->tableDigest('finance_tariff_catalogue_versions', ['id', 'public_id', 'catalogue_id', 'actor_user_id', 'version', 'display_name', 'state', 'previous_content_digest', 'content_digest']),
                    'finance_tariff_items_sha256' => $this->tableDigest('finance_tariff_items', ['id', 'public_id', 'catalogue_id', 'component_id', 'tariff_code', 'state', 'version', 'latest_effective_from', 'current_content_digest']),
                    'finance_tariff_item_versions_sha256' => $this->tableDigest('finance_tariff_item_versions', ['id', 'public_id', 'tariff_item_id', 'actor_user_id', 'version', 'catalogue_public_id', 'catalogue_code', 'component_public_id', 'component_code', 'component_content_digest', 'display_name', 'care_setting', 'service_domain', 'reference_label', 'ward_class_label', 'amount_rupiah', 'state', 'effective_from', 'previous_content_digest', 'content_digest']),
                    'finance_tariff_code_reservations_sha256' => $this->tableDigest('finance_tariff_code_reservations', ['id', 'public_id', 'actor_user_id', 'reservation_type', 'normalized_code']),
                    'finance_tariff_operation_receipts_sha256' => $this->tableDigest('finance_tariff_operation_receipts', ['id', 'public_id', 'actor_user_id', 'operation', 'idempotency_key', 'payload_digest', 'result_type', 'result_public_id', 'result_version', 'result_state', 'result_digest']),
                    'inpatient_location_events_sha256' => $this->tableDigest('inpatient_location_events', ['id', 'public_id', 'encounter_id', 'actor_user_id', 'event_type', 'sequence', 'to_bed_public_id', 'payload_digest']),
                    'inpatient_beds_sha256' => $this->tableDigest('inpatient_beds', ['id', 'public_id', 'ward_id', 'code', 'state', 'version']),
                    'inpatient_patient_claim_mutexes_sha256' => $this->tableDigest('inpatient_patient_claim_mutexes', ['patient_id']),
                    'active_inpatient_patient_claims_sha256' => $this->filteredTableDigest('encounters', ['id', 'public_id', 'patient_id', 'active_inpatient_patient_id', 'status'], 'active_inpatient_patient_id'),
                    'outpatient_documents_sha256' => $this->tableDigest('outpatient_clinical_documents', ['id', 'public_id', 'encounter_id', 'document_type', 'document_state', 'version']),
                    'outpatient_document_versions_sha256' => $this->tableDigest('outpatient_clinical_document_versions', ['id', 'public_id', 'outpatient_clinical_document_id', 'version', 'actor_user_id']),
                    'rm_reviews_sha256' => $this->tableDigest('outpatient_rm_completeness_reviews', ['id', 'public_id', 'encounter_id', 'version', 'review_state']),
                    'audit_events_sha256' => $this->tableDigest('audit_events', ['id', 'actor_user_id', 'actor_type', 'actor_reference', 'action', 'resource_type', 'resource_id', 'outcome', 'reason']),
                ],
            ];

            $payload['snapshot_sha256'] = hash('sha256', CanonicalJson::encode($payload));

            return $payload;
        }, 1);

        return $snapshot;
    }

    private function assertSafeBoundary(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Recovery verification requires SIMULATION mode with synthetic-only enforcement.');
        }

        if (config('database.default') !== 'pgsql' || DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Recovery verification requires a PostgreSQL connection.');
        }

        $version = (int) DB::scalar('SHOW server_version_num');
        if (intdiv($version, 10_000) !== 17) {
            throw new RuntimeException('Recovery verification requires PostgreSQL major version 17.');
        }

        $database = (string) DB::scalar('SELECT current_database()');
        if (preg_match(self::DATABASE_NAME_PATTERN, $database) !== 1) {
            throw new RuntimeException('Recovery verification refuses a database outside the generated disposable namespace.');
        }

        if (SchemaQualifier::primarySchema() !== 'laravel') {
            throw new RuntimeException('Recovery verification requires the private laravel schema.');
        }

        $publicApplicationTables = DB::table('information_schema.tables')
            ->where('table_schema', 'public')
            ->whereIn('table_name', ['users', 'patients', 'encounters', 'audit_events', 'migrations'])
            ->count();
        if ($publicApplicationTables !== 0) {
            throw new RuntimeException('Recovery verification found application tables in the public schema.');
        }
    }

    /** @return list<array{migration: string, sha256: string}> */
    private function migrationFileRows(): array
    {
        $files = collect(File::files(database_path('migrations')))
            ->sortBy(fn (\SplFileInfo $file): string => $file->getFilename());
        $rows = [];

        foreach ($files as $file) {
            $sha256 = hash_file('sha256', $file->getPathname());
            if (! is_string($sha256)) {
                throw new RuntimeException('A migration file could not be hashed for recovery verification.');
            }

            $rows[] = [
                'migration' => $file->getBasename('.php'),
                'sha256' => $sha256,
            ];
        }

        return $rows;
    }

    /**
     * The warehouse migration is deliberately shippable before its separately
     * governed exact-engine cutover. It may therefore be the sole file absent
     * from a valid recovery ledger; every other missing, extra, duplicate, or
     * reordered migration remains drift.
     *
     * @param  array<int, array{migration: string, batch: int}>  $migrationRows
     * @param  list<array{migration: string, sha256: string}>  $expectedMigrations
     */
    private function assertMigrationLedgerMatchesCheckout(array $migrationRows, array $expectedMigrations): void
    {
        $actual = array_column($migrationRows, 'migration');
        $expected = array_column($expectedMigrations, 'migration');
        $expectedWithoutGovernedWarehouse = array_values(array_filter(
            $expected,
            static fn (string $migration): bool => $migration !== self::GOVERNED_OPTIONAL_MIGRATION,
        ));

        if ($actual === $expected || $actual === $expectedWithoutGovernedWarehouse) {
            return;
        }

        throw new RuntimeException('The disposable recovery database migration ledger does not match this checkout.');
    }

    /** @param list<string> $columns */
    private function tableDigest(string $table, array $columns): string
    {
        $orderColumn = in_array('id', $columns, true) ? 'id' : $columns[0];
        $rows = DB::table(SchemaQualifier::table($table))
            ->orderBy($orderColumn)
            ->get($columns)
            ->map(fn (object $row): array => collect((array) $row)
                ->map(fn (mixed $value): mixed => is_bool($value) ? $value : ($value === null ? null : (string) $value))
                ->all())
            ->all();

        return $this->digest($rows);
    }

    /** @param list<string> $columns */
    private function filteredTableDigest(string $table, array $columns, string $notNullColumn): string
    {
        $rows = DB::table(SchemaQualifier::table($table))
            ->whereNotNull($notNullColumn)
            ->orderBy(in_array('id', $columns, true) ? 'id' : $columns[0])
            ->get($columns)
            ->map(fn (object $row): array => collect((array) $row)
                ->map(fn (mixed $value): mixed => is_bool($value) ? $value : ($value === null ? null : (string) $value))
                ->all())
            ->all();

        return $this->digest($rows);
    }

    private function orphanCount(string $child, string $parent, string $foreignKey): int
    {
        return DB::table(SchemaQualifier::table($child).' as child')
            ->leftJoin(SchemaQualifier::table($parent).' as parent', 'parent.id', '=', 'child.'.$foreignKey)
            ->whereNotNull('child.'.$foreignKey)
            ->whereNull('parent.id')
            ->count();
    }

    private function crossPatientHandoffCount(): int
    {
        $handoffs = SchemaQualifier::table('emergency_inpatient_handoffs');
        $encounters = SchemaQualifier::table('encounters');

        return DB::table($handoffs.' as handoff')
            ->join($encounters.' as source', 'source.id', '=', 'handoff.source_encounter_id')
            ->join($encounters.' as target', 'target.id', '=', 'handoff.target_encounter_id')
            ->whereColumn('source.patient_id', '!=', 'target.patient_id')
            ->count();
    }

    private function duplicateActiveInpatientClaimCount(): int
    {
        $duplicates = DB::table(SchemaQualifier::table('encounters'))
            ->select('active_inpatient_patient_id')
            ->whereNotNull('active_inpatient_patient_id')
            ->groupBy('active_inpatient_patient_id')
            ->havingRaw('COUNT(*) > 1');

        return DB::query()->fromSub($duplicates, 'duplicate_claims')->count();
    }

    private function uncompensatedCancelledChildCount(): int
    {
        $handoffs = SchemaQualifier::table('emergency_inpatient_handoffs');
        $compensations = SchemaQualifier::table('emergency_handoff_compensations');
        $encounters = SchemaQualifier::table('encounters');

        return DB::table($handoffs.' as handoff')
            ->join($encounters.' as target', 'target.id', '=', 'handoff.target_encounter_id')
            ->leftJoin($compensations.' as compensation', 'compensation.handoff_id', '=', 'handoff.id')
            ->whereNull('compensation.id')
            ->where('target.status', 'CANCELLED')
            ->count();
    }

    private function compensatedRetainedBedOrClaimCount(): int
    {
        $handoffs = SchemaQualifier::table('emergency_inpatient_handoffs');
        $compensations = SchemaQualifier::table('emergency_handoff_compensations');
        $encounters = SchemaQualifier::table('encounters');

        return DB::table($compensations.' as compensation')
            ->join($handoffs.' as handoff', 'handoff.id', '=', 'compensation.handoff_id')
            ->join($encounters.' as target', 'target.id', '=', 'handoff.target_encounter_id')
            ->where(function ($query): void {
                $query->whereNotNull('target.inpatient_bed_id')
                    ->orWhereNotNull('target.active_inpatient_patient_id');
            })
            ->count();
    }

    private function compensatedNonCancelledChildCount(): int
    {
        $handoffs = SchemaQualifier::table('emergency_inpatient_handoffs');
        $compensations = SchemaQualifier::table('emergency_handoff_compensations');
        $encounters = SchemaQualifier::table('encounters');

        return DB::table($compensations.' as compensation')
            ->join($handoffs.' as handoff', 'handoff.id', '=', 'compensation.handoff_id')
            ->join($encounters.' as target', 'target.id', '=', 'handoff.target_encounter_id')
            ->where('target.status', '!=', 'CANCELLED')
            ->count();
    }

    private function pharmacyStockBalanceMismatchCount(): int
    {
        $lots = DB::table(SchemaQualifier::table('pharmacy_stock_lots'))->orderBy('id')->get();
        $mismatches = 0;
        foreach ($lots as $lot) {
            $movements = DB::table(SchemaQualifier::table('pharmacy_stock_movements'))
                ->where('stock_lot_id', $lot->id)->orderBy('id')->get();
            $last = $movements->last();
            $available = 0;
            $quarantined = 0;
            $chainValid = true;
            foreach ($movements as $movement) {
                $available += (int) $movement->available_delta;
                $quarantined += (int) $movement->quarantined_delta;
                if ($available !== (int) $movement->available_balance_after || $quarantined !== (int) $movement->quarantined_balance_after) {
                    $chainValid = false;
                }
            }
            if (! $chainValid
                || (int) $movements->sum('available_delta') !== (int) $lot->available_quantity
                || (int) $movements->sum('quarantined_delta') !== (int) $lot->quarantined_quantity
                || $last === null
                || (int) $last->available_balance_after !== (int) $lot->available_quantity
                || (int) $last->quarantined_balance_after !== (int) $lot->quarantined_quantity) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function pharmacyFinancialSourceMismatchCount(): int
    {
        $events = DB::table(SchemaQualifier::table('pharmacy_financial_source_events'))->orderBy('id')->get();
        $mismatches = 0;
        foreach ($events as $event) {
            if ($event->event_type === 'CHARGE' && $event->source_type === 'HANDOVER_ITEM') {
                $source = DB::table(SchemaQualifier::table('pharmacy_handover_items'))->where('public_id', $event->source_public_id)->first();
                if ($source === null || (int) $event->quantity !== (int) $source->quantity || (int) $event->amount !== (int) $source->quantity * (int) $source->sale_value_snapshot) {
                    $mismatches++;
                }
            } elseif ($event->event_type === 'REVERSAL' && $event->source_type === 'RETURN_ITEM') {
                $source = DB::table(SchemaQualifier::table('pharmacy_return_items').' as ri')
                    ->join(SchemaQualifier::table('pharmacy_handover_items').' as hi', 'hi.id', '=', 'ri.handover_item_id')
                    ->where('ri.public_id', $event->source_public_id)->first(['ri.quantity', 'hi.sale_value_snapshot']);
                if ($source === null || (int) $event->quantity !== (int) $source->quantity || (int) $event->amount !== -((int) $source->quantity * (int) $source->sale_value_snapshot)) {
                    $mismatches++;
                }
            } else {
                $mismatches++;
            }
        }
        foreach (DB::table(SchemaQualifier::table('pharmacy_handover_items'))->get() as $source) {
            if (DB::table(SchemaQualifier::table('pharmacy_financial_source_events'))->where(['event_type' => 'CHARGE', 'source_type' => 'HANDOVER_ITEM', 'source_public_id' => $source->public_id])->count() !== 1) {
                $mismatches++;
            }
        }
        foreach (DB::table(SchemaQualifier::table('pharmacy_return_items'))->get() as $source) {
            if (DB::table(SchemaQualifier::table('pharmacy_financial_source_events'))->where(['event_type' => 'REVERSAL', 'source_type' => 'RETURN_ITEM', 'source_public_id' => $source->public_id])->count() !== 1) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function pharmacyReceiptResultMismatchCount(): int
    {
        $tables = [
            'MEDICINE' => 'pharmacy_medicines', 'DEPOT' => 'pharmacy_depots', 'LOT' => 'pharmacy_stock_lots',
            'PRESCRIPTION' => 'pharmacy_prescriptions', 'VERIFICATION' => 'pharmacy_verifications',
            'PREPARATION' => 'pharmacy_preparations', 'HANDOVER' => 'pharmacy_handovers', 'RETURN' => 'pharmacy_returns',
        ];
        $mismatches = 0;
        foreach (DB::table(SchemaQualifier::table('pharmacy_operation_receipts'))->get() as $receipt) {
            $table = $tables[$receipt->result_type] ?? null;
            if ($table === null || ! DB::table(SchemaQualifier::table($table))->where('public_id', $receipt->result_public_id)->exists()) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function pharmacyPrescriptionStateMismatchCount(): int
    {
        $prescriptions = DB::table(SchemaQualifier::table('pharmacy_prescriptions'))->orderBy('id')->get();
        $mismatches = 0;
        foreach ($prescriptions as $prescription) {
            $head = DB::table(SchemaQualifier::table('pharmacy_prescription_versions'))
                ->where('prescription_id', $prescription->id)->where('version', $prescription->version)->first();
            $verification = DB::table(SchemaQualifier::table('pharmacy_verifications'))->where('prescription_id', $prescription->id)->first();
            $verified = 0;
            if ($verification !== null) {
                $decisions = json_decode((string) $verification->item_decisions, true, flags: JSON_THROW_ON_ERROR);
                if (is_array($decisions)) {
                    foreach ($decisions as $decision) {
                        if (! is_array($decision)) {
                            continue;
                        }
                        $quantity = $decision['verified_quantity'] ?? null;
                        if (is_int($quantity) || (is_string($quantity) && ctype_digit($quantity))) {
                            $verified += (int) $quantity;
                        }
                    }
                }
            }
            $handed = (int) DB::table(SchemaQualifier::table('pharmacy_handover_items').' as hi')
                ->join(SchemaQualifier::table('pharmacy_handovers').' as h', 'h.id', '=', 'hi.handover_id')
                ->where('h.prescription_id', $prescription->id)->sum('hi.quantity');
            $activePreparation = DB::table(SchemaQualifier::table('pharmacy_preparations').' as prep')
                ->leftJoin(SchemaQualifier::table('pharmacy_handovers').' as h', 'h.preparation_id', '=', 'prep.id')
                ->where('prep.prescription_id', $prescription->id)->where('prep.state', 'ACTIVE')->whereNull('h.id')->count();
            $validState = match ($prescription->status) {
                'DRAFT' => $verification === null && $handed === 0,
                'ORDERED' => $verification === null && $handed === 0,
                'VERIFIED' => $verification?->decision === 'VERIFIED' && $verified > 0 && $handed === 0 && $activePreparation === 0,
                'PREPARED' => $verification?->decision === 'VERIFIED' && $activePreparation === 1,
                'PARTIALLY_HANDED_OVER' => $verified > 0 && $handed > 0 && $handed < $verified && $activePreparation === 0,
                'HANDED_OVER' => $verified > 0 && $handed === $verified && $activePreparation === 0,
                'UNFILLED_CLOSED' => $verified > 0 && $handed > 0 && $handed < $verified && $activePreparation === 0,
                'REFUSED' => $verification?->decision === 'REFUSED' && $handed === 0,
                'CANCELLED' => $handed === 0,
                default => false,
            };
            if ($head === null || $head->state !== $prescription->status || $head->content_digest !== $prescription->current_content_digest || ! $validState) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function financeRetainedSourceMismatchCount(): int
    {
        $mismatches = 0;
        $groups = FinanceChargeEvent::query()->orderBy('id')->get()->groupBy('encounter_id');
        foreach ($groups as $encounterId => $events) {
            $encounter = Encounter::query()->with('patient')->whereKey($encounterId)->first();
            if (! $encounter instanceof Encounter) {
                $mismatches += $events->count();

                continue;
            }
            try {
                app(FinanceSourceCoordinator::class)->verifyRetained($encounter, $events->values());
            } catch (FinanceDenied) {
                $mismatches += $events->count();
            }
        }

        return $mismatches;
    }

    private function financeTypedSourceOrphanCount(string $domain): int
    {
        [$foreignKey, $parent] = match ($domain) {
            FinanceChargeEvent::SOURCE_PHARMACY => ['pharmacy_financial_source_event_id', 'pharmacy_financial_source_events'],
            FinanceChargeEvent::SOURCE_RADIOLOGY => ['finance_radiology_source_event_id', 'finance_radiology_source_events'],
            FinanceChargeEvent::SOURCE_LABORATORY => ['finance_laboratory_source_event_id', 'finance_laboratory_source_events'],
            FinanceChargeEvent::SOURCE_ACCOMMODATION => ['finance_accommodation_source_event_id', 'finance_accommodation_source_events'],
            default => throw new RuntimeException('Unknown typed finance source domain.'),
        };

        return (int) DB::table(SchemaQualifier::table('finance_charge_events').' as child')
            ->leftJoin(SchemaQualifier::table($parent).' as parent', 'parent.id', '=', 'child.'.$foreignKey)
            ->where('child.source_domain', $domain)
            ->whereNull('parent.id')
            ->count();
    }

    private function financeTypedSourceMismatchCount(): int
    {
        $mismatches = 0;
        foreach (DB::table(SchemaQualifier::table('finance_charge_events'))->orderBy('id')->get() as $event) {
            $pharmacy = $event->pharmacy_financial_source_event_id !== null;
            $radiology = $event->finance_radiology_source_event_id !== null;
            $laboratory = $event->finance_laboratory_source_event_id !== null;
            $accommodation = $event->finance_accommodation_source_event_id !== null;
            $valid = ($event->source_domain === FinanceChargeEvent::SOURCE_PHARMACY
                    && $event->source_table === FinanceChargeEvent::SOURCE_TABLE_PHARMACY
                    && $pharmacy && ! $radiology && ! $laboratory && ! $accommodation)
                || ($event->source_domain === FinanceChargeEvent::SOURCE_RADIOLOGY
                    && $event->source_table === FinanceChargeEvent::SOURCE_TABLE_RADIOLOGY
                    && ! $pharmacy && $radiology && ! $laboratory && ! $accommodation)
                || ($event->source_domain === FinanceChargeEvent::SOURCE_LABORATORY
                    && $event->source_table === FinanceChargeEvent::SOURCE_TABLE_LABORATORY
                    && ! $pharmacy && ! $radiology && $laboratory && ! $accommodation)
                || ($event->source_domain === FinanceChargeEvent::SOURCE_ACCOMMODATION
                    && $event->source_table === FinanceChargeEvent::SOURCE_TABLE_ACCOMMODATION
                    && ! $pharmacy && ! $radiology && ! $laboratory && $accommodation);
            if (! $valid) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function financeLineSourceMismatchCount(): int
    {
        $fingerprints = new FinanceEvidenceFingerprint;
        $mismatches = 0;
        foreach (FinanceBillVersion::query()->with('lines')->orderBy('id')->get() as $version) {
            $seen = [];
            foreach ($version->lines->sortBy('line_number')->values() as $index => $line) {
                $event = FinanceChargeEvent::query()->find($line->charge_event_id);
                if (! $event || isset($seen[$line->charge_event_id]) || $line->line_number !== $index + 1
                    || $event->encounter_id !== $version->encounter_id || $event->patient_id !== $version->patient_id) {
                    $mismatches++;

                    continue;
                }
                $seen[$line->charge_event_id] = true;
                foreach ([
                    'source_domain', 'source_public_id', 'source_content_digest', 'event_type', 'quantity',
                    'unit_amount', 'signed_amount', 'description', 'content_digest' => 'charge_event_content_digest',
                ] as $eventField => $lineField) {
                    if (is_int($eventField)) {
                        $eventField = $lineField;
                    }
                    if ((string) $event->getAttribute($eventField) !== (string) $line->getAttribute($lineField)) {
                        $mismatches++;

                        continue 2;
                    }
                }
                if (! $line->occurred_at->equalTo($event->occurred_at)
                    || ! hash_equals((string) $line->content_digest, $fingerprints->line($event, $line->line_number))) {
                    $mismatches++;
                }
            }
        }

        return $mismatches;
    }

    private function financeVersionControlMismatchCount(): int
    {
        $fingerprints = new FinanceEvidenceFingerprint;
        $mismatches = 0;
        foreach (FinanceBillVersion::query()->with('lines')->orderBy('id')->get() as $version) {
            $lines = $version->lines->sortBy('line_number')->values();
            $events = FinanceChargeEvent::query()->whereIn('id', $lines->pluck('charge_event_id'))->get();
            $gross = (int) $events->where('event_type', FinanceChargeEvent::CHARGE)->sum('signed_amount');
            $reversal = (int) $events->where('event_type', FinanceChargeEvent::REVERSAL)->sum('signed_amount');
            $cutoff = $events->max('occurred_at');
            if ($lines->count() !== $version->source_event_count
                || $events->count() !== $version->source_event_count
                || ! hash_equals((string) $version->source_set_digest, $fingerprints->sourceSet($events))
                || $version->gross_amount !== $gross
                || $version->reversal_amount !== $reversal
                || $version->net_amount !== $gross + $reversal
                || ! $version->source_cutoff_at->equalTo($cutoff)
                || ! hash_equals((string) $version->content_digest, $fingerprints->version($version, $lines))) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function financePreviousVersionChainMismatchCount(): int
    {
        $mismatches = 0;
        foreach (FinanceBill::query()->with('versions')->orderBy('id')->get() as $bill) {
            $previous = null;
            foreach ($bill->versions->sortBy('version')->values() as $index => $version) {
                if ($version->version !== $index + 1
                    || $version->previous_version_id !== $previous?->id
                    || $version->bill_id !== $bill->id
                    || $version->encounter_id !== $bill->encounter_id
                    || $version->patient_id !== $bill->patient_id) {
                    $mismatches++;
                }
                $previous = $version;
            }
        }

        return $mismatches;
    }

    private function financeBillHeadMismatchCount(): int
    {
        $fingerprints = new FinanceEvidenceFingerprint;
        $mismatches = 0;
        foreach (FinanceBill::query()->with('versions')->orderBy('id')->get() as $bill) {
            $events = FinanceChargeEvent::query()->where('encounter_id', $bill->encounter_id)->get();
            $sourceDigest = $fingerprints->sourceSet($events);
            $latest = $bill->versions->sortByDesc('version')->first();
            $state = $latest === null
                ? FinanceBill::OPEN_NO_VERSION
                : (hash_equals((string) $latest->source_set_digest, $sourceDigest)
                    ? FinanceBill::ISSUED_CURRENT
                    : FinanceBill::NEW_SOURCE_PENDING);
            $currentVersion = $latest === null ? 0 : $latest->version;
            $issuedSourceDigest = $latest === null ? null : $latest->source_set_digest;
            if ($bill->current_source_event_count !== $events->count()
                || ! hash_equals((string) $bill->current_source_set_digest, $sourceDigest)
                || $bill->current_version !== $currentVersion
                || $bill->current_issued_source_set_digest !== $issuedSourceDigest
                || $bill->state !== $state
                || ! hash_equals((string) $bill->current_content_digest, $fingerprints->billContent($bill))) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function financeReceiptResultMismatchCount(): int
    {
        $fingerprints = new FinanceEvidenceFingerprint;
        $mismatches = 0;
        foreach (FinanceOperationReceipt::query()->orderBy('id')->get() as $receipt) {
            $bill = null;
            $version = null;
            if ($receipt->result_type === FinanceOperationReceipt::RESULT_BILL) {
                $bill = FinanceBill::query()->where('public_id', $receipt->result_public_id)->first();
            } elseif ($receipt->result_type === FinanceOperationReceipt::RESULT_BILL_VERSION) {
                $version = FinanceBillVersion::query()->with('lines')->where('public_id', $receipt->result_public_id)->first();
                $bill = $version?->bill()->first();
            }
            if (! $bill || ($version && $version->version !== $receipt->result_version)) {
                $mismatches++;

                continue;
            }
            $events = FinanceChargeEvent::query()->where('encounter_id', $bill->encounter_id)
                ->orderBy('occurred_at')->orderBy('source_domain')->orderBy('source_public_id')->orderBy('id')
                ->limit($receipt->result_source_event_count)->get();
            $sourceDigest = $fingerprints->sourceSet($events);
            $evidenceDigest = $version?->content_digest;
            $expected = FinanceCanonicalJson::digest([
                $receipt->result_type, $receipt->result_public_id, $receipt->result_version,
                $receipt->result_state, $events->count(), $sourceDigest, $evidenceDigest,
            ]);
            if ($events->count() !== $receipt->result_source_event_count
                || ! hash_equals((string) $receipt->result_source_set_digest, $sourceDigest)
                || ($version && (! hash_equals((string) $version->source_set_digest, $sourceDigest)
                    || ! hash_equals((string) $version->content_digest, $fingerprints->version($version, $version->lines))))
                || ! hash_equals((string) $receipt->result_digest, $expected)) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function financeCashSettlementMismatchCount(): int
    {
        $fingerprints = new FinanceCashSettlementFingerprint;
        $mismatches = 0;

        foreach (FinanceCashSettlement::query()->with(['bill', 'billVersion'])->orderBy('id')->get() as $settlement) {
            $bill = $settlement->bill;
            $version = $settlement->billVersion;
            $encounter = Encounter::query()->find($settlement->encounter_id);
            $patient = $encounter?->patient()->first();
            $priorSettledAmount = (int) FinanceCashSettlement::query()
                ->where('bill_id', $settlement->bill_id)
                ->where(function ($query) use ($settlement): void {
                    $query->where('bill_version_snapshot', '<', $settlement->bill_version_snapshot)
                        ->orWhere(function ($query) use ($settlement): void {
                            $query->where('bill_version_snapshot', $settlement->bill_version_snapshot)
                                ->where('id', '<', $settlement->id);
                        });
                })
                ->sum('amount');
            $priorNetCollected = $settlement->prior_net_collected_amount_snapshot ?? $priorSettledAmount;
            $expectedOutstanding = $version === null ? null : $version->net_amount - $priorNetCollected;

            if (! $bill || ! $version || ! $encounter || ! $patient
                || $version->bill_id !== $bill->id
                || $settlement->encounter_id !== $bill->encounter_id
                || $settlement->encounter_id !== $version->encounter_id
                || $settlement->patient_id !== $bill->patient_id
                || $settlement->patient_id !== $version->patient_id
                || $settlement->patient_id !== $encounter->patient_id
                || $settlement->bill_public_id_snapshot !== $bill->public_id
                || $settlement->bill_number_snapshot !== $bill->bill_number
                || $settlement->bill_version_public_id_snapshot !== $version->public_id
                || $settlement->bill_version_snapshot !== $version->version
                || $settlement->encounter_public_id_snapshot !== $encounter->public_id
                || $settlement->patient_public_id_snapshot !== $patient->public_id
                || $settlement->care_setting !== $bill->care_setting
                || $settlement->care_setting !== $version->care_setting
                || $settlement->coverage_profile_snapshot !== $version->coverage_profile
                || $settlement->payment_method !== FinanceCashSettlement::PAYMENT_CASH
                || $settlement->state !== FinanceCashSettlement::SETTLED
                || $priorNetCollected < 0
                || $priorNetCollected > $priorSettledAmount
                || $settlement->amount !== $expectedOutstanding
                || $settlement->amount <= 0
                || ! hash_equals($settlement->source_set_digest, $version->source_set_digest)
                || ! hash_equals($settlement->bill_version_content_digest, $version->content_digest)
                || ! hash_equals($settlement->content_digest, $fingerprints->settlement($settlement))) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function financeSettlementReceiptMismatchCount(): int
    {
        $mismatches = 0;

        foreach (FinanceSettlementOperationReceipt::query()->orderBy('id')->get() as $receipt) {
            $settlement = FinanceCashSettlement::query()
                ->where('public_id', $receipt->settlement_public_id)
                ->first();
            $expected = $settlement === null ? null : FinanceCanonicalJson::digest([
                FinanceCashSettlementService::OPERATION_SETTLE,
                $settlement->public_id,
                $settlement->content_digest,
                $settlement->bill_version_public_id_snapshot,
                $settlement->bill_version_content_digest,
                $settlement->amount,
                $settlement->source_set_digest,
            ]);

            if (! $settlement
                || $receipt->actor_user_id !== $settlement->cashier_user_id
                || $receipt->operation !== FinanceCashSettlementService::OPERATION_SETTLE
                || $receipt->bill_version_public_id !== $settlement->bill_version_public_id_snapshot
                || $receipt->amount !== $settlement->amount
                || ! hash_equals($receipt->source_set_digest, $settlement->source_set_digest)
                || ! hash_equals($receipt->bill_version_content_digest, $settlement->bill_version_content_digest)
                || ! hash_equals($receipt->settlement_content_digest, $settlement->content_digest)
                || preg_match('/\A[a-f0-9]{64}\z/', $receipt->payload_digest) !== 1
                || ! is_string($expected)
                || ! hash_equals($receipt->result_digest, $expected)) {
                $mismatches++;
            }
        }

        foreach (FinanceCashSettlement::query()->orderBy('id')->get() as $settlement) {
            if (FinanceSettlementOperationReceipt::query()
                ->where('operation', FinanceCashSettlementService::OPERATION_SETTLE)
                ->where('settlement_public_id', $settlement->public_id)
                ->count() !== 1) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function financeCashSettlementAuditMismatchCount(): int
    {
        $mismatches = 0;

        foreach (FinanceCashSettlement::query()->orderBy('id')->get() as $settlement) {
            $events = AuditEvent::query()
                ->where('action', 'finance.workflow.mutate')
                ->where('resource_type', 'finance_record')
                ->where('resource_id', $settlement->bill_public_id_snapshot)
                ->where('actor_user_id', $settlement->cashier_user_id)
                ->where('outcome', 'SUCCESS')
                ->get();
            $matches = $events->filter(function (AuditEvent $event) use ($settlement): bool {
                if (($event->metadata['operation'] ?? null) !== FinanceCashSettlementService::OPERATION_SETTLE
                    || ($event->metadata['state'] ?? null) !== FinanceBill::ISSUED_CURRENT
                    || (int) ($event->metadata['version'] ?? 0) !== $settlement->bill_version_snapshot) {
                    return false;
                }

                if ($settlement->prior_net_collected_amount_snapshot === null) {
                    return ! array_key_exists('settlement_public_id', $event->metadata)
                        && ! array_key_exists('settlement_content_digest', $event->metadata);
                }

                return ($event->metadata['settlement_public_id'] ?? null) === $settlement->public_id
                    && ($event->metadata['settlement_content_digest'] ?? null) === $settlement->content_digest
                    && ($event->metadata['settlement_result_digest'] ?? null) === (new FinanceCashSettlementFingerprint)->result($settlement);
            })->count();

            if ($matches !== 1) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function financeCashSettlementNetEquationMismatchCount(): int
    {
        $netPolicy = app(FinanceCashSettlementNetPolicy::class);
        $mismatches = 0;

        $billIds = FinanceCashSettlement::query()->distinct()->orderBy('bill_id')->pluck('bill_id');
        foreach ($billIds as $billId) {
            $settlements = FinanceCashSettlement::query()
                ->where('bill_id', $billId)
                ->orderBy('bill_version_snapshot')->orderBy('id')->get();
            $manualNet = 0;
            $priorGross = 0;
            $invalid = false;

            try {
                foreach ($settlements as $settlement) {
                    $version = FinanceBillVersion::query()->find($settlement->bill_version_id);
                    $priorNetSnapshot = $settlement->prior_net_collected_amount_snapshot ?? $priorGross;
                    $state = $netPolicy->correctionState($settlement);
                    if (! $version
                        || $priorNetSnapshot < 0
                        || $priorNetSnapshot > $priorGross
                        || $settlement->amount + $priorNetSnapshot !== $version->net_amount) {
                        $invalid = true;
                    }
                    $manualNet += $settlement->amount - $state['refunded_amount'];
                    $priorGross += $settlement->amount;
                }

                if ($manualNet < 0 || $manualNet !== $netPolicy->netCollected($settlements)) {
                    $invalid = true;
                }
            } catch (FinanceDenied) {
                $invalid = true;
            }

            if ($invalid) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function financeSettlementCorrectionMismatchCount(): int
    {
        $netPolicy = app(FinanceCashSettlementNetPolicy::class);
        $mismatches = 0;

        foreach (FinanceSettlementCorrectionCase::query()->orderBy('id')->get() as $case) {
            $settlement = FinanceCashSettlement::query()->find($case->settlement_id);
            try {
                $state = $settlement ? $netPolicy->correctionState($settlement) : null;
                if (! is_array($state)
                    || ! $state['case'] instanceof FinanceSettlementCorrectionCase
                    || $state['case']->id !== $case->id) {
                    $mismatches++;
                }
            } catch (FinanceDenied) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function financeSettlementCorrectionReceiptMismatchCount(): int
    {
        $fingerprints = app(FinanceCashSettlementCorrectionFingerprint::class);
        $mismatches = 0;

        foreach (FinanceSettlementCorrectionOperationReceipt::query()->orderBy('id')->get() as $receipt) {
            $case = FinanceSettlementCorrectionCase::query()->find($receipt->correction_case_id);
            $event = $receipt->correction_event_id === null
                ? null
                : FinanceSettlementCorrectionEvent::query()->find($receipt->correction_event_id);
            $expectedOperation = $event === null
                ? FinanceCashSettlementCorrectionService::OPERATION_REQUEST
                : ($event->sequence === 1
                    ? FinanceCashSettlementCorrectionService::OPERATION_REVIEW
                    : FinanceCashSettlementCorrectionService::OPERATION_COMPLETE);
            $expectedType = $event === null
                ? FinanceSettlementCorrectionOperationReceipt::RESULT_CASE
                : FinanceSettlementCorrectionOperationReceipt::RESULT_EVENT;
            $record = $event ?? $case;
            $expectedActor = $event instanceof FinanceSettlementCorrectionEvent
                ? $event->actor_user_id
                : $case?->requesting_cashier_user_id;

            if (! $case
                || ! $record
                || ($receipt->correction_event_id !== null && ! $event)
                || ($event && $event->correction_case_id !== $case->id)
                || $receipt->actor_user_id !== $expectedActor
                || $receipt->operation !== $expectedOperation
                || $receipt->result_type !== $expectedType
                || $receipt->result_public_id !== $record->public_id
                || ! hash_equals($receipt->case_content_digest, $case->content_digest)
                || ($event === null && $receipt->event_content_digest !== null)
                || ($event && ! hash_equals((string) $receipt->event_content_digest, $event->content_digest))
                || preg_match('/\A[a-f0-9]{64}\z/', $receipt->payload_digest) !== 1
                || ! hash_equals($receipt->result_digest, $fingerprints->result($expectedOperation, $case, $event))) {
                $mismatches++;
            }
        }

        foreach (FinanceSettlementCorrectionCase::query()->orderBy('id')->get() as $case) {
            if (FinanceSettlementCorrectionOperationReceipt::query()
                ->where('correction_case_id', $case->id)
                ->where('operation', FinanceCashSettlementCorrectionService::OPERATION_REQUEST)
                ->whereNull('correction_event_id')->count() !== 1) {
                $mismatches++;
            }
        }
        foreach (FinanceSettlementCorrectionEvent::query()->orderBy('id')->get() as $event) {
            $operation = $event->sequence === 1
                ? FinanceCashSettlementCorrectionService::OPERATION_REVIEW
                : FinanceCashSettlementCorrectionService::OPERATION_COMPLETE;
            if (FinanceSettlementCorrectionOperationReceipt::query()
                ->where('correction_case_id', $event->correction_case_id)
                ->where('correction_event_id', $event->id)
                ->where('operation', $operation)->count() !== 1) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function financeSettlementCorrectionAuditMismatchCount(): int
    {
        $mismatches = 0;

        foreach (FinanceSettlementCorrectionCase::query()->orderBy('id')->get() as $case) {
            $requestEvents = AuditEvent::query()
                ->where('action', 'finance.workflow.mutate')
                ->where('resource_type', 'finance_record')
                ->where('resource_id', $case->public_id)
                ->where('actor_user_id', $case->requesting_cashier_user_id)
                ->where('outcome', 'SUCCESS')->get();
            if ($requestEvents->filter(fn (AuditEvent $event): bool => ($event->metadata['operation'] ?? null) === FinanceCashSettlementCorrectionService::OPERATION_REQUEST
                && ($event->metadata['state'] ?? null) === FinanceCashSettlementNetPolicy::CORRECTION_REQUESTED
                && (int) ($event->metadata['version'] ?? 0) === 1
            )->count() !== 1) {
                $mismatches++;
            }

            foreach (FinanceSettlementCorrectionEvent::query()
                ->where('correction_case_id', $case->id)->orderBy('sequence')->orderBy('id')->get() as $event) {
                $operation = $event->sequence === 1
                    ? FinanceCashSettlementCorrectionService::OPERATION_REVIEW
                    : FinanceCashSettlementCorrectionService::OPERATION_COMPLETE;
                $eventAudits = AuditEvent::query()
                    ->where('action', 'finance.workflow.mutate')
                    ->where('resource_type', 'finance_record')
                    ->where('resource_id', $case->public_id)
                    ->where('actor_user_id', $event->actor_user_id)
                    ->where('outcome', 'SUCCESS')->get();
                if ($eventAudits->filter(fn (AuditEvent $audit): bool => ($audit->metadata['operation'] ?? null) === $operation
                    && ($audit->metadata['state'] ?? null) === $event->event_type
                    && (int) ($audit->metadata['version'] ?? 0) === $event->sequence
                )->count() !== 1) {
                    $mismatches++;
                }
            }
        }

        return $mismatches;
    }

    private function financeTariffVersionChainMismatchCount(): int
    {
        $mismatches = 0;
        foreach ([
            ['finance_cost_component_groups', 'finance_cost_component_group_versions', 'group_id'],
            ['finance_cost_components', 'finance_cost_component_versions', 'component_id'],
            ['finance_tariff_catalogues', 'finance_tariff_catalogue_versions', 'catalogue_id'],
            ['finance_tariff_items', 'finance_tariff_item_versions', 'tariff_item_id'],
        ] as [$headTable, $versionTable, $foreignKey]) {
            foreach (DB::table(SchemaQualifier::table($headTable))->orderBy('id')->get() as $head) {
                $versions = DB::table(SchemaQualifier::table($versionTable))
                    ->where($foreignKey, $head->id)
                    ->orderBy('version')
                    ->get();
                $previousDigest = null;
                $retired = false;
                if ($versions->count() !== (int) $head->version) {
                    $mismatches++;
                }
                foreach ($versions as $index => $version) {
                    if ((int) $version->version !== $index + 1
                        || (string) ($version->previous_content_digest ?? '') !== (string) ($previousDigest ?? '')
                        || $retired) {
                        $mismatches++;
                    }
                    $retired = $version->state === 'RETIRED';
                    $previousDigest = $version->content_digest;
                }
            }
        }

        return $mismatches;
    }

    private function financeTariffHeadMismatchCount(): int
    {
        $mismatches = 0;
        foreach ([
            ['finance_cost_component_groups', 'finance_cost_component_group_versions', 'group_id', ['display_name'], null],
            ['finance_cost_components', 'finance_cost_component_versions', 'component_id', ['display_name', 'description', 'terminology_label'], null],
            ['finance_tariff_catalogues', 'finance_tariff_catalogue_versions', 'catalogue_id', ['display_name'], null],
            ['finance_tariff_items', 'finance_tariff_item_versions', 'tariff_item_id', [], 'latest_effective_from'],
        ] as [$headTable, $versionTable, $foreignKey, $projectionFields, $effectiveField]) {
            foreach (DB::table(SchemaQualifier::table($headTable))->orderBy('id')->get() as $head) {
                $latest = DB::table(SchemaQualifier::table($versionTable))
                    ->where($foreignKey, $head->id)
                    ->orderByDesc('version')
                    ->first();
                $valid = $latest !== null
                    && (int) $head->version === (int) $latest->version
                    && (string) $head->state === (string) $latest->state
                    && hash_equals((string) $head->current_content_digest, (string) $latest->content_digest);
                foreach ($projectionFields as $field) {
                    $valid = $valid && (string) ($head->{$field} ?? '') === (string) ($latest->{$field} ?? '');
                }
                if ($effectiveField !== null) {
                    $valid = $valid
                        && substr((string) ($head->{$effectiveField} ?? ''), 0, 10) === substr((string) ($latest->effective_from ?? ''), 0, 10);
                }
                if (! $valid) {
                    $mismatches++;
                }
            }
        }

        return $mismatches;
    }

    private function financeTariffEffectivePeriodMismatchCount(): int
    {
        $mismatches = 0;
        foreach (DB::table(SchemaQualifier::table('finance_tariff_items'))->orderBy('id')->get() as $item) {
            $versions = DB::table(SchemaQualifier::table('finance_tariff_item_versions'))
                ->where('tariff_item_id', $item->id)
                ->orderBy('version')
                ->get();
            $previousDate = null;
            $retired = false;
            foreach ($versions as $version) {
                $date = substr((string) $version->effective_from, 0, 10);
                if (($previousDate !== null && $date <= $previousDate) || $retired) {
                    $mismatches++;
                }
                $retired = $version->state === 'RETIRED';
                $previousDate = $date;
            }
            if ($versions->isNotEmpty()
                && substr((string) $item->latest_effective_from, 0, 10) !== substr((string) $versions->last()->effective_from, 0, 10)) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function financeTariffUpstreamMismatchCount(): int
    {
        $mismatches = 0;
        foreach (DB::table(SchemaQualifier::table('finance_tariff_items'))->orderBy('id')->get() as $item) {
            $catalogue = DB::table(SchemaQualifier::table('finance_tariff_catalogues'))->where('id', $item->catalogue_id)->first();
            $component = DB::table(SchemaQualifier::table('finance_cost_components'))->where('id', $item->component_id)->first();
            $group = $component === null ? null : DB::table(SchemaQualifier::table('finance_cost_component_groups'))->where('id', $component->group_id)->first();
            if ($catalogue === null || $component === null || $group === null) {
                continue;
            }

            $versions = DB::table(SchemaQualifier::table('finance_tariff_item_versions'))
                ->where('tariff_item_id', $item->id)
                ->orderBy('version')
                ->get();
            foreach ($versions as $index => $version) {
                $componentVersionExists = DB::table(SchemaQualifier::table('finance_cost_component_versions'))
                    ->where('component_id', $component->id)
                    ->where('content_digest', $version->component_content_digest)
                    ->exists();
                if ((string) $version->catalogue_public_id !== (string) $catalogue->public_id
                    || (string) $version->catalogue_code !== (string) $catalogue->catalogue_code
                    || (string) $version->component_public_id !== (string) $component->public_id
                    || (string) $version->component_code !== (string) $component->component_code
                    || ! $componentVersionExists) {
                    $mismatches++;
                }

                if ($version->state === 'RETIRED') {
                    continue;
                }
                $end = isset($versions[$index + 1]) ? (string) $versions[$index + 1]->effective_from : null;
                foreach ([
                    ['finance_tariff_catalogue_versions', 'catalogue_id', $catalogue->id],
                    ['finance_cost_component_versions', 'component_id', $component->id],
                    ['finance_cost_component_group_versions', 'group_id', $group->id],
                ] as [$table, $foreignKey, $id]) {
                    $retiredAt = DB::table(SchemaQualifier::table($table))
                        ->where($foreignKey, $id)
                        ->where('state', 'RETIRED')
                        ->value('created_at');
                    $retiredDate = $retiredAt === null ? null : substr((string) $retiredAt, 0, 10);
                    if ($retiredDate !== null
                        && $retiredDate >= (string) $version->effective_from
                        && ($end === null || $retiredDate < $end)) {
                        $mismatches++;
                    }
                }
            }
        }

        return $mismatches;
    }

    private function financeTariffCodeReservationMismatchCount(): int
    {
        $mismatches = 0;
        $types = [
            'GROUP' => ['finance_cost_component_groups', 'group_code'],
            'COMPONENT' => ['finance_cost_components', 'component_code'],
            'CATALOGUE' => ['finance_tariff_catalogues', 'catalogue_code'],
            'TARIFF_ITEM' => ['finance_tariff_items', 'tariff_code'],
        ];
        foreach ($types as $type => [$table, $codeColumn]) {
            foreach (DB::table(SchemaQualifier::table($table))->pluck($codeColumn) as $code) {
                if (DB::table(SchemaQualifier::table('finance_tariff_code_reservations'))
                    ->where('reservation_type', $type)->where('normalized_code', $code)->count() !== 1) {
                    $mismatches++;
                }
            }
        }
        foreach (DB::table(SchemaQualifier::table('finance_tariff_code_reservations'))->get() as $reservation) {
            $target = $types[$reservation->reservation_type] ?? null;
            if ($target === null || DB::table(SchemaQualifier::table($target[0]))
                ->where($target[1], $reservation->normalized_code)->count() !== 1) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function financeTariffReceiptResultMismatchCount(): int
    {
        $mismatches = 0;
        $digests = new FinanceTariffContentDigest;
        $types = [
            'GROUP' => ['finance_cost_component_groups', 'finance_cost_component_group_versions', 'group_id'],
            'COMPONENT' => ['finance_cost_components', 'finance_cost_component_versions', 'component_id'],
            'CATALOGUE' => ['finance_tariff_catalogues', 'finance_tariff_catalogue_versions', 'catalogue_id'],
            'TARIFF_ITEM' => ['finance_tariff_items', 'finance_tariff_item_versions', 'tariff_item_id'],
        ];
        $operations = [
            'GROUP' => ['FINANCE_COST_COMPONENT_GROUP_CREATE', 'FINANCE_COST_COMPONENT_GROUP_REVISE', 'FINANCE_COST_COMPONENT_GROUP_RETIRE'],
            'COMPONENT' => ['FINANCE_COST_COMPONENT_CREATE', 'FINANCE_COST_COMPONENT_REVISE', 'FINANCE_COST_COMPONENT_RETIRE'],
            'CATALOGUE' => ['FINANCE_TARIFF_CATALOGUE_CREATE', 'FINANCE_TARIFF_CATALOGUE_REVISE', 'FINANCE_TARIFF_CATALOGUE_RETIRE'],
            'TARIFF_ITEM' => ['FINANCE_TARIFF_ITEM_CREATE', 'FINANCE_TARIFF_ITEM_APPEND_VERSION', 'FINANCE_TARIFF_ITEM_RETIRE'],
        ];
        foreach (DB::table(SchemaQualifier::table('finance_tariff_operation_receipts'))->orderBy('id')->get() as $receipt) {
            $target = $types[$receipt->result_type] ?? null;
            $head = $target === null ? null : DB::table(SchemaQualifier::table($target[0]))
                ->where('public_id', $receipt->result_public_id)->first();
            $version = $head === null ? null : DB::table(SchemaQualifier::table($target[1]))
                ->where($target[2], $head->id)
                ->where('version', $receipt->result_version)
                ->first();
            $expectedDigest = $version === null ? null : $digests->retainedResult(
                (string) $receipt->result_type,
                (string) $receipt->result_public_id,
                (int) $receipt->result_version,
                (string) $receipt->result_state,
                (string) $version->content_digest,
            );
            if ($version === null
                || ! in_array($receipt->operation, $operations[$receipt->result_type] ?? [], true)
                || (string) $version->state !== (string) $receipt->result_state
                || ! hash_equals((string) $expectedDigest, (string) $receipt->result_digest)) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function financeRadiologyBindingVersionChainMismatchCount(): int
    {
        $mismatches = 0;
        foreach (DB::table(SchemaQualifier::table('finance_radiology_tariff_bindings'))->orderBy('id')->get() as $binding) {
            $versions = DB::table(SchemaQualifier::table('finance_radiology_tariff_binding_versions'))
                ->where('binding_id', $binding->id)
                ->orderBy('version')
                ->get();
            $previousDigest = null;
            $previousDate = null;
            $retired = false;
            if ($versions->count() !== (int) $binding->version) {
                $mismatches++;
            }
            foreach ($versions as $index => $version) {
                $effectiveFrom = substr((string) $version->effective_from, 0, 10);
                if ((int) $version->version !== $index + 1
                    || (string) ($version->previous_content_digest ?? '') !== (string) ($previousDigest ?? '')
                    || ($previousDate !== null && $effectiveFrom <= $previousDate)
                    || $retired) {
                    $mismatches++;
                }
                $retired = (string) $version->state === 'RETIRED';
                $previousDigest = (string) $version->content_digest;
                $previousDate = $effectiveFrom;
            }
        }

        return $mismatches;
    }

    private function financeRadiologyBindingHeadMismatchCount(): int
    {
        $mismatches = 0;
        foreach (DB::table(SchemaQualifier::table('finance_radiology_tariff_bindings'))->orderBy('id')->get() as $binding) {
            $latest = DB::table(SchemaQualifier::table('finance_radiology_tariff_binding_versions'))
                ->where('binding_id', $binding->id)
                ->orderByDesc('version')
                ->first();
            if ($latest === null
                || (int) $binding->version !== (int) $latest->version
                || (string) $binding->state !== (string) $latest->state
                || substr((string) $binding->latest_effective_from, 0, 10) !== substr((string) $latest->effective_from, 0, 10)
                || ! hash_equals((string) $binding->current_content_digest, (string) $latest->content_digest)) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function financeRadiologyBindingUpstreamMismatchCount(): int
    {
        $bindingDigests = new FinanceRadiologyTariffContentDigest;
        $tariffDigests = new FinanceTariffContentDigest;
        $mismatches = 0;
        foreach (DB::table(SchemaQualifier::table('finance_radiology_tariff_bindings'))->orderBy('id')->get() as $binding) {
            $master = DB::table(SchemaQualifier::table('radiology_examination_masters'))
                ->where('id', $binding->radiology_master_id)
                ->first();
            $masterVersion = DB::table(SchemaQualifier::table('radiology_examination_master_versions'))
                ->where('id', $binding->radiology_master_version_id)
                ->first();
            $masterValid = $master !== null
                && $masterVersion !== null
                && (int) $masterVersion->radiology_examination_master_id === (int) $master->id
                && (string) $binding->radiology_master_version_public_id === (string) $masterVersion->public_id
                && (int) $binding->radiology_master_version === (int) $masterVersion->version
                && (string) $binding->radiology_master_code === (string) $master->examination_code
                && hash_equals((string) $binding->radiology_master_content_digest, (string) $masterVersion->content_digest)
                && hash_equals(
                    (string) $masterVersion->content_digest,
                    $bindingDigests->radiologyMasterVersion(
                        (string) $masterVersion->display_name,
                        $masterVersion->preparation_instruction === null ? null : (string) $masterVersion->preparation_instruction,
                        (string) $masterVersion->state,
                    ),
                );
            if (! $masterValid) {
                $mismatches++;

                continue;
            }

            $versions = DB::table(SchemaQualifier::table('finance_radiology_tariff_binding_versions'))
                ->where('binding_id', $binding->id)
                ->orderBy('version')
                ->get();
            foreach ($versions as $version) {
                $tariff = DB::table(SchemaQualifier::table('finance_tariff_items'))
                    ->where('id', $version->tariff_item_id)
                    ->first();
                $effectiveFrom = substr((string) $version->effective_from, 0, 10);
                $tariffVersion = $tariff === null ? null : DB::table(SchemaQualifier::table('finance_tariff_item_versions'))
                    ->where('tariff_item_id', $tariff->id)
                    ->whereDate('effective_from', '<=', $effectiveFrom)
                    ->orderByDesc('effective_from')
                    ->orderByDesc('version')
                    ->first();
                $tariffDigest = $tariffVersion === null ? null : $tariffDigests->tariff([
                    'tariff_code' => (string) $tariff->tariff_code,
                    'catalogue_public_id' => (string) $tariffVersion->catalogue_public_id,
                    'catalogue_code' => (string) $tariffVersion->catalogue_code,
                    'component_public_id' => (string) $tariffVersion->component_public_id,
                    'component_code' => (string) $tariffVersion->component_code,
                    'component_content_digest' => (string) $tariffVersion->component_content_digest,
                    'display_name' => (string) $tariffVersion->display_name,
                    'care_setting' => (string) $tariffVersion->care_setting,
                    'service_domain' => (string) $tariffVersion->service_domain,
                    'reference_label' => $tariffVersion->reference_label,
                    'ward_class_label' => $tariffVersion->ward_class_label,
                    'amount_rupiah' => (int) $tariffVersion->amount_rupiah,
                    'state' => (string) $tariffVersion->state,
                    'effective_from' => substr((string) $tariffVersion->effective_from, 0, 10),
                    'version' => (int) $tariffVersion->version,
                ]);
                $bindingDigest = $bindingDigests->binding([
                    'radiology_master_public_id' => (string) $master->public_id,
                    'radiology_master_version_public_id' => (string) $binding->radiology_master_version_public_id,
                    'radiology_master_version' => (int) $binding->radiology_master_version,
                    'radiology_master_content_digest' => (string) $binding->radiology_master_content_digest,
                    'radiology_master_code' => (string) $binding->radiology_master_code,
                    'care_setting' => (string) $binding->care_setting,
                    'tariff_item_public_id' => (string) $version->tariff_item_public_id,
                    'tariff_item_code' => (string) $version->tariff_item_code,
                    'state' => (string) $version->state,
                    'effective_from' => $effectiveFrom,
                    'version' => (int) $version->version,
                ]);
                if ($tariffVersion === null
                    || (string) $version->tariff_item_public_id !== (string) $tariff->public_id
                    || (string) $version->tariff_item_code !== (string) $tariff->tariff_code
                    || (string) $tariffVersion->state !== 'ACTIVE'
                    || (string) $tariffVersion->service_domain !== 'RADIOLOGY'
                    || (string) $tariffVersion->care_setting !== (string) $binding->care_setting
                    || ! hash_equals((string) $tariffVersion->content_digest, (string) $tariffDigest)
                    || ! hash_equals((string) $version->content_digest, $bindingDigest)) {
                    $mismatches++;
                }
            }
        }

        return $mismatches;
    }

    private function financeRadiologyBindingReceiptResultMismatchCount(): int
    {
        $digests = new FinanceRadiologyTariffContentDigest;
        $operations = [
            'FINANCE_RADIOLOGY_TARIFF_BINDING_CREATE',
            'FINANCE_RADIOLOGY_TARIFF_BINDING_APPEND_VERSION',
            'FINANCE_RADIOLOGY_TARIFF_BINDING_RETIRE',
        ];
        $mismatches = 0;
        foreach (DB::table(SchemaQualifier::table('finance_radiology_tariff_operation_receipts'))->orderBy('id')->get() as $receipt) {
            $binding = DB::table(SchemaQualifier::table('finance_radiology_tariff_bindings'))
                ->where('public_id', $receipt->result_public_id)
                ->first();
            $version = $binding === null ? null : DB::table(SchemaQualifier::table('finance_radiology_tariff_binding_versions'))
                ->where('binding_id', $binding->id)
                ->where('version', $receipt->result_version)
                ->first();
            $expectedDigest = $version === null ? null : $digests->retainedResult(
                (string) $receipt->result_public_id,
                (int) $receipt->result_version,
                (string) $receipt->result_state,
                (string) $version->content_digest,
            );
            if ((string) $receipt->result_type !== 'BINDING'
                || ! in_array((string) $receipt->operation, $operations, true)
                || $version === null
                || (string) $version->state !== (string) $receipt->result_state
                || ! hash_equals((string) $receipt->result_digest, (string) $expectedDigest)) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function financeRadiologySourceMismatchCount(): int
    {
        $adapter = app(FinanceRadiologySourceAdapter::class);
        $mismatches = 0;
        foreach (DB::table(SchemaQualifier::table('finance_radiology_source_events'))->orderBy('id')->get() as $source) {
            $charges = FinanceChargeEvent::query()
                ->where('finance_radiology_source_event_id', $source->id)
                ->orderBy('id')
                ->get();
            $encounter = Encounter::query()->with('patient')->whereKey($source->encounter_id)->first();
            if ($charges->count() !== 1
                || DB::table(SchemaQualifier::table('finance_radiology_source_events'))
                    ->where('radiology_performance_id', $source->radiology_performance_id)
                    ->count() !== 1
                || ! $encounter instanceof Encounter) {
                $mismatches++;

                continue;
            }
            try {
                $adapter->verifyRetained($encounter, $charges);
            } catch (FinanceDenied) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function financeLaboratoryBindingVersionChainMismatchCount(): int
    {
        $mismatches = 0;
        foreach (DB::table(SchemaQualifier::table('finance_laboratory_tariff_bindings'))->orderBy('id')->get() as $binding) {
            $versions = DB::table(SchemaQualifier::table('finance_laboratory_tariff_binding_versions'))
                ->where('binding_id', $binding->id)->orderBy('version')->get();
            $previousDigest = null;
            $previousDate = null;
            $retired = false;
            if ($versions->count() !== (int) $binding->version) {
                $mismatches++;
            }
            foreach ($versions as $index => $version) {
                $effectiveFrom = substr((string) $version->effective_from, 0, 10);
                if ((int) $version->version !== $index + 1
                    || (string) ($version->previous_content_digest ?? '') !== (string) ($previousDigest ?? '')
                    || ($previousDate !== null && $effectiveFrom <= $previousDate)
                    || $retired) {
                    $mismatches++;
                }
                $retired = (string) $version->state === 'RETIRED';
                $previousDigest = (string) $version->content_digest;
                $previousDate = $effectiveFrom;
            }
        }

        return $mismatches;
    }

    private function financeLaboratoryBindingHeadMismatchCount(): int
    {
        $mismatches = 0;
        foreach (DB::table(SchemaQualifier::table('finance_laboratory_tariff_bindings'))->orderBy('id')->get() as $binding) {
            $latest = DB::table(SchemaQualifier::table('finance_laboratory_tariff_binding_versions'))
                ->where('binding_id', $binding->id)->orderByDesc('version')->first();
            if ($latest === null
                || (int) $binding->version !== (int) $latest->version
                || (string) $binding->state !== (string) $latest->state
                || substr((string) $binding->latest_effective_from, 0, 10) !== substr((string) $latest->effective_from, 0, 10)
                || ! hash_equals((string) $binding->current_content_digest, (string) $latest->content_digest)) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function financeLaboratoryBindingUpstreamMismatchCount(): int
    {
        $bindingDigests = new FinanceLaboratoryTariffContentDigest;
        $tariffDigests = new FinanceTariffContentDigest;
        $mismatches = 0;
        foreach (DB::table(SchemaQualifier::table('finance_laboratory_tariff_bindings'))->orderBy('id')->get() as $binding) {
            $master = DB::table(SchemaQualifier::table('laboratory_examination_masters'))->where('id', $binding->laboratory_master_id)->first();
            $masterVersion = DB::table(SchemaQualifier::table('laboratory_examination_master_versions'))->where('id', $binding->laboratory_master_version_id)->first();
            $components = $masterVersion === null ? [] : json_decode((string) $masterVersion->components, true);
            $masterValid = $master !== null && $masterVersion !== null && is_array($components)
                && (int) $masterVersion->laboratory_examination_master_id === (int) $master->id
                && (string) $binding->laboratory_master_version_public_id === (string) $masterVersion->public_id
                && (int) $binding->laboratory_master_version === (int) $masterVersion->version
                && (string) $binding->laboratory_master_code === (string) $master->examination_code
                && hash_equals((string) $binding->laboratory_master_content_digest, (string) $masterVersion->content_digest)
                && hash_equals((string) $masterVersion->content_digest, $bindingDigests->laboratoryMasterVersion(
                    (string) $masterVersion->display_name,
                    (string) $masterVersion->specimen_type,
                    $masterVersion->collection_instruction === null ? null : (string) $masterVersion->collection_instruction,
                    $components,
                    (string) $masterVersion->state,
                ));
            if (! $masterValid) {
                $mismatches++;

                continue;
            }

            foreach (DB::table(SchemaQualifier::table('finance_laboratory_tariff_binding_versions'))->where('binding_id', $binding->id)->orderBy('version')->get() as $version) {
                $tariff = DB::table(SchemaQualifier::table('finance_tariff_items'))->where('id', $version->tariff_item_id)->first();
                $effectiveFrom = substr((string) $version->effective_from, 0, 10);
                $tariffVersion = $tariff === null ? null : DB::table(SchemaQualifier::table('finance_tariff_item_versions'))
                    ->where('tariff_item_id', $tariff->id)->whereDate('effective_from', '<=', $effectiveFrom)
                    ->orderByDesc('effective_from')->orderByDesc('version')->first();
                $tariffDigest = $tariffVersion === null ? null : $tariffDigests->tariff([
                    'tariff_code' => (string) $tariff->tariff_code,
                    'catalogue_public_id' => (string) $tariffVersion->catalogue_public_id,
                    'catalogue_code' => (string) $tariffVersion->catalogue_code,
                    'component_public_id' => (string) $tariffVersion->component_public_id,
                    'component_code' => (string) $tariffVersion->component_code,
                    'component_content_digest' => (string) $tariffVersion->component_content_digest,
                    'display_name' => (string) $tariffVersion->display_name,
                    'care_setting' => (string) $tariffVersion->care_setting,
                    'service_domain' => (string) $tariffVersion->service_domain,
                    'reference_label' => $tariffVersion->reference_label,
                    'ward_class_label' => $tariffVersion->ward_class_label,
                    'amount_rupiah' => (int) $tariffVersion->amount_rupiah,
                    'state' => (string) $tariffVersion->state,
                    'effective_from' => substr((string) $tariffVersion->effective_from, 0, 10),
                    'version' => (int) $tariffVersion->version,
                ]);
                $bindingDigest = $bindingDigests->binding([
                    'laboratory_master_public_id' => (string) $master->public_id,
                    'laboratory_master_version_public_id' => (string) $binding->laboratory_master_version_public_id,
                    'laboratory_master_version' => (int) $binding->laboratory_master_version,
                    'laboratory_master_content_digest' => (string) $binding->laboratory_master_content_digest,
                    'laboratory_master_code' => (string) $binding->laboratory_master_code,
                    'care_setting' => (string) $binding->care_setting,
                    'tariff_item_public_id' => (string) $version->tariff_item_public_id,
                    'tariff_item_code' => (string) $version->tariff_item_code,
                    'state' => (string) $version->state,
                    'effective_from' => $effectiveFrom,
                    'version' => (int) $version->version,
                ]);
                if ($tariffVersion === null
                    || (string) $version->tariff_item_public_id !== (string) $tariff->public_id
                    || (string) $version->tariff_item_code !== (string) $tariff->tariff_code
                    || (string) $tariffVersion->state !== 'ACTIVE'
                    || (string) $tariffVersion->service_domain !== 'LABORATORY'
                    || (string) $tariffVersion->care_setting !== (string) $binding->care_setting
                    || ! hash_equals((string) $tariffVersion->content_digest, (string) $tariffDigest)
                    || ! hash_equals((string) $version->content_digest, $bindingDigest)) {
                    $mismatches++;
                }
            }
        }

        return $mismatches;
    }

    private function financeLaboratoryBindingReceiptResultMismatchCount(): int
    {
        $digests = new FinanceLaboratoryTariffContentDigest;
        $operations = [
            'FINANCE_LABORATORY_TARIFF_BINDING_CREATE',
            'FINANCE_LABORATORY_TARIFF_BINDING_APPEND_VERSION',
            'FINANCE_LABORATORY_TARIFF_BINDING_RETIRE',
        ];
        $mismatches = 0;
        foreach (DB::table(SchemaQualifier::table('finance_laboratory_tariff_operation_receipts'))->orderBy('id')->get() as $receipt) {
            $binding = DB::table(SchemaQualifier::table('finance_laboratory_tariff_bindings'))->where('public_id', $receipt->result_public_id)->first();
            $version = $binding === null ? null : DB::table(SchemaQualifier::table('finance_laboratory_tariff_binding_versions'))
                ->where('binding_id', $binding->id)->where('version', $receipt->result_version)->first();
            $expectedDigest = $version === null ? null : $digests->retainedResult(
                (string) $receipt->result_public_id,
                (int) $receipt->result_version,
                (string) $receipt->result_state,
                (string) $version->content_digest,
            );
            if ((string) $receipt->result_type !== 'BINDING'
                || ! in_array((string) $receipt->operation, $operations, true)
                || $version === null
                || (string) $version->state !== (string) $receipt->result_state
                || ! hash_equals((string) $receipt->result_digest, (string) $expectedDigest)) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function financeLaboratorySourceMismatchCount(): int
    {
        $adapter = app(FinanceLaboratorySourceAdapter::class);
        $mismatches = 0;
        foreach (DB::table(SchemaQualifier::table('finance_laboratory_source_events'))->orderBy('id')->get() as $source) {
            $charges = FinanceChargeEvent::query()->where('finance_laboratory_source_event_id', $source->id)->orderBy('id')->get();
            $encounter = Encounter::query()->with('patient')->whereKey($source->encounter_id)->first();
            if ($charges->count() !== 1
                || DB::table(SchemaQualifier::table('finance_laboratory_source_events'))->where('laboratory_result_version_id', $source->laboratory_result_version_id)->count() !== 1
                || ! $encounter instanceof Encounter) {
                $mismatches++;

                continue;
            }
            try {
                $adapter->verifyRetained($encounter, $charges);
            } catch (FinanceDenied) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function financeAccommodationBindingVersionChainMismatchCount(): int
    {
        $mismatches = 0;
        foreach (DB::table(SchemaQualifier::table('finance_accommodation_tariff_bindings'))->orderBy('id')->get() as $binding) {
            $versions = DB::table(SchemaQualifier::table('finance_accommodation_tariff_binding_versions'))
                ->where('binding_id', $binding->id)->orderBy('version')->get();
            $previousDigest = null;
            $previousDate = null;
            $retired = false;
            if ($versions->count() !== (int) $binding->version) {
                $mismatches++;
            }
            foreach ($versions as $index => $version) {
                $effectiveFrom = substr((string) $version->effective_from, 0, 10);
                if ((int) $version->version !== $index + 1
                    || (string) ($version->previous_content_digest ?? '') !== (string) ($previousDigest ?? '')
                    || ($previousDate !== null && $effectiveFrom <= $previousDate)
                    || $retired) {
                    $mismatches++;
                }
                $retired = (string) $version->state === 'RETIRED';
                $previousDigest = (string) $version->content_digest;
                $previousDate = $effectiveFrom;
            }
        }

        return $mismatches;
    }

    private function financeAccommodationBindingHeadMismatchCount(): int
    {
        $mismatches = 0;
        foreach (DB::table(SchemaQualifier::table('finance_accommodation_tariff_bindings'))->orderBy('id')->get() as $binding) {
            $latest = DB::table(SchemaQualifier::table('finance_accommodation_tariff_binding_versions'))
                ->where('binding_id', $binding->id)->orderByDesc('version')->first();
            if ($latest === null
                || (int) $binding->version !== (int) $latest->version
                || (string) $binding->state !== (string) $latest->state
                || substr((string) $binding->latest_effective_from, 0, 10) !== substr((string) $latest->effective_from, 0, 10)
                || ! hash_equals((string) $binding->current_content_digest, (string) $latest->content_digest)) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function financeAccommodationBindingUpstreamMismatchCount(): int
    {
        $bindingDigests = new FinanceAccommodationTariffContentDigest;
        $tariffDigests = new FinanceTariffContentDigest;
        $mismatches = 0;
        foreach (DB::table(SchemaQualifier::table('finance_accommodation_tariff_bindings'))->orderBy('id')->get() as $binding) {
            $bed = DB::table(SchemaQualifier::table('inpatient_beds'))->where('id', $binding->inpatient_bed_id)->first();
            $bedVersion = DB::table(SchemaQualifier::table('inpatient_bed_versions'))->where('id', $binding->inpatient_bed_version_id)->first();
            $ward = $bed === null ? null : DB::table(SchemaQualifier::table('inpatient_wards'))->where('id', $bed->ward_id)->first();
            $bedValid = $bed !== null && $bedVersion !== null && $ward !== null
                && (int) $bedVersion->bed_id === (int) $bed->id
                && (string) $binding->inpatient_bed_version_public_id === (string) $bedVersion->public_id
                && (int) $binding->inpatient_bed_version === (int) $bedVersion->version
                && (string) $binding->inpatient_bed_content_digest === (string) $bedVersion->after_digest
                && (string) $binding->ward_public_id === (string) $ward->public_id
                && (string) $binding->ward_code === (string) $ward->code
                && (string) $binding->bed_public_id === (string) $bed->public_id
                && (string) $binding->bed_code === (string) $bed->code
                && (string) $binding->service_class === (string) $bedVersion->service_class
                && (string) $binding->care_setting === 'INPATIENT'
                && (string) $binding->pricing_unit === 'OCCUPANCY_DAY'
                && hash_equals(
                    (string) $bedVersion->after_digest,
                    $bindingDigests->inpatientBedVersion(
                        (string) $bed->code,
                        (string) $bedVersion->display_name,
                        (string) $bedVersion->room_label,
                        (string) $bedVersion->service_class,
                        (string) $bedVersion->state,
                        (int) $bedVersion->version,
                    ),
                );
            if (! $bedValid) {
                $mismatches++;

                continue;
            }

            foreach (DB::table(SchemaQualifier::table('finance_accommodation_tariff_binding_versions'))->where('binding_id', $binding->id)->orderBy('version')->get() as $version) {
                $tariff = DB::table(SchemaQualifier::table('finance_tariff_items'))->where('id', $version->tariff_item_id)->first();
                $effectiveFrom = substr((string) $version->effective_from, 0, 10);
                $tariffVersion = $tariff === null ? null : DB::table(SchemaQualifier::table('finance_tariff_item_versions'))
                    ->where('tariff_item_id', $tariff->id)->whereDate('effective_from', '<=', $effectiveFrom)
                    ->orderByDesc('effective_from')->orderByDesc('version')->first();
                $tariffDigest = $tariffVersion === null ? null : $tariffDigests->tariff([
                    'tariff_code' => (string) $tariff->tariff_code,
                    'catalogue_public_id' => (string) $tariffVersion->catalogue_public_id,
                    'catalogue_code' => (string) $tariffVersion->catalogue_code,
                    'component_public_id' => (string) $tariffVersion->component_public_id,
                    'component_code' => (string) $tariffVersion->component_code,
                    'component_content_digest' => (string) $tariffVersion->component_content_digest,
                    'display_name' => (string) $tariffVersion->display_name,
                    'care_setting' => (string) $tariffVersion->care_setting,
                    'service_domain' => (string) $tariffVersion->service_domain,
                    'reference_label' => $tariffVersion->reference_label,
                    'ward_class_label' => $tariffVersion->ward_class_label,
                    'amount_rupiah' => (int) $tariffVersion->amount_rupiah,
                    'state' => (string) $tariffVersion->state,
                    'effective_from' => substr((string) $tariffVersion->effective_from, 0, 10),
                    'version' => (int) $tariffVersion->version,
                ]);
                $bindingDigest = $bindingDigests->binding([
                    'bed_public_id' => (string) $bed->public_id,
                    'inpatient_bed_version_public_id' => (string) $binding->inpatient_bed_version_public_id,
                    'inpatient_bed_version' => (int) $binding->inpatient_bed_version,
                    'inpatient_bed_content_digest' => (string) $binding->inpatient_bed_content_digest,
                    'bed_code' => (string) $binding->bed_code,
                    'tariff_item_public_id' => (string) $version->tariff_item_public_id,
                    'tariff_item_code' => (string) $version->tariff_item_code,
                    'state' => (string) $version->state,
                    'effective_from' => $effectiveFrom,
                    'version' => (int) $version->version,
                ]);
                if ($tariffVersion === null
                    || (string) $version->tariff_item_public_id !== (string) $tariff->public_id
                    || (string) $version->tariff_item_code !== (string) $tariff->tariff_code
                    || (string) $tariffVersion->state !== 'ACTIVE'
                    || (string) $tariffVersion->service_domain !== 'ACCOMMODATION'
                    || (string) $tariffVersion->care_setting !== 'INPATIENT'
                    || ! hash_equals((string) $tariffVersion->content_digest, (string) $tariffDigest)
                    || ! hash_equals((string) $version->content_digest, $bindingDigest)) {
                    $mismatches++;
                }
            }
        }

        return $mismatches;
    }

    private function financeAccommodationBindingReceiptResultMismatchCount(): int
    {
        $digests = new FinanceAccommodationTariffContentDigest;
        $operations = [
            'FINANCE_ACCOMMODATION_TARIFF_BINDING_CREATE',
            'FINANCE_ACCOMMODATION_TARIFF_BINDING_APPEND_VERSION',
            'FINANCE_ACCOMMODATION_TARIFF_BINDING_RETIRE',
        ];
        $mismatches = 0;
        foreach (DB::table(SchemaQualifier::table('finance_accommodation_tariff_operation_receipts'))->orderBy('id')->get() as $receipt) {
            $binding = DB::table(SchemaQualifier::table('finance_accommodation_tariff_bindings'))->where('public_id', $receipt->result_public_id)->first();
            $version = $binding === null ? null : DB::table(SchemaQualifier::table('finance_accommodation_tariff_binding_versions'))
                ->where('binding_id', $binding->id)->where('version', $receipt->result_version)->first();
            $expectedDigest = $version === null ? null : $digests->retainedResult(
                (string) $receipt->result_public_id,
                (int) $receipt->result_version,
                (string) $receipt->result_state,
                (string) $version->content_digest,
            );
            if ((string) $receipt->result_type !== 'BINDING'
                || ! in_array((string) $receipt->operation, $operations, true)
                || $version === null
                || (string) $version->state !== (string) $receipt->result_state
                || ! hash_equals((string) $receipt->result_digest, (string) $expectedDigest)) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function financeAccommodationSourceMismatchCount(): int
    {
        $adapter = app(FinanceAccommodationSourceAdapter::class);
        $mismatches = 0;
        foreach (DB::table(SchemaQualifier::table('finance_accommodation_source_events'))->orderBy('id')->get() as $source) {
            $charges = FinanceChargeEvent::query()->where('finance_accommodation_source_event_id', $source->id)->orderBy('id')->get();
            $encounter = Encounter::query()->with('patient')->whereKey($source->encounter_id)->first();
            if ($charges->count() !== 1
                || DB::table(SchemaQualifier::table('finance_accommodation_source_events'))
                    ->where('encounter_id', $source->encounter_id)
                    ->whereDate('service_date', substr((string) $source->service_date, 0, 10))
                    ->count() !== 1
                || ! $encounter instanceof Encounter) {
                $mismatches++;

                continue;
            }
            try {
                $adapter->verifyRetained($encounter, $charges);
            } catch (FinanceDenied) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function financeCashierCollectionMismatchCount(): int
    {
        $fingerprints = app(FinanceCashierCollectionFingerprint::class);
        $netPolicy = app(FinanceCashSettlementNetPolicy::class);
        $mismatches = 0;

        foreach (FinanceCashSettlement::query()->where('collection_binding_required', true)->orderBy('id')->get() as $settlement) {
            if (FinanceCashierCollectionMember::query()->where('settlement_id', $settlement->id)->count() !== 1) {
                $mismatches++;
            }
        }
        foreach (FinanceCashierCollectionBatch::query()->with(['members.settlement', 'events', 'handoff'])->orderBy('id')->get() as $batch) {
            try {
                app(FinanceCashierCollectionService::class)->reconciledEvidence($batch);
            } catch (FinanceDenied) {
                $mismatches++;
            }
            if (! hash_equals($batch->content_digest, $fingerprints->batch($batch))) {
                $mismatches++;
            }
            $members = $batch->members->sortBy('id')->values();
            $events = $batch->events->sortBy(['sequence', 'id'])->values();
            $refundEvents = collect();
            $gross = 0;
            $refunded = 0;
            foreach ($members as $member) {
                $settlement = $member->settlement;
                if (! $settlement || ! $settlement->collection_binding_required
                    || $member->cashier_user_id !== $batch->cashier_user_id
                    || $member->settlement_public_id_snapshot !== $settlement->public_id
                    || $member->amount !== $settlement->amount
                    || ! hash_equals($member->settlement_content_digest, $settlement->content_digest)
                    || ! hash_equals($member->content_digest, $fingerprints->member($member))) {
                    $mismatches++;

                    continue;
                }
                $gross += $settlement->amount;
                try {
                    $correction = $netPolicy->correctionState($settlement);
                    if ($correction['state'] === FinanceCashSettlementNetPolicy::REFUND_COMPLETED) {
                        $refunded += $settlement->amount;
                        $refundEvents->push($correction['events']->last());
                    }
                } catch (FinanceDenied) {
                    $mismatches++;
                }
            }
            $previous = null;
            $verifiedCount = 0;
            $mismatches += $this->financeCollectionEvidenceReceiptMismatchCount(
                $batch, null, null, FinanceCashierCollectionService::OPERATION_OPEN,
                'OPEN', 0, $batch->cashier_user_id, $fingerprints,
            );
            foreach ($events as $index => $event) {
                $typeValid = $index === 0
                    ? $event->event_type === FinanceCashierCollectionEvent::CLOSE_REQUESTED
                    : in_array($event->event_type, [FinanceCashierCollectionEvent::RECOUNT_SUBMITTED, FinanceCashierCollectionEvent::CLOSE_VERIFIED], true);
                if ($event->event_type === FinanceCashierCollectionEvent::CLOSE_VERIFIED) {
                    $verifiedCount++;
                }
                if (! $typeValid || $event->sequence !== $index + 1
                    || ($event->event_type === FinanceCashierCollectionEvent::CLOSE_VERIFIED
                        ? $event->actor_user_id === $batch->cashier_user_id
                        : $event->actor_user_id !== $batch->cashier_user_id)
                    || ($previous === null ? $event->previous_event_digest !== null : ! hash_equals($previous->content_digest, (string) $event->previous_event_digest))
                    || $event->membership_count !== $members->count()
                    || $event->gross_amount !== $gross || $event->completed_refund_amount !== $refunded
                    || $event->expected_net_amount !== $gross - $refunded
                    || $event->variance_amount !== $event->counted_amount - $event->expected_net_amount
                    || ! hash_equals($event->membership_digest, $fingerprints->membership($members, $refundEvents))
                    || ! hash_equals($event->content_digest, $fingerprints->event($event))) {
                    $mismatches++;
                }
                $operation = match ($event->event_type) {
                    FinanceCashierCollectionEvent::CLOSE_REQUESTED => FinanceCashierCollectionService::OPERATION_CLOSE,
                    FinanceCashierCollectionEvent::RECOUNT_SUBMITTED => FinanceCashierCollectionService::OPERATION_RECOUNT,
                    FinanceCashierCollectionEvent::CLOSE_VERIFIED => FinanceCashierCollectionService::OPERATION_VERIFY,
                    default => '',
                };
                if ($operation !== '') {
                    $mismatches += $this->financeCollectionEvidenceReceiptMismatchCount(
                        $batch, $event, null, $operation, $event->event_type,
                        $event->sequence, $event->actor_user_id, $fingerprints,
                    );
                }
                $previous = $event;
            }
            $lastEvent = $events->last();
            if ($verifiedCount > 1 || ($verifiedCount === 1
                && (! $lastEvent instanceof FinanceCashierCollectionEvent
                    || $lastEvent->event_type !== FinanceCashierCollectionEvent::CLOSE_VERIFIED
                    || $lastEvent->variance_amount !== 0))) {
                $mismatches++;
            }
            $slots = DB::table(SchemaQualifier::table('finance_cashier_collection_active_slots'))->where('collection_batch_id', $batch->id)->get();
            if (($events->isEmpty() && ($slots->count() !== 1 || (int) $slots->first()->cashier_user_id !== $batch->cashier_user_id))
                || ($events->isNotEmpty() && $slots->isNotEmpty())) {
                $mismatches++;
            }
            $handoff = $batch->handoff;
            if ($handoff) {
                $verified = $events->firstWhere('event_type', FinanceCashierCollectionEvent::CLOSE_VERIFIED);
                if (! $verified || $handoff->verified_event_id !== $verified->id
                    || $handoff->cashier_user_id !== $batch->cashier_user_id
                    || $handoff->supervisor_user_id !== $verified->actor_user_id
                    || $handoff->cashier_name_snapshot !== $batch->cashier_name_snapshot
                    || $handoff->supervisor_name_snapshot !== $verified->actor_name_snapshot
                    || $handoff->batch_public_id_snapshot !== $batch->public_id
                    || $handoff->batch_number_snapshot !== $batch->batch_number
                    || $handoff->membership_count !== $verified->membership_count
                    || $handoff->gross_amount !== $verified->gross_amount
                    || $handoff->completed_refund_amount !== $verified->completed_refund_amount
                    || $handoff->expected_net_amount !== $verified->expected_net_amount
                    || $handoff->counted_amount !== $handoff->expected_net_amount
                    || ! hash_equals($handoff->batch_content_digest, $batch->content_digest)
                    || ! hash_equals($handoff->verified_event_digest, $verified->content_digest)
                    || ! hash_equals($handoff->content_digest, $fingerprints->handoff($handoff))) {
                    $mismatches++;
                }
                $mismatches += $this->financeCollectionEvidenceReceiptMismatchCount(
                    $batch, $verified, $handoff, FinanceCashierCollectionService::OPERATION_HANDOFF,
                    'DEPOSIT_HANDOFF_CREATED', $verified instanceof FinanceCashierCollectionEvent ? $verified->sequence : 0,
                    $batch->cashier_user_id, $fingerprints,
                );
            }
        }
        foreach (FinanceCashierCollectionOperationReceipt::query()->orderBy('id')->get() as $receipt) {
            $batch = FinanceCashierCollectionBatch::query()->find($receipt->collection_batch_id);
            $event = $receipt->collection_event_id ? FinanceCashierCollectionEvent::query()->find($receipt->collection_event_id) : null;
            $handoff = $receipt->deposit_handoff_id ? FinanceCashDepositHandoff::query()->find($receipt->deposit_handoff_id) : null;
            $result = $handoff ?? $event ?? $batch;
            [$shapeValid, $state, $version, $actorUserId] = match ($receipt->operation) {
                FinanceCashierCollectionService::OPERATION_OPEN => [$event === null && $handoff === null, 'OPEN', 0, $batch?->cashier_user_id],
                FinanceCashierCollectionService::OPERATION_CLOSE => [$event?->event_type === FinanceCashierCollectionEvent::CLOSE_REQUESTED && $handoff === null, 'CLOSE_REQUESTED', $event?->sequence, $event?->actor_user_id],
                FinanceCashierCollectionService::OPERATION_RECOUNT => [$event?->event_type === FinanceCashierCollectionEvent::RECOUNT_SUBMITTED && $handoff === null, 'RECOUNT_SUBMITTED', $event?->sequence, $event?->actor_user_id],
                FinanceCashierCollectionService::OPERATION_VERIFY => [$event?->event_type === FinanceCashierCollectionEvent::CLOSE_VERIFIED && $handoff === null, 'CLOSE_VERIFIED', $event?->sequence, $event?->actor_user_id],
                FinanceCashierCollectionService::OPERATION_HANDOFF => [$event?->event_type === FinanceCashierCollectionEvent::CLOSE_VERIFIED && $handoff?->verified_event_id === $event->id, 'DEPOSIT_HANDOFF_CREATED', $event?->sequence, $batch?->cashier_user_id],
                default => [false, '', -1, null],
            };
            if (! $batch || ! $result || ! $shapeValid || $actorUserId !== $receipt->actor_user_id
                || $event?->collection_batch_id !== ($event ? $batch->id : null)
                || $handoff?->collection_batch_id !== ($handoff ? $batch->id : null)
                || $receipt->result_public_id !== $result->public_id
                || $this->financeCollectionEvidenceReceiptMismatchCount(
                    $batch, $event, $handoff, $receipt->operation, $state, (int) $version,
                    $receipt->actor_user_id, $fingerprints,
                ) !== 0) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function financeCollectionEvidenceReceiptMismatchCount(
        FinanceCashierCollectionBatch $batch,
        ?FinanceCashierCollectionEvent $event,
        ?FinanceCashDepositHandoff $handoff,
        string $operation,
        string $state,
        int $version,
        int $actorUserId,
        FinanceCashierCollectionFingerprint $fingerprints,
    ): int {
        $receipts = FinanceCashierCollectionOperationReceipt::query()
            ->where('operation', $operation)
            ->where('result_public_id', ($handoff ?? $event ?? $batch)->public_id)
            ->get();
        $expectedType = $handoff ? FinanceCashierCollectionOperationReceipt::RESULT_HANDOFF
            : ($event ? FinanceCashierCollectionOperationReceipt::RESULT_EVENT : FinanceCashierCollectionOperationReceipt::RESULT_BATCH);
        $receipt = $receipts->first();
        $receiptValid = $receipts->count() === 1 && $receipt instanceof FinanceCashierCollectionOperationReceipt
            && $receipt->actor_user_id === $actorUserId
            && $receipt->collection_batch_id === $batch->id
            && $receipt->collection_event_id === $event?->id
            && $receipt->deposit_handoff_id === $handoff?->id
            && $receipt->result_type === $expectedType
            && hash_equals($receipt->batch_content_digest, $batch->content_digest)
            && ($event ? hash_equals((string) $receipt->event_content_digest, $event->content_digest) : $receipt->event_content_digest === null)
            && ($handoff ? hash_equals((string) $receipt->handoff_content_digest, $handoff->content_digest) : $receipt->handoff_content_digest === null)
            && hash_equals($receipt->result_digest, $fingerprints->result($operation, $batch, $event, $handoff));
        $audits = AuditEvent::query()->where('action', 'finance.workflow.mutate')->where('outcome', 'SUCCESS')
            ->where('resource_id', $batch->public_id)->where('actor_user_id', $actorUserId)
            ->get()->filter(function (AuditEvent $audit) use ($operation, $state, $version, $batch, $event, $handoff): bool {
                $metadata = $audit->metadata ?? [];

                return ($metadata['operation'] ?? null) === $operation
                    && ($metadata['state'] ?? null) === $state
                    && ($metadata['version'] ?? null) === $version
                    && ($metadata['batch_public_id'] ?? null) === $batch->public_id
                    && ($metadata['batch_content_digest'] ?? null) === $batch->content_digest
                    && ($metadata['event_public_id'] ?? null) === $event?->public_id
                    && ($metadata['event_content_digest'] ?? null) === $event?->content_digest
                    && ($metadata['handoff_public_id'] ?? null) === $handoff?->public_id
                    && ($metadata['handoff_content_digest'] ?? null) === $handoff?->content_digest
                    && ($metadata['expected_net_amount'] ?? null) === ($event instanceof FinanceCashierCollectionEvent ? $event->expected_net_amount : 0)
                    && ($metadata['evidence_count'] ?? null) === ($event instanceof FinanceCashierCollectionEvent ? $event->membership_count : 0);
            });

        return $receiptValid && $audits->count() === 1 ? 0 : 1;
    }

    private function teachingResetPairMismatchCount(): int
    {
        $events = AuditEvent::query()->whereIn('action', ['teaching.reset.started', 'teaching.reset.completed'])
            ->where('resource_type', 'simulation')->where('resource_id', 'synthetic-reset')
            ->where('outcome', 'SUCCESS')->orderBy('id')->get();
        if ($events->isEmpty()) {
            return 0;
        }
        $mismatches = 0;
        $tombstoneKeys = [
            'boundary', 'evidence_preserved', 'queue_counter_high_water_preserved',
            'collection_batch_count', 'collection_active_slot_count', 'collection_member_count',
            'collection_event_count', 'collection_handoff_count', 'collection_operation_receipt_count',
            'collection_active_slot_digest', 'collection_operation_receipt_digest', 'collection_evidence_digest',
        ];
        foreach ($events->groupBy(function (AuditEvent $event): string {
            $correlationId = $event->metadata['reset_correlation_id'] ?? null;

            return is_string($correlationId) ? $correlationId : '__missing_reset_correlation_id__';
        }) as $correlationId => $pair) {
            $started = $pair->where('action', 'teaching.reset.started')->values();
            $completed = $pair->where('action', 'teaching.reset.completed')->values();
            if (! is_string($correlationId) || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $correlationId) !== 1
                || $started->count() !== 1 || $completed->count() !== 1) {
                $mismatches++;

                continue;
            }
            $start = $started->first();
            $complete = $completed->first();
            if (! $start instanceof AuditEvent || ! $complete instanceof AuditEvent) {
                $mismatches++;

                continue;
            }
            $sameTombstone = collect($tombstoneKeys)->every(
                fn (string $key): bool => ($start->metadata[$key] ?? null) === ($complete->metadata[$key] ?? null),
            );
            if (! $sameTombstone || $start->reason !== $complete->reason
                || $start->actor_user_id !== $complete->actor_user_id
                || $start->actor_type !== $complete->actor_type
                || $start->actor_reference !== $complete->actor_reference) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    private function digest(mixed $value): string
    {
        return hash('sha256', CanonicalJson::encode(['value' => $value]));
    }
}
