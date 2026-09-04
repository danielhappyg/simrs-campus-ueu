<?php

namespace Tests\Feature\Operations;

use App\Models\Encounter;
use App\Models\FinanceAccommodationTariffBinding;
use App\Models\FinanceBill;
use App\Models\FinanceBillVersion;
use App\Models\FinanceCostComponent;
use App\Models\FinanceCostComponentGroup;
use App\Models\FinanceTariffCatalogue;
use App\Models\FinanceTariffItem;
use App\Models\InpatientBed;
use App\Models\InpatientBedVersion;
use App\Models\InpatientDischargeCodingSource;
use App\Models\InpatientDischargeSummary;
use App\Models\InpatientWard;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Finance\FinanceAccommodationSourceAdapter;
use App\Support\Finance\FinanceAccommodationTariffAppendOnlyGuard;
use App\Support\Finance\FinanceAccommodationTariffBindingService;
use App\Support\Finance\FinanceAccommodationTariffMutationScope;
use App\Support\Finance\FinanceAppendOnlyGuard;
use App\Support\Finance\FinanceBillService;
use App\Support\Finance\FinanceMutationScope;
use App\Support\Finance\FinanceProjection;
use App\Support\Finance\FinanceTariffMasterService;
use App\Support\Inpatient\InpatientAdmissionService;
use App\Support\Inpatient\InpatientBedTransferService;
use App\Support\Inpatient\InpatientDischargeCodingSourceService;
use App\Support\Inpatient\InpatientDischargeService;
use App\Support\Inpatient\InpatientDischargeSummaryService;
use App\Support\Inpatient\InpatientMasterService;
use App\Support\Operations\SyntheticRecoverySnapshot;
use App\Support\Simulation\SyntheticResetService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use RuntimeException;
use Tests\Support\ExactEngineTestFixture;
use Tests\TestCase;

final class FinanceAccommodationRecoverySnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const INTEGRITY_METHODS = [
        'financeAccommodationBindingVersionChainMismatchCount',
        'financeAccommodationBindingHeadMismatchCount',
        'financeAccommodationBindingUpstreamMismatchCount',
        'financeAccommodationBindingReceiptResultMismatchCount',
        'financeAccommodationSourceMismatchCount',
    ];

    private User $admin;

    private User $registrar;

    private User $cashier;

    private User $physician;

    private User $steward;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-02 08:00:00');
        $this->seed(RbacSeeder::class);
        $this->admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $this->registrar = $this->actor(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $this->cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $this->physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $this->steward = $this->actor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_snapshot_registers_accommodation_counts_digests_orphans_integrity_and_exact_four_domain_union(): void
    {
        $source = file_get_contents(app_path('Support/Operations/SyntheticRecoverySnapshot.php'));
        $this->assertIsString($source);

        foreach ([
            'finance_accommodation_tariff_bindings',
            'finance_accommodation_tariff_binding_versions',
            'finance_accommodation_tariff_operation_receipts',
            'finance_accommodation_source_events',
        ] as $table) {
            $this->assertStringContainsString("'{$table}' =>", $source);
            $this->assertStringContainsString("'{$table}_sha256' =>", $source);
        }
        foreach ([
            'finance_charge_without_accommodation_source',
            'finance_accommodation_binding_without_bed',
            'finance_accommodation_binding_without_bed_version',
            'finance_accommodation_binding_version_without_binding',
            'finance_accommodation_binding_version_without_tariff',
            'finance_accommodation_binding_version_without_actor',
            'finance_accommodation_receipt_without_actor',
            'finance_accommodation_source_without_opening_location',
            'finance_accommodation_source_without_bed_version',
            'finance_accommodation_source_without_closing_location',
            'finance_accommodation_source_without_discharge',
            'finance_accommodation_source_without_binding_version',
            'finance_accommodation_source_without_tariff_version',
            'finance_accommodation_source_without_encounter',
            'finance_accommodation_source_without_patient',
            'finance_accommodation_source_without_importer',
            'finance_accommodation_binding_version_chain_mismatches',
            'finance_accommodation_binding_head_mismatches',
            'finance_accommodation_binding_upstream_mismatches',
            'finance_accommodation_binding_receipt_result_mismatches',
            'finance_accommodation_source_mismatches',
        ] as $contract) {
            $this->assertStringContainsString("'{$contract}' =>", $source);
        }
        foreach ([
            'pharmacy_financial_source_event_id',
            'finance_radiology_source_event_id',
            'finance_laboratory_source_event_id',
            'finance_accommodation_source_event_id',
        ] as $foreignKey) {
            $this->assertStringContainsString("'{$foreignKey}'", $source);
        }
    }

    public function test_integrity_helpers_reconcile_a_real_closed_occupancy_source(): void
    {
        $this->materializedSourceFixture();

        $this->assertDatabaseCount('finance_accommodation_tariff_bindings', 1);
        $this->assertDatabaseCount('finance_accommodation_tariff_binding_versions', 1);
        $this->assertDatabaseCount('finance_accommodation_tariff_operation_receipts', 1);
        $this->assertDatabaseCount('finance_accommodation_source_events', 1);
        $this->assertDatabaseCount('finance_charge_events', 1);
        $this->assertIntegrityHelpersReturnZero();
        $this->assertSame(0, $this->integrityCount('financeTypedSourceMismatchCount'));
    }

    public function test_integrity_helpers_detect_binding_receipt_occupancy_source_and_typed_charge_corruption(): void
    {
        $this->materializedSourceFixture();

        FinanceAccommodationTariffAppendOnlyGuard::runSyntheticReset(
            fn () => FinanceAccommodationTariffMutationScope::run(function (): void {
                DB::table('finance_accommodation_tariff_binding_versions')
                    ->update(['previous_content_digest' => str_repeat('a', 64)]);
                DB::table('finance_accommodation_tariff_operation_receipts')
                    ->update(['result_digest' => str_repeat('b', 64)]);
            }),
        );
        FinanceAccommodationTariffMutationScope::run(fn () => DB::table('finance_accommodation_tariff_bindings')->update([
            'bed_code' => 'CORRUPTED-BED',
            'current_content_digest' => str_repeat('c', 64),
        ]));
        $typedCorruptionApplied = ExactEngineTestFixture::corruptWithoutPostgresCheck(
            'finance_charge_events',
            'fce_value_ck',
            function (): bool {
                FinanceAppendOnlyGuard::runSyntheticReset(
                    fn () => FinanceMutationScope::run(fn () => DB::table('finance_charge_events')->update([
                        'source_domain' => 'PHARMACY',
                    ])),
                );

                $this->assertGreaterThan(0, $this->integrityCount('financeTypedSourceMismatchCount'));

                return true;
            },
        );

        $this->assertGreaterThan(0, $this->integrityCount('financeAccommodationBindingVersionChainMismatchCount'));
        $this->assertGreaterThan(0, $this->integrityCount('financeAccommodationBindingHeadMismatchCount'));
        $this->assertGreaterThan(0, $this->integrityCount('financeAccommodationBindingUpstreamMismatchCount'));
        $this->assertGreaterThan(0, $this->integrityCount('financeAccommodationBindingReceiptResultMismatchCount'));
        $this->assertGreaterThan(0, $this->integrityCount('financeAccommodationSourceMismatchCount'));
        if ($typedCorruptionApplied === null) {
            $this->assertSame(0, $this->integrityCount('financeTypedSourceMismatchCount'));
        }
    }

    public function test_reset_removes_accommodation_graph_before_inpatient_and_tariff_parents_and_preserves_audit(): void
    {
        $this->materializedSourceFixture();
        $auditIds = AuditEvent::query()->orderBy('id')->pluck('id')->all();
        $this->assertNotEmpty($auditIds);

        app(SyntheticResetService::class)->reset([
            'actor' => $this->steward,
            'reason' => 'finance_accommodation_reset_test',
        ]);

        foreach ([
            'finance_accommodation_source_events',
            'finance_accommodation_tariff_operation_receipts',
            'finance_accommodation_tariff_binding_versions',
            'finance_accommodation_tariff_bindings',
            'finance_charge_events',
            'finance_tariff_operation_receipts',
            'finance_tariff_item_versions',
            'finance_tariff_items',
            'finance_tariff_catalogue_versions',
            'finance_tariff_catalogues',
            'finance_cost_component_versions',
            'finance_cost_components',
            'finance_cost_component_group_versions',
            'finance_cost_component_groups',
            'inpatient_location_events',
            'inpatient_bed_versions',
            'inpatient_beds',
            'inpatient_ward_versions',
            'inpatient_wards',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table.' must be empty after reset.');
        }
        $this->assertSame($auditIds, AuditEvent::query()->whereIn('id', $auditIds)->orderBy('id')->pluck('id')->all());
        $this->assertDatabaseHas('audit_events', [
            'action' => 'teaching.reset.completed',
            'resource_type' => 'simulation',
            'reason' => 'finance_accommodation_reset_test',
        ]);
        $this->assertIntegrityHelpersReturnZero();
    }

    public function test_completion_audit_failure_rolls_back_the_entire_accommodation_reset(): void
    {
        $this->routineDischargeBillFixture();
        $before = $this->retainedCounts();
        $this->app->instance(AuditRecorder::class, new class extends AuditRecorder
        {
            private int $calls = 0;

            public function record(string $action, string $resourceType, ?string $resourceId = null, ?User $actor = null, string $outcome = 'SUCCESS', ?string $reason = null, array $metadata = [], ?Request $request = null, bool $includeRequestFingerprint = true): ?AuditEvent
            {
                $this->calls++;

                return $this->calls === 1 ? new AuditEvent : null;
            }
        });

        try {
            app(SyntheticResetService::class)->reset([
                'actor' => $this->steward,
                'reason' => 'finance_accommodation_reset_rollback_test',
            ]);
            $this->fail('A missing completion audit must roll back the reset.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('completion audit event', $exception->getMessage());
        }

        $this->assertSame($before, $this->retainedCounts());
        $this->assertIntegrityHelpersReturnZero();
    }

    public function test_reset_removes_discharge_backed_accommodation_sources_and_issued_bill_before_inpatient_parents(): void
    {
        $this->routineDischargeBillFixture();
        $auditIds = AuditEvent::query()->orderBy('id')->pluck('id')->all();

        $this->assertDatabaseCount('inpatient_discharges', 1);
        $this->assertDatabaseCount('finance_accommodation_source_events', 3);
        $this->assertSame(3, DB::table('finance_accommodation_source_events')->whereNotNull('inpatient_discharge_id')->count());
        $this->assertDatabaseCount('finance_charge_events', 3);
        $this->assertDatabaseCount('finance_bills', 1);
        $this->assertDatabaseCount('finance_bill_versions', 1);
        $this->assertDatabaseCount('finance_bill_lines', 3);

        app(SyntheticResetService::class)->reset([
            'actor' => $this->steward,
            'reason' => 'finance_accommodation_discharge_reset_order_test',
        ]);

        foreach ([
            'finance_bill_lines', 'finance_bill_versions', 'finance_bills',
            'finance_charge_events', 'finance_accommodation_source_events',
            'inpatient_discharges', 'inpatient_location_events', 'encounters', 'patients',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table.' must be empty after reset.');
        }
        $this->assertSame($auditIds, AuditEvent::query()->whereIn('id', $auditIds)->orderBy('id')->pluck('id')->all());
        $this->assertDatabaseHas('audit_events', [
            'action' => 'teaching.reset.completed',
            'resource_type' => 'simulation',
            'reason' => 'finance_accommodation_discharge_reset_order_test',
        ]);
    }

    private function materializedSourceFixture(): void
    {
        $masters = app(InpatientMasterService::class);
        $ward = $masters->createWard(
            $this->admin, 'RECOVERY-AKOM', 'Bangsal Pemulihan Akomodasi',
            InpatientMasterService::REASON_INITIAL_SETUP, 'recovery-accommodation-ward', null,
        )->master;
        $this->assertInstanceOf(InpatientWard::class, $ward);
        $sourceBed = $this->createBed($masters, $ward, 'REC-AKOM-01', 'recovery-accommodation-bed-1');
        $targetBed = $this->createBed($masters, $ward, 'REC-AKOM-02', 'recovery-accommodation-bed-2');
        $sourceVersion = InpatientBedVersion::query()->where('bed_id', $sourceBed->id)->where('version', 1)->sole();
        $tariff = $this->tariff();
        $binding = app(FinanceAccommodationTariffBindingService::class)->create(
            $this->steward, $sourceVersion->public_id, 'INPATIENT', $tariff->public_id,
            '2026-09-02', 'Pemetaan pemulihan akomodasi.', 'recovery-accommodation-binding',
        )->record;
        $this->assertInstanceOf(FinanceAccommodationTariffBinding::class, $binding);

        $patient = Patient::factory()->create(['is_synthetic' => true]);
        $admission = app(InpatientAdmissionService::class)->admitDirect(
            $patient, $this->registrar, $sourceBed->public_id, Encounter::PAYER_UMUM,
            null, Encounter::CONTINUE_LANGSUNG, 'Perawatan akomodasi sintetis', registeredAt: Carbon::now(),
            admissionAuthorityType: Encounter::AUTHORITY_PLANNED_ORDER,
            admissionAuthorityReference: 'ORDER-ACCOMMODATION-RECOVERY-0001',
        );
        Carbon::setTestNow('2026-09-02 16:00:00');
        app(InpatientBedTransferService::class)->transfer(
            $admission->encounter->public_id, $this->registrar, 1, $sourceBed->public_id,
            $targetBed->public_id, 'Menutup interval akomodasi pertama.', 'recovery-accommodation-transfer',
        );
        app(FinanceAccommodationSourceAdapter::class)->synchronize($admission->encounter->fresh(), $this->cashier);
    }

    private function routineDischargeBillFixture(): void
    {
        $masters = app(InpatientMasterService::class);
        $ward = $masters->createWard(
            $this->admin, 'RECOVERY-AKOM', 'Bangsal Pemulihan Akomodasi',
            InpatientMasterService::REASON_INITIAL_SETUP, 'recovery-accommodation-ward', null,
        )->master;
        $this->assertInstanceOf(InpatientWard::class, $ward);
        $bed = $this->createBed($masters, $ward, 'REC-AKOM-01', 'recovery-accommodation-bed-1');
        $bedVersion = InpatientBedVersion::query()->where('bed_id', $bed->id)->where('version', 1)->sole();
        $tariff = $this->tariff();
        app(FinanceAccommodationTariffBindingService::class)->create(
            $this->steward, $bedVersion->public_id, 'INPATIENT', $tariff->public_id,
            '2026-09-02', 'Pemetaan pemulihan akomodasi.', 'recovery-accommodation-binding',
        );

        $patient = Patient::factory()->create(['is_synthetic' => true]);
        $admission = app(InpatientAdmissionService::class)->admitDirect(
            $patient, $this->registrar, $bed->public_id, Encounter::PAYER_UMUM,
            null, Encounter::CONTINUE_LANGSUNG, 'Perawatan akomodasi sintetis', registeredAt: Carbon::now(),
            admissionAuthorityType: Encounter::AUTHORITY_PLANNED_ORDER,
            admissionAuthorityReference: 'ORDER-ACCOMMODATION-RECOVERY-0002',
        );
        $summaries = app(InpatientDischargeSummaryService::class);
        $draft = $summaries->saveDraft(
            $admission->encounter->public_id, $this->physician, InpatientDischargeSummary::DEFINITION_VERSION,
            0, $this->completeDischargeFields(), 'recovery-accommodation-summary-draft',
        );
        $final = $summaries->finalize(
            $admission->encounter->public_id, $this->physician, InpatientDischargeSummary::DEFINITION_VERSION,
            $draft->summary->version, 'recovery-accommodation-summary-final',
        );
        $coding = app(InpatientDischargeCodingSourceService::class);
        $codingDraft = $coding->saveDraft(
            $admission->encounter->public_id, $this->physician, InpatientDischargeCodingSource::DEFINITION_VERSION, 0,
            [
                'principal_diagnosis_statement' => 'Observasi sintetis',
                'secondary_diagnosis_statements' => [],
                'procedure_attestation' => InpatientDischargeCodingSource::ATTESTATION_NONE,
                'performed_procedure_statements' => [],
            ],
            'recovery-accommodation-coding-draft',
        );
        $coding->finalize(
            $admission->encounter->public_id, $this->physician, InpatientDischargeCodingSource::DEFINITION_VERSION,
            $codingDraft->source->version, 'recovery-accommodation-coding-final',
        );

        Carbon::setTestNow('2026-09-04 08:00:00');
        app(InpatientDischargeService::class)->execute(
            $admission->encounter->public_id, $this->physician, $final->summary->version, 1,
            $bed->public_id, 'recovery-accommodation-discharge',
        );
        $bill = app(FinanceBillService::class)->synchronize(
            $admission->encounter->public_id, $this->cashier, 'recovery-accommodation-bill-sync',
        )->record;
        $this->assertInstanceOf(FinanceBill::class, $bill);
        $version = app(FinanceBillService::class)->issue(
            $bill->public_id, $this->cashier, app(FinanceProjection::class)->fingerprint($bill->fresh()),
            'Penerbitan tagihan akomodasi pemulihan.', 'recovery-accommodation-bill-issue',
        )->record;
        $this->assertInstanceOf(FinanceBillVersion::class, $version);
    }

    /** @return array<string, string> */
    private function completeDischargeFields(): array
    {
        return [
            'admission_reason' => 'Observasi',
            'significant_findings' => 'Stabil',
            'care_and_treatment_summary' => 'Pemantauan',
            'condition_at_discharge' => 'Baik',
            'follow_up_plan' => 'Kontrol',
        ];
    }

    private function tariff(): FinanceTariffItem
    {
        $service = app(FinanceTariffMasterService::class);
        $group = $service->createGroup($this->steward, 'GREC-AKOM', 'Grup pemulihan akomodasi', 'Penyiapan pemulihan.', 'recovery-accommodation-group')->record;
        $this->assertInstanceOf(FinanceCostComponentGroup::class, $group);
        $component = $service->createComponent($this->steward, $group->public_id, 'CREC-AKOM', 'Komponen pemulihan akomodasi', null, null, 'Penyiapan pemulihan.', 'recovery-accommodation-component')->record;
        $this->assertInstanceOf(FinanceCostComponent::class, $component);
        $catalogue = $service->createCatalogue($this->steward, 'KREC-AKOM', 'Katalog pemulihan akomodasi', 'Penyiapan pemulihan.', 'recovery-accommodation-catalogue')->record;
        $this->assertInstanceOf(FinanceTariffCatalogue::class, $catalogue);
        $tariff = $service->createTariffItem(
            $this->steward, $catalogue->public_id, $component->public_id, 'TREC-AKOM',
            'Tarif pemulihan akomodasi', 'INPATIENT', 'ACCOMMODATION', null, null,
            10000, '2026-09-02', 'Penyiapan pemulihan.', 'recovery-accommodation-tariff',
        )->record;
        $this->assertInstanceOf(FinanceTariffItem::class, $tariff);

        return $tariff;
    }

    private function createBed(InpatientMasterService $service, InpatientWard $ward, string $code, string $key): InpatientBed
    {
        $bed = $service->createBed(
            $this->admin, $ward->public_id, $code, 'Bed '.$code, 'Ruang Pemulihan', 'Kelas 1',
            InpatientMasterService::REASON_INITIAL_SETUP, $key, null,
        )->master;
        $this->assertInstanceOf(InpatientBed::class, $bed);

        return $bed;
    }

    private function assertIntegrityHelpersReturnZero(): void
    {
        foreach (self::INTEGRITY_METHODS as $method) {
            $this->assertSame(0, $this->integrityCount($method), $method.' must reconcile.');
        }
    }

    private function integrityCount(string $method): int
    {
        $reflection = new ReflectionClass(SyntheticRecoverySnapshot::class);
        $target = $reflection->getMethod($method);

        return (int) $target->invoke(app(SyntheticRecoverySnapshot::class));
    }

    /** @return array<string, int> */
    private function retainedCounts(): array
    {
        $counts = [];
        foreach ([
            'finance_accommodation_source_events', 'finance_charge_events',
            'finance_bill_lines', 'finance_bill_versions', 'finance_bills', 'finance_operation_receipts',
            'finance_accommodation_tariff_operation_receipts',
            'finance_accommodation_tariff_binding_versions',
            'finance_accommodation_tariff_bindings', 'finance_tariff_items',
            'inpatient_discharges', 'inpatient_discharge_operation_receipts',
            'inpatient_location_events', 'encounters', 'patients',
        ] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $user->fresh();
    }
}
