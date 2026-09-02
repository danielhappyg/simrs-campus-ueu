<?php

namespace App\Support\Simulation;

use App\Models\Encounter;
use App\Models\LaboratoryOperationReceipt;
use App\Models\LaboratoryResultVersion;
use App\Models\Patient;
use App\Models\RadiologyOperationReceipt;
use App\Models\RadiologyReportVersion;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Database\SchemaQualifier;
use App\Support\Emergency\EmergencyMutationScope;
use App\Support\Finance\FinanceAccommodationTariffAppendOnlyGuard;
use App\Support\Finance\FinanceAccommodationTariffMutationScope;
use App\Support\Finance\FinanceAppendOnlyGuard;
use App\Support\Finance\FinanceCanonicalJson;
use App\Support\Finance\FinanceLaboratoryTariffAppendOnlyGuard;
use App\Support\Finance\FinanceLaboratoryTariffMutationScope;
use App\Support\Finance\FinanceMutationScope;
use App\Support\Finance\FinanceRadiologyTariffAppendOnlyGuard;
use App\Support\Finance\FinanceRadiologyTariffMutationScope;
use App\Support\Finance\FinanceTariffAppendOnlyGuard;
use App\Support\Finance\FinanceTariffMutationScope;
use App\Support\Inpatient\CanonicalInpatientBedOperationLockCoordinator;
use App\Support\Inpatient\InpatientDischargeCodingSourceMutationScope;
use App\Support\Inpatient\InpatientDischargeMutationScope;
use App\Support\Inpatient\InpatientDischargeSummaryMutationScope;
use App\Support\Inpatient\InpatientDocumentationMutationScope;
use App\Support\Inpatient\InpatientLocationMutationScope;
use App\Support\Inpatient\InpatientMasterDirectWriteScope;
use App\Support\Inpatient\InpatientRmMutationScope;
use App\Support\Inpatient\InpatientSummaryAddendumMutationScope;
use App\Support\Laboratory\LaboratoryMutationScope;
use App\Support\Pharmacy\PharmacyAppendOnlyGuard;
use App\Support\Pharmacy\PharmacyMutationScope;
use App\Support\Radiology\RadiologyMutationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class SyntheticResetService
{
    private readonly CanonicalInpatientBedOperationLockCoordinator $inpatientLocks;

    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        ?CanonicalInpatientBedOperationLockCoordinator $inpatientLocks = null,
    ) {
        $this->inpatientLocks = $inpatientLocks ?? app(CanonicalInpatientBedOperationLockCoordinator::class);
    }

    /** @param array{actor?: ?User, reason?: ?string} $options */
    public function reset(array $options = []): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Synthetic reset requires SIMULATION mode with synthetic-only data enforced.');
        }

        $actor = $options['actor'] ?? null;
        $reason = $options['reason'] ?? 'simulation_reset';
        if (mb_strlen($reason) < 1 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Synthetic reset reason must contain between 1 and 255 characters.');
        }

        $resetCorrelationId = (string) Str::ulid();
        DB::transaction(function () use ($actor, $reason, $resetCorrelationId): void {
            $this->lockCashierCollectionCoordination();
            $this->lockSyntheticPharmacyGraph();
            $this->lockSyntheticFinanceGraph();
            $this->lockSyntheticInpatientGraph();
            $collectionResetEvidence = $this->collectionResetEvidence();
            $started = $this->auditRecorder->record(
                action: 'teaching.reset.started',
                resourceType: 'simulation',
                resourceId: 'synthetic-reset',
                actor: $actor instanceof User ? $actor : null,
                outcome: 'SUCCESS',
                reason: $reason,
                metadata: [
                    'boundary' => 'synthetic_patient_graph',
                    'evidence_preserved' => true,
                    'queue_counter_high_water_preserved' => true,
                    'reset_correlation_id' => $resetCorrelationId,
                    ...$collectionResetEvidence,
                ],
                includeRequestFingerprint: false,
            );

            if ($started === null) {
                throw new RuntimeException('Synthetic reset refused because its start audit event could not be recorded.');
            }

            $this->deleteSyntheticFinanceChains();
            $this->deleteSyntheticFinanceTariffMaster();
            $this->deleteSyntheticAmendmentChains();
            $this->deleteSyntheticInpatientSummaryAddendumChains();
            $this->deleteSyntheticInpatientDischargeChains();
            $this->deleteSyntheticInpatientRmChains();
            $this->deleteSyntheticInpatientDischargeCodingSourceChains();
            $this->deleteSyntheticInpatientDischargeSummaryChains();
            $this->deleteSyntheticInpatientDocumentationChains();
            $this->deleteSyntheticPharmacyChains();
            $this->deleteSyntheticLaboratoryChains();
            $this->deleteSyntheticRadiologyChains();
            $this->deleteSyntheticEmergencyChains();
            $this->deleteSyntheticInpatientLocationChains();
            $this->deleteSyntheticInpatientClaimMutexes();
            $deleted = Patient::query()->syntheticOnly()->delete();
            $this->deleteSyntheticInpatientMasters();

            $completed = $this->auditRecorder->record(
                action: 'teaching.reset.completed',
                resourceType: 'simulation',
                resourceId: 'synthetic-reset',
                actor: $actor instanceof User ? $actor : null,
                outcome: 'SUCCESS',
                reason: $reason,
                metadata: [
                    'boundary' => 'synthetic_patient_graph',
                    'deleted_patients' => $deleted,
                    'evidence_preserved' => true,
                    'queue_counter_high_water_preserved' => true,
                    'reset_correlation_id' => $resetCorrelationId,
                    ...$collectionResetEvidence,
                ],
                includeRequestFingerprint: false,
            );

            if ($completed === null) {
                throw new RuntimeException('Synthetic reset rolled back because its completion audit event could not be recorded.');
            }
        });
    }

    /** @return array{collection_batch_count:int,collection_active_slot_count:int,collection_member_count:int,collection_event_count:int,collection_handoff_count:int,collection_operation_receipt_count:int,collection_active_slot_digest:string,collection_operation_receipt_digest:string,collection_evidence_digest:string} */
    private function collectionResetEvidence(): array
    {
        $tables = [
            'finance_cashier_collection_batches', 'finance_cashier_collection_active_slots',
            'finance_cashier_collection_members', 'finance_cashier_collection_events',
            'finance_cash_deposit_handoffs', 'finance_cashier_collection_operation_receipts',
        ];
        if (collect($tables)->contains(fn (string $table): bool => ! Schema::hasTable(SchemaQualifier::table($table)))) {
            return [
                'collection_batch_count' => 0, 'collection_active_slot_count' => 0,
                'collection_member_count' => 0, 'collection_event_count' => 0,
                'collection_handoff_count' => 0, 'collection_operation_receipt_count' => 0,
                'collection_active_slot_digest' => FinanceCanonicalJson::digest([]),
                'collection_operation_receipt_digest' => FinanceCanonicalJson::digest([]),
                'collection_evidence_digest' => FinanceCanonicalJson::digest([]),
            ];
        }
        $evidence = [
            'finance_cashier_collection_batches' => DB::table(SchemaQualifier::table('finance_cashier_collection_batches'))->orderBy('id')->get(['id', 'public_id', 'content_digest'])
                ->map(fn (object $row): array => [(int) $row->id, (string) $row->public_id, (string) $row->content_digest])->all(),
            'finance_cashier_collection_active_slots' => DB::table(SchemaQualifier::table('finance_cashier_collection_active_slots'))->orderBy('cashier_user_id')->get(['cashier_user_id', 'collection_batch_id', 'created_at'])
                ->map(fn (object $row): array => [(int) $row->cashier_user_id, (int) $row->collection_batch_id, (string) $row->created_at])->all(),
            'finance_cashier_collection_members' => DB::table(SchemaQualifier::table('finance_cashier_collection_members'))->orderBy('id')->get(['id', 'public_id', 'content_digest'])
                ->map(fn (object $row): array => [(int) $row->id, (string) $row->public_id, (string) $row->content_digest])->all(),
            'finance_cashier_collection_events' => DB::table(SchemaQualifier::table('finance_cashier_collection_events'))->orderBy('id')->get(['id', 'public_id', 'content_digest'])
                ->map(fn (object $row): array => [(int) $row->id, (string) $row->public_id, (string) $row->content_digest])->all(),
            'finance_cash_deposit_handoffs' => DB::table(SchemaQualifier::table('finance_cash_deposit_handoffs'))->orderBy('id')->get(['id', 'public_id', 'content_digest'])
                ->map(fn (object $row): array => [(int) $row->id, (string) $row->public_id, (string) $row->content_digest])->all(),
            'finance_cashier_collection_operation_receipts' => DB::table(SchemaQualifier::table('finance_cashier_collection_operation_receipts'))->orderBy('id')->get(['id', 'public_id', 'operation', 'result_public_id', 'result_digest'])
                ->map(fn (object $row): array => [(int) $row->id, (string) $row->public_id, (string) $row->operation, (string) $row->result_public_id, (string) $row->result_digest])->all(),
        ];

        return [
            'collection_batch_count' => count($evidence['finance_cashier_collection_batches']),
            'collection_active_slot_count' => count($evidence['finance_cashier_collection_active_slots']),
            'collection_member_count' => count($evidence['finance_cashier_collection_members']),
            'collection_event_count' => count($evidence['finance_cashier_collection_events']),
            'collection_handoff_count' => count($evidence['finance_cash_deposit_handoffs']),
            'collection_operation_receipt_count' => count($evidence['finance_cashier_collection_operation_receipts']),
            'collection_active_slot_digest' => FinanceCanonicalJson::digest($evidence['finance_cashier_collection_active_slots']),
            'collection_operation_receipt_digest' => FinanceCanonicalJson::digest($evidence['finance_cashier_collection_operation_receipts']),
            'collection_evidence_digest' => FinanceCanonicalJson::digest($evidence),
        ];
    }

    private function lockCashierCollectionCoordination(): void
    {
        $slots = SchemaQualifier::table('finance_cashier_collection_active_slots');
        $batches = SchemaQualifier::table('finance_cashier_collection_batches');
        if (! Schema::hasTable($slots) || ! Schema::hasTable($batches)) {
            return;
        }
        DB::table($slots)->orderBy('cashier_user_id')->lockForUpdate()->get(['cashier_user_id', 'collection_batch_id']);
        DB::table($batches)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    private function lockSyntheticInpatientGraph(): void
    {
        $patients = SchemaQualifier::table('patients');
        $encounters = SchemaQualifier::table('encounters');
        $patientIds = array_values(DB::table($patients)->where('is_synthetic', true)->orderBy('id')->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)->all());
        if ($patientIds === []) {
            return;
        }

        $this->inpatientLocks->lockPatientClaimMutexes($patientIds);
        $active = DB::table($encounters)
            ->whereIn('patient_id', $patientIds)
            ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->whereIn('status', Encounter::BED_OCCUPYING_STATUSES)
            ->orderBy('id')
            ->get(['id', 'inpatient_bed_id', 'bed_code']);
        $bedCodes = array_values($active->pluck('bed_code')->filter(static fn (mixed $code): bool => is_string($code) && trim($code) !== '')
            ->map(static fn (mixed $code): string => (string) $code)->all());
        $this->inpatientLocks->lockMutexes($bedCodes);
        $this->inpatientLocks->lockEncounters(array_values($active->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all()));

        $bedIds = $active->pluck('inpatient_bed_id')->filter()->map(static fn (mixed $id): int => (int) $id)->values()->all();
        if ($bedIds !== [] && Schema::hasTable(SchemaQualifier::table('inpatient_beds'))) {
            $beds = DB::table(SchemaQualifier::table('inpatient_beds'))->whereIn('id', $bedIds)->get(['id', 'ward_id']);
            $this->inpatientLocks->lockWards(array_values($beds->pluck('ward_id')->map(static fn (mixed $id): int => (int) $id)->all()));
            $this->inpatientLocks->lockBeds(array_values($beds->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all()));
        }
    }

    private function lockSyntheticPharmacyGraph(): void
    {
        $mutexes = SchemaQualifier::table('pharmacy_inventory_mutexes');
        if (! Schema::hasTable($mutexes)) {
            return;
        }
        $patientIds = array_values(DB::table(SchemaQualifier::table('patients'))->where('is_synthetic', true)->orderBy('id')->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)->all());
        if ($patientIds !== []) {
            $this->inpatientLocks->lockPatientClaimMutexes($patientIds);
        }
        PharmacyMutationScope::run(fn () => DB::table($mutexes.' as pim')
            ->join(SchemaQualifier::table('pharmacy_depots').' as pd', 'pd.id', '=', 'pim.depot_id')
            ->join(SchemaQualifier::table('pharmacy_medicines').' as pm', 'pm.id', '=', 'pim.medicine_id')
            ->orderBy('pd.depot_code')->orderBy('pm.medicine_code')->orderBy('pim.id')
            ->lockForUpdate()->get(['pim.id']));
    }

    private function lockSyntheticFinanceGraph(): void
    {
        if (! Schema::hasTable(SchemaQualifier::table('finance_bills'))) {
            return;
        }

        DB::table(SchemaQualifier::table('encounters').' as e')
            ->join(SchemaQualifier::table('patients').' as p', 'p.id', '=', 'e.patient_id')
            ->where('p.is_synthetic', true)
            ->orderBy('e.id')
            ->lockForUpdate()
            ->get(['e.id']);

        FinanceMutationScope::run(fn () => DB::table(SchemaQualifier::table('finance_bills'))
            ->orderBy('encounter_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']));
    }

    private function deleteSyntheticFinanceChains(): void
    {
        if (! Schema::hasTable(SchemaQualifier::table('finance_bills'))) {
            return;
        }

        FinanceAccommodationTariffAppendOnlyGuard::runSyntheticReset(function (): void {
            FinanceLaboratoryTariffAppendOnlyGuard::runSyntheticReset(function (): void {
                FinanceRadiologyTariffAppendOnlyGuard::runSyntheticReset(function (): void {
                    FinanceAppendOnlyGuard::runSyntheticReset(function (): void {
                        FinanceMutationScope::run(function (): void {
                            DB::table(SchemaQualifier::table('finance_bill_versions'))
                                ->whereNotNull('previous_version_id')
                                ->update(['previous_version_id' => null]);
                            foreach ([
                                'finance_cashier_collection_operation_receipts',
                                'finance_cash_deposit_handoffs',
                                'finance_cashier_collection_events',
                                'finance_cashier_collection_members',
                                'finance_cashier_collection_active_slots',
                                'finance_cashier_collection_batches',
                                'finance_settlement_correction_operation_receipts',
                                'finance_settlement_correction_events',
                                'finance_settlement_correction_cases',
                                'finance_settlement_operation_receipts', 'finance_cash_settlements',
                                'finance_operation_receipts', 'finance_bill_lines', 'finance_bill_versions',
                                'finance_bills', 'finance_charge_events', 'finance_accommodation_source_events',
                                'finance_laboratory_source_events', 'finance_radiology_source_events',
                            ] as $table) {
                                if (Schema::hasTable(SchemaQualifier::table($table))) {
                                    DB::table(SchemaQualifier::table($table))->delete();
                                }
                            }
                        });
                    });
                });
            });
        });
    }

    private function deleteSyntheticFinanceTariffMaster(): void
    {
        if (! Schema::hasTable(SchemaQualifier::table('finance_tariff_catalogues'))) {
            return;
        }

        FinanceAccommodationTariffAppendOnlyGuard::runSyntheticReset(function (): void {
            FinanceLaboratoryTariffAppendOnlyGuard::runSyntheticReset(function (): void {
                FinanceRadiologyTariffAppendOnlyGuard::runSyntheticReset(function (): void {
                    FinanceAccommodationTariffMutationScope::run(function (): void {
                        foreach ([
                            'finance_accommodation_tariff_operation_receipts',
                            'finance_accommodation_tariff_binding_versions',
                            'finance_accommodation_tariff_bindings',
                        ] as $table) {
                            if (Schema::hasTable(SchemaQualifier::table($table))) {
                                DB::table(SchemaQualifier::table($table))->delete();
                            }
                        }
                    });
                    FinanceLaboratoryTariffMutationScope::run(function (): void {
                        foreach ([
                            'finance_laboratory_tariff_operation_receipts',
                            'finance_laboratory_tariff_binding_versions',
                            'finance_laboratory_tariff_bindings',
                        ] as $table) {
                            if (Schema::hasTable(SchemaQualifier::table($table))) {
                                DB::table(SchemaQualifier::table($table))->delete();
                            }
                        }
                    });
                    FinanceRadiologyTariffMutationScope::run(function (): void {
                        foreach ([
                            'finance_radiology_tariff_operation_receipts',
                            'finance_radiology_tariff_binding_versions',
                            'finance_radiology_tariff_bindings',
                        ] as $table) {
                            if (Schema::hasTable(SchemaQualifier::table($table))) {
                                DB::table(SchemaQualifier::table($table))->delete();
                            }
                        }
                    });
                    FinanceTariffAppendOnlyGuard::runSyntheticReset(function (): void {
                        FinanceTariffMutationScope::run(function (): void {
                            foreach ([
                                'finance_tariff_operation_receipts',
                                'finance_tariff_item_versions',
                                'finance_tariff_items',
                                'finance_tariff_catalogue_versions',
                                'finance_tariff_catalogues',
                                'finance_cost_component_versions',
                                'finance_cost_components',
                                'finance_cost_component_group_versions',
                                'finance_cost_component_groups',
                                'finance_tariff_code_reservations',
                            ] as $table) {
                                DB::table(SchemaQualifier::table($table))->delete();
                            }
                        });
                    });
                });
            });
        });
    }

    private function deleteSyntheticPharmacyChains(): void
    {
        if (! Schema::hasTable(SchemaQualifier::table('pharmacy_prescriptions'))) {
            return;
        }
        PharmacyAppendOnlyGuard::runSyntheticReset(function (): void {
            PharmacyMutationScope::run(function (): void {
                DB::table(SchemaQualifier::table('pharmacy_preparations'))
                    ->whereNotNull('replaces_preparation_id')
                    ->update(['replaces_preparation_id' => null]);
                foreach ([
                    'pharmacy_operation_receipts', 'pharmacy_return_items', 'pharmacy_returns',
                    'pharmacy_financial_source_events', 'pharmacy_stock_movements',
                    'pharmacy_handover_items', 'pharmacy_handovers', 'pharmacy_preparation_allocations',
                    'pharmacy_preparations', 'pharmacy_verifications', 'pharmacy_prescription_items',
                    'pharmacy_prescription_versions', 'pharmacy_prescriptions', 'pharmacy_stock_lots',
                    'pharmacy_inventory_mutexes', 'pharmacy_depot_versions', 'pharmacy_depots',
                    'pharmacy_depot_code_reservations', 'pharmacy_medicine_versions',
                    'pharmacy_medicines', 'pharmacy_medicine_code_reservations',
                ] as $table) {
                    DB::table(SchemaQualifier::table($table))->delete();
                }
            });
        });
    }

    private function deleteSyntheticInpatientClaimMutexes(): void
    {
        $mutexes = SchemaQualifier::table('inpatient_patient_claim_mutexes');
        if (! Schema::hasTable($mutexes)) {
            return;
        }
        $patients = SchemaQualifier::table('patients');
        DB::table($mutexes)->whereIn(
            'patient_id',
            DB::table($patients)->select('id')->where('is_synthetic', true),
        )->delete();
    }

    private function deleteSyntheticAmendmentChains(): void
    {
        $requests = SchemaQualifier::table('outpatient_post_closure_amendment_requests');
        if (! Schema::hasTable($requests)) {
            return;
        }

        $encounters = SchemaQualifier::table('encounters');
        $patients = SchemaQualifier::table('patients');
        $syntheticEncounterIds = DB::table($encounters)
            ->select($encounters.'.id')
            ->join($patients, $patients.'.id', '=', $encounters.'.patient_id')
            ->where($patients.'.is_synthetic', true);

        DB::table($requests)
            ->whereIn('encounter_id', $syntheticEncounterIds)
            ->delete();
    }

    private function deleteSyntheticInpatientDocumentationChains(): void
    {
        $documents = SchemaQualifier::table('inpatient_clinical_documents');
        if (! Schema::hasTable($documents)) {
            return;
        }

        $encounters = SchemaQualifier::table('encounters');
        $patients = SchemaQualifier::table('patients');
        $syntheticEncounterIds = DB::table($encounters)
            ->select($encounters.'.id')
            ->join($patients, $patients.'.id', '=', $encounters.'.patient_id')
            ->where($patients.'.is_synthetic', true);

        InpatientDocumentationMutationScope::run(function () use ($documents, $syntheticEncounterIds): void {
            DB::table(SchemaQualifier::table('inpatient_document_operation_receipts'))
                ->whereIn('encounter_id', clone $syntheticEncounterIds)
                ->delete();
            DB::table($documents)
                ->whereIn('encounter_id', $syntheticEncounterIds)
                ->delete();
        });
    }

    private function deleteSyntheticInpatientSummaryAddendumChains(): void
    {
        $requests = SchemaQualifier::table('inpatient_summary_correction_requests');
        if (! Schema::hasTable($requests)) {
            return;
        }

        $encounters = SchemaQualifier::table('encounters');
        $patients = SchemaQualifier::table('patients');
        $encounterIds = DB::table($encounters)->select($encounters.'.id')
            ->join($patients, $patients.'.id', '=', $encounters.'.patient_id')
            ->where($patients.'.is_synthetic', true);
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement("SET LOCAL simrs.synthetic_reset = '1'");
        } elseif ($driver === 'mysql') {
            DB::statement('SET @simrs_synthetic_reset = 1');
        }

        try {
            InpatientSummaryAddendumMutationScope::run(function () use ($requests, $encounterIds): void {
                $requestIds = DB::table($requests)->select($requests.'.id')
                    ->whereIn('encounter_id', clone $encounterIds);
                $addenda = SchemaQualifier::table('inpatient_summary_addenda');
                $addendumIds = DB::table($addenda)->select($addenda.'.id')
                    ->whereIn('correction_request_id', clone $requestIds);
                $reviews = SchemaQualifier::table('inpatient_summary_addendum_reviews');
                $reviewIds = DB::table($reviews)->select($reviews.'.id')
                    ->whereIn('correction_request_id', clone $requestIds);

                DB::table(SchemaQualifier::table('inpatient_summary_addendum_operation_receipts'))
                    ->whereIn('encounter_id', clone $encounterIds)->delete();
                DB::table(SchemaQualifier::table('inpatient_summary_addendum_review_items'))
                    ->whereIn('review_id', $reviewIds)->delete();
                DB::table($reviews)->whereIn('correction_request_id', clone $requestIds)->delete();
                DB::table(SchemaQualifier::table('inpatient_summary_addendum_versions'))
                    ->whereIn('addendum_id', $addendumIds)->delete();
                DB::table($addenda)->whereIn('correction_request_id', clone $requestIds)->delete();
                DB::table(SchemaQualifier::table('inpatient_summary_correction_request_versions'))
                    ->whereIn('correction_request_id', clone $requestIds)->delete();
                DB::table($requests)->whereIn('encounter_id', $encounterIds)->delete();
            });
        } finally {
            if ($driver === 'mysql') {
                DB::statement('SET @simrs_synthetic_reset = 0');
            }
        }
    }

    private function deleteSyntheticInpatientDischargeSummaryChains(): void
    {
        $summaries = SchemaQualifier::table('inpatient_discharge_summaries');
        if (! Schema::hasTable($summaries)) {
            return;
        }

        $encounters = SchemaQualifier::table('encounters');
        $patients = SchemaQualifier::table('patients');
        $syntheticEncounterIds = DB::table($encounters)
            ->select($encounters.'.id')
            ->join($patients, $patients.'.id', '=', $encounters.'.patient_id')
            ->where($patients.'.is_synthetic', true);
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement("SET LOCAL simrs.synthetic_reset = '1'");
        } elseif ($driver === 'mysql') {
            DB::statement('SET @simrs_synthetic_reset = 1');
        }

        try {
            InpatientDischargeSummaryMutationScope::run(function () use ($summaries, $syntheticEncounterIds): void {
                $summaryIds = DB::table($summaries)
                    ->select($summaries.'.id')
                    ->whereIn('encounter_id', clone $syntheticEncounterIds);
                DB::table(SchemaQualifier::table('inpatient_discharge_summary_operation_receipts'))
                    ->whereIn('encounter_id', clone $syntheticEncounterIds)
                    ->delete();
                DB::table(SchemaQualifier::table('inpatient_discharge_summary_versions'))
                    ->whereIn('inpatient_discharge_summary_id', $summaryIds)
                    ->delete();
                DB::table($summaries)
                    ->whereIn('encounter_id', $syntheticEncounterIds)
                    ->delete();
            });
        } finally {
            if ($driver === 'mysql') {
                DB::statement('SET @simrs_synthetic_reset = 0');
            }
        }
    }

    private function deleteSyntheticInpatientDischargeCodingSourceChains(): void
    {
        $sources = SchemaQualifier::table('inpatient_discharge_coding_sources');
        if (! Schema::hasTable($sources)) {
            return;
        }
        $encounters = SchemaQualifier::table('encounters');
        $patients = SchemaQualifier::table('patients');
        $ids = DB::table($encounters)->select($encounters.'.id')->join($patients, $patients.'.id', '=', $encounters.'.patient_id')->where($patients.'.is_synthetic', true);
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement("SET LOCAL simrs.synthetic_reset = '1'");
        } elseif ($driver === 'mysql') {
            DB::statement('SET @simrs_synthetic_reset = 1');
        }
        try {
            InpatientDischargeCodingSourceMutationScope::run(function () use ($sources, $ids): void {
                $sourceIds = DB::table($sources)->select($sources.'.id')->whereIn('encounter_id', clone $ids);
                DB::table(SchemaQualifier::table('inpatient_discharge_coding_source_operation_receipts'))->whereIn('encounter_id', clone $ids)->delete();
                DB::table(SchemaQualifier::table('inpatient_discharge_coding_source_versions'))->whereIn('inpatient_discharge_coding_source_id', $sourceIds)->delete();
                DB::table($sources)->whereIn('encounter_id', $ids)->delete();
            });
        } finally {
            if ($driver === 'mysql') {
                DB::statement('SET @simrs_synthetic_reset = 0');
            }
        }
    }

    private function deleteSyntheticInpatientRmChains(): void
    {
        $codings = SchemaQualifier::table('inpatient_rm_codings');
        if (! Schema::hasTable($codings)) {
            return;
        }
        $encounters = SchemaQualifier::table('encounters');
        $patients = SchemaQualifier::table('patients');
        $encounterIds = DB::table($encounters)->select($encounters.'.id')
            ->join($patients, $patients.'.id', '=', $encounters.'.patient_id')
            ->where($patients.'.is_synthetic', true);
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement("SET LOCAL simrs.synthetic_reset = '1'");
        } elseif ($driver === 'mysql') {
            DB::statement('SET @simrs_synthetic_reset = 1');
        }
        try {
            InpatientRmMutationScope::run(function () use ($codings, $encounterIds): void {
                $codingIds = DB::table($codings)->select($codings.'.id')->whereIn('encounter_id', clone $encounterIds);
                $versionTable = SchemaQualifier::table('inpatient_rm_coding_versions');
                $versionIds = DB::table($versionTable)->select($versionTable.'.id')->whereIn('inpatient_rm_coding_id', clone $codingIds);
                $reviewTable = SchemaQualifier::table('inpatient_rm_completeness_reviews');
                $reviewIds = DB::table($reviewTable)->select($reviewTable.'.id')->whereIn('encounter_id', clone $encounterIds);
                DB::table(SchemaQualifier::table('inpatient_rm_operation_receipts'))->whereIn('encounter_id', clone $encounterIds)->delete();
                DB::table(SchemaQualifier::table('inpatient_rm_completeness_items'))->whereIn('inpatient_rm_completeness_review_id', $reviewIds)->delete();
                DB::table($reviewTable)->whereIn('encounter_id', clone $encounterIds)->delete();
                DB::table(SchemaQualifier::table('inpatient_rm_coding_assignments'))->whereIn('inpatient_rm_coding_version_id', $versionIds)->delete();
                DB::table($versionTable)->whereIn('inpatient_rm_coding_id', clone $codingIds)->delete();
                DB::table($codings)->whereIn('encounter_id', $encounterIds)->delete();
            });
        } finally {
            if ($driver === 'mysql') {
                DB::statement('SET @simrs_synthetic_reset = 0');
            }
        }
    }

    private function deleteSyntheticInpatientDischargeChains(): void
    {
        $discharges = SchemaQualifier::table('inpatient_discharges');
        if (! Schema::hasTable($discharges)) {
            return;
        }
        $encounters = SchemaQualifier::table('encounters');
        $patients = SchemaQualifier::table('patients');
        $syntheticEncounterIds = DB::table($encounters)->select($encounters.'.id')
            ->join($patients, $patients.'.id', '=', $encounters.'.patient_id')
            ->where($patients.'.is_synthetic', true);
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement("SET LOCAL simrs.synthetic_reset = '1'");
        } elseif ($driver === 'mysql') {
            DB::statement('SET @simrs_synthetic_reset = 1');
        }
        try {
            InpatientDischargeMutationScope::run(function () use ($discharges, $syntheticEncounterIds): void {
                DB::table(SchemaQualifier::table('inpatient_discharge_operation_receipts'))
                    ->whereIn('encounter_id', clone $syntheticEncounterIds)->delete();
                DB::table($discharges)->whereIn('encounter_id', $syntheticEncounterIds)->delete();
            });
        } finally {
            if ($driver === 'mysql') {
                DB::statement('SET @simrs_synthetic_reset = 0');
            }
        }
    }

    private function deleteSyntheticInpatientMasters(): void
    {
        $wards = SchemaQualifier::table('inpatient_wards');
        if (! Schema::hasTable($wards)) {
            return;
        }

        InpatientMasterDirectWriteScope::run(function (): void {
            foreach ([
                'inpatient_master_operation_receipts',
                'inpatient_bed_versions',
                'inpatient_beds',
                'inpatient_ward_versions',
                'inpatient_wards',
            ] as $table) {
                DB::table(SchemaQualifier::table($table))->delete();
            }
        });
    }

    private function deleteSyntheticInpatientLocationChains(): void
    {
        $events = SchemaQualifier::table('inpatient_location_events');
        if (! Schema::hasTable($events)) {
            return;
        }
        $encounters = SchemaQualifier::table('encounters');
        $patients = SchemaQualifier::table('patients');
        $syntheticEncounterIds = DB::table($encounters)->select($encounters.'.id')
            ->join($patients, $patients.'.id', '=', $encounters.'.patient_id')->where($patients.'.is_synthetic', true);
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement("SET LOCAL simrs.synthetic_reset = '1'");
        } elseif ($driver === 'mysql') {
            DB::statement('SET @simrs_synthetic_reset = 1');
        }

        try {
            InpatientLocationMutationScope::run(function () use ($events, $syntheticEncounterIds): void {
                DB::table(SchemaQualifier::table('inpatient_location_operation_receipts'))
                    ->whereIn('encounter_id', clone $syntheticEncounterIds)->delete();
                DB::table($events)->whereIn('encounter_id', $syntheticEncounterIds)->delete();
            });
        } finally {
            if ($driver === 'mysql') {
                DB::statement('SET @simrs_synthetic_reset = 0');
            }
        }
    }

    private function deleteSyntheticEmergencyChains(): void
    {
        $assessments = SchemaQualifier::table('emergency_triage_assessments');
        if (! Schema::hasTable($assessments)) {
            return;
        }

        $encounters = SchemaQualifier::table('encounters');
        $patients = SchemaQualifier::table('patients');
        $encounterIds = DB::table($encounters)
            ->select($encounters.'.id')
            ->join($patients, $patients.'.id', '=', $encounters.'.patient_id')
            ->where($patients.'.is_synthetic', true);
        $documents = SchemaQualifier::table('emergency_clinical_documents');
        $documentIds = DB::table($documents)->select('id')->whereIn('encounter_id', clone $encounterIds);
        $proposals = SchemaQualifier::table('emergency_result_follow_up_proposals');
        $proposalIds = DB::table($proposals)->select('id')->whereIn('encounter_id', clone $encounterIds);
        $intents = SchemaQualifier::table('emergency_disposition_correction_intents');
        $intentIds = DB::table($intents)->select('id')->whereIn('encounter_id', clone $encounterIds);
        $handoffs = SchemaQualifier::table('emergency_inpatient_handoffs');
        $handoffIds = DB::table($handoffs)->select('id')->whereIn('source_encounter_id', clone $encounterIds);

        $resultPublicIds = collect([
            ...DB::table($assessments)->whereIn('encounter_id', clone $encounterIds)->pluck('public_id')->all(),
            ...DB::table(SchemaQualifier::table('emergency_clinical_document_versions'))->whereIn('emergency_clinical_document_id', clone $documentIds)->pluck('public_id')->all(),
            ...DB::table($proposals)->whereIn('encounter_id', clone $encounterIds)->pluck('public_id')->all(),
            ...DB::table(SchemaQualifier::table('emergency_result_follow_up_acceptances'))->whereIn('proposal_id', clone $proposalIds)->pluck('public_id')->all(),
            ...DB::table(SchemaQualifier::table('emergency_dispositions'))->whereIn('encounter_id', clone $encounterIds)->pluck('public_id')->all(),
            ...DB::table($intents)->whereIn('encounter_id', clone $encounterIds)->pluck('public_id')->all(),
            ...DB::table(SchemaQualifier::table('emergency_disposition_correction_intent_events'))->whereIn('correction_intent_id', clone $intentIds)->pluck('public_id')->all(),
            ...DB::table($handoffs)->whereIn('source_encounter_id', clone $encounterIds)->pluck('public_id')->all(),
            ...DB::table(SchemaQualifier::table('emergency_handoff_compensations'))->whereIn('handoff_id', clone $handoffIds)->pluck('public_id')->all(),
        ])->filter(fn (mixed $value): bool => is_string($value))->values()->all();

        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement("SET LOCAL simrs.synthetic_reset = '1'");
        } elseif ($driver === 'mysql') {
            DB::statement('SET @simrs_synthetic_reset = 1');
        }

        try {
            EmergencyMutationScope::run(function () use ($encounterIds, $documents, $documentIds, $proposals, $proposalIds, $intents, $intentIds, $handoffs, $handoffIds, $resultPublicIds): void {
                if ($resultPublicIds !== []) {
                    DB::table(SchemaQualifier::table('emergency_operation_receipts'))
                        ->whereIn('result_public_id', $resultPublicIds)
                        ->delete();
                }
                DB::table(SchemaQualifier::table('emergency_handoff_compensations'))
                    ->whereIn('handoff_id', clone $handoffIds)->delete();
                DB::table(SchemaQualifier::table('emergency_disposition_correction_intent_events'))
                    ->whereIn('correction_intent_id', clone $intentIds)->delete();
                DB::table($intents)->whereIn('encounter_id', clone $encounterIds)->delete();
                DB::table($handoffs)->whereIn('source_encounter_id', clone $encounterIds)->delete();
                DB::table(SchemaQualifier::table('emergency_dispositions'))
                    ->whereIn('encounter_id', clone $encounterIds)->delete();
                DB::table(SchemaQualifier::table('emergency_result_follow_up_acceptances'))
                    ->whereIn('proposal_id', clone $proposalIds)->delete();
                DB::table($proposals)->whereIn('encounter_id', clone $encounterIds)->delete();
                DB::table(SchemaQualifier::table('emergency_clinical_document_versions'))
                    ->whereIn('emergency_clinical_document_id', clone $documentIds)->delete();
                DB::table($documents)->whereIn('encounter_id', clone $encounterIds)->delete();
                DB::table(SchemaQualifier::table('emergency_triage_assessments'))
                    ->whereIn('encounter_id', $encounterIds)->delete();
            });
        } finally {
            if ($driver === 'mysql') {
                DB::statement('SET @simrs_synthetic_reset = 0');
            }
        }
    }

    private function deleteSyntheticRadiologyChains(): void
    {
        $orders = SchemaQualifier::table('radiology_orders');
        if (! Schema::hasTable($orders)) {
            return;
        }
        $encounters = SchemaQualifier::table('encounters');
        $patients = SchemaQualifier::table('patients');
        $encounterIds = DB::table($encounters)->select($encounters.'.id')->join($patients, $patients.'.id', '=', $encounters.'.patient_id')->where($patients.'.is_synthetic', true);
        $orderIds = DB::table($orders)->select($orders.'.id')->whereIn('encounter_id', clone $encounterIds);
        $reportIds = DB::table(SchemaQualifier::table('radiology_report_versions'))->select('id')->whereIn('radiology_order_id', clone $orderIds);
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement("SET LOCAL simrs.synthetic_reset = '1'");
        } elseif ($driver === 'mysql') {
            DB::statement('SET @simrs_synthetic_reset = 1');
        }
        try {
            RadiologyMutationScope::run(function () use ($orders, $orderIds, $reportIds, $encounterIds): void {
                $receipts = DB::table(SchemaQualifier::table('radiology_operation_receipts'));
                $receipts->where(function ($query) use ($orders, $orderIds, $reportIds): void {
                    $query->where(function ($typed) use ($orders, $orderIds): void {
                        $typed->where('result_type', RadiologyOperationReceipt::RESULT_ORDER)
                            ->whereIn('result_public_id', DB::table($orders)->select('public_id')->whereIn('id', clone $orderIds));
                    })->orWhere(function ($typed) use ($orderIds): void {
                        $typed->where('result_type', RadiologyOperationReceipt::RESULT_CANCELLATION)
                            ->whereIn('result_public_id', DB::table(SchemaQualifier::table('radiology_order_cancellations'))->select('public_id')->whereIn('radiology_order_id', clone $orderIds));
                    })->orWhere(function ($typed) use ($orderIds): void {
                        $typed->where('result_type', RadiologyOperationReceipt::RESULT_PERFORMANCE)
                            ->whereIn('result_public_id', DB::table(SchemaQualifier::table('radiology_performances'))->select('public_id')->whereIn('radiology_order_id', clone $orderIds));
                    })->orWhere(function ($typed) use ($reportIds): void {
                        $typed->where('result_type', RadiologyOperationReceipt::RESULT_REPORT_VERSION)
                            ->whereIn('result_public_id', DB::table(SchemaQualifier::table('radiology_report_versions'))->select('public_id')->whereIn('id', clone $reportIds));
                    })->orWhere(function ($typed) use ($reportIds): void {
                        $typed->where('result_type', RadiologyOperationReceipt::RESULT_ACKNOWLEDGEMENT)
                            ->whereIn('result_public_id', DB::table(SchemaQualifier::table('radiology_report_acknowledgements'))->select('public_id')->whereIn('radiology_report_version_id', clone $reportIds));
                    });
                })->delete();
                DB::table(SchemaQualifier::table('radiology_report_acknowledgements'))->whereIn('radiology_report_version_id', clone $reportIds)->delete();
                DB::table(SchemaQualifier::table('radiology_report_versions'))
                    ->whereIn('radiology_order_id', clone $orderIds)
                    ->where('state', RadiologyReportVersion::AMENDED_VERIFIED)->delete();
                DB::table(SchemaQualifier::table('radiology_report_versions'))
                    ->whereIn('radiology_order_id', clone $orderIds)->delete();
                DB::table(SchemaQualifier::table('radiology_performances'))->whereIn('radiology_order_id', clone $orderIds)->delete();
                DB::table(SchemaQualifier::table('radiology_order_cancellations'))->whereIn('radiology_order_id', clone $orderIds)->delete();
                DB::table($orders)->whereIn('encounter_id', $encounterIds)->delete();
            });
        } finally {
            if ($driver === 'mysql') {
                DB::statement('SET @simrs_synthetic_reset = 0');
            }
        }
    }

    private function deleteSyntheticLaboratoryChains(): void
    {
        $orders = SchemaQualifier::table('laboratory_orders');
        if (! Schema::hasTable($orders)) {
            return;
        }

        $encounters = SchemaQualifier::table('encounters');
        $patients = SchemaQualifier::table('patients');
        $encounterIds = DB::table($encounters)
            ->select($encounters.'.id')
            ->join($patients, $patients.'.id', '=', $encounters.'.patient_id')
            ->where($patients.'.is_synthetic', true);
        $orderIds = DB::table($orders)
            ->select($orders.'.id')
            ->whereIn('encounter_id', clone $encounterIds);
        $attempts = SchemaQualifier::table('laboratory_specimen_attempts');
        $attemptIds = DB::table($attempts)
            ->select($attempts.'.id')
            ->whereIn('laboratory_order_id', clone $orderIds);
        $results = SchemaQualifier::table('laboratory_result_versions');
        $resultIds = DB::table($results)
            ->select($results.'.id')
            ->whereIn('laboratory_order_id', clone $orderIds);
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("SET LOCAL simrs.synthetic_reset = '1'");
        } elseif ($driver === 'mysql') {
            DB::statement('SET @simrs_synthetic_reset = 1');
        }

        try {
            LaboratoryMutationScope::run(function () use ($orders, $orderIds, $attempts, $attemptIds, $results, $resultIds, $encounterIds): void {
                $receipts = DB::table(SchemaQualifier::table('laboratory_operation_receipts'));
                $receipts->where(function ($query) use ($orders, $orderIds, $attempts, $attemptIds, $results, $resultIds): void {
                    $query->where(function ($typed) use ($orders, $orderIds): void {
                        $typed->where('result_type', LaboratoryOperationReceipt::RESULT_ORDER)
                            ->whereIn('result_public_id', DB::table($orders)->select('public_id')->whereIn('id', clone $orderIds));
                    })->orWhere(function ($typed) use ($orderIds): void {
                        $typed->where('result_type', LaboratoryOperationReceipt::RESULT_CANCELLATION)
                            ->whereIn('result_public_id', DB::table(SchemaQualifier::table('laboratory_order_cancellations'))->select('public_id')->whereIn('laboratory_order_id', clone $orderIds));
                    })->orWhere(function ($typed) use ($attempts, $attemptIds): void {
                        $typed->where('result_type', LaboratoryOperationReceipt::RESULT_SPECIMEN_ATTEMPT)
                            ->whereIn('result_public_id', DB::table($attempts)->select('public_id')->whereIn('id', clone $attemptIds));
                    })->orWhere(function ($typed) use ($attemptIds): void {
                        $typed->where('result_type', LaboratoryOperationReceipt::RESULT_SPECIMEN_EVENT)
                            ->whereIn('result_public_id', DB::table(SchemaQualifier::table('laboratory_specimen_events'))->select('public_id')->whereIn('laboratory_specimen_attempt_id', clone $attemptIds));
                    })->orWhere(function ($typed) use ($results, $resultIds): void {
                        $typed->where('result_type', LaboratoryOperationReceipt::RESULT_RESULT_VERSION)
                            ->whereIn('result_public_id', DB::table($results)->select('public_id')->whereIn('id', clone $resultIds));
                    })->orWhere(function ($typed) use ($resultIds): void {
                        $typed->where('result_type', LaboratoryOperationReceipt::RESULT_ACKNOWLEDGEMENT)
                            ->whereIn('result_public_id', DB::table(SchemaQualifier::table('laboratory_result_acknowledgements'))->select('public_id')->whereIn('laboratory_result_version_id', clone $resultIds));
                    });
                })->delete();

                DB::table(SchemaQualifier::table('laboratory_result_acknowledgements'))
                    ->whereIn('laboratory_result_version_id', clone $resultIds)->delete();
                DB::table(SchemaQualifier::table('laboratory_critical_communications'))
                    ->whereIn('laboratory_result_version_id', clone $resultIds)->delete();
                DB::table($results)
                    ->whereIn('laboratory_order_id', clone $orderIds)
                    ->where('state', LaboratoryResultVersion::AMENDED_VERIFIED)
                    ->delete();
                DB::table($results)->whereIn('laboratory_order_id', clone $orderIds)->delete();
                DB::table(SchemaQualifier::table('laboratory_specimen_events'))
                    ->whereIn('laboratory_specimen_attempt_id', clone $attemptIds)->delete();
                DB::table($attempts)->whereIn('laboratory_order_id', clone $orderIds)->delete();
                DB::table(SchemaQualifier::table('laboratory_order_cancellations'))
                    ->whereIn('laboratory_order_id', clone $orderIds)->delete();
                DB::table($orders)->whereIn('encounter_id', $encounterIds)->delete();
            });
        } finally {
            if ($driver === 'mysql') {
                DB::statement('SET @simrs_synthetic_reset = 0');
            }
        }
    }
}
