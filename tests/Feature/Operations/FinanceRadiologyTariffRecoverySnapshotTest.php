<?php

namespace Tests\Feature\Operations;

use App\Models\Encounter;
use App\Models\FinanceCostComponent;
use App\Models\FinanceCostComponentGroup;
use App\Models\FinanceRadiologyTariffBinding;
use App\Models\FinanceTariffCatalogue;
use App\Models\FinanceTariffItem;
use App\Models\Patient;
use App\Models\RadiologyExaminationMaster;
use App\Models\RadiologyExaminationMasterVersion;
use App\Models\RadiologyOrder;
use App\Models\RadiologyPerformance;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Finance\FinanceAppendOnlyGuard;
use App\Support\Finance\FinanceBillService;
use App\Support\Finance\FinanceMutationScope;
use App\Support\Finance\FinanceRadiologyTariffAppendOnlyGuard;
use App\Support\Finance\FinanceRadiologyTariffBindingService;
use App\Support\Finance\FinanceRadiologyTariffMutationScope;
use App\Support\Finance\FinanceTariffMasterService;
use App\Support\Operations\SyntheticRecoverySnapshot;
use App\Support\Radiology\RadiologyMasterService;
use App\Support\Radiology\RadiologyMutationScope;
use App\Support\Simulation\SyntheticResetService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

final class FinanceRadiologyTariffRecoverySnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const INTEGRITY_METHODS = [
        'financeRadiologyBindingVersionChainMismatchCount',
        'financeRadiologyBindingHeadMismatchCount',
        'financeRadiologyBindingUpstreamMismatchCount',
        'financeRadiologyBindingReceiptResultMismatchCount',
        'financeRadiologySourceMismatchCount',
    ];

    private User $admin;

    private User $physician;

    private User $technologist;

    private User $cashier;

    private User $steward;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-02 10:00:00');
        $this->seed(RbacSeeder::class);
        $this->admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $this->physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $this->technologist = $this->actor(RoleCapabilityMatrix::ROLE_RADIOLOGY_TECHNOLOGIST);
        $this->cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $this->steward = $this->actor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_radiology_tariff_recovery_helpers_accept_an_empty_consistent_graph(): void
    {
        $this->assertIntegrityHelpersReturnZero();
    }

    public function test_snapshot_registers_radiology_binding_source_counts_digests_orphans_and_integrity(): void
    {
        $source = file_get_contents(app_path('Support/Operations/SyntheticRecoverySnapshot.php'));
        $this->assertIsString($source);

        foreach ([
            'finance_radiology_tariff_bindings',
            'finance_radiology_tariff_binding_versions',
            'finance_radiology_tariff_operation_receipts',
            'finance_radiology_source_events',
        ] as $table) {
            $this->assertStringContainsString("'{$table}' =>", $source);
            $this->assertStringContainsString("'{$table}_sha256' =>", $source);
        }
        foreach ([
            'finance_radiology_binding_without_master',
            'finance_radiology_binding_without_master_version',
            'finance_radiology_binding_version_without_binding',
            'finance_radiology_binding_version_without_tariff',
            'finance_radiology_binding_version_without_actor',
            'finance_radiology_receipt_without_actor',
            'finance_radiology_source_without_performance',
            'finance_radiology_source_without_order',
            'finance_radiology_source_without_binding_version',
            'finance_radiology_source_without_tariff_version',
            'finance_radiology_source_without_encounter',
            'finance_radiology_source_without_patient',
            'finance_radiology_source_without_importer',
            'finance_radiology_binding_version_chain_mismatches',
            'finance_radiology_binding_head_mismatches',
            'finance_radiology_binding_upstream_mismatches',
            'finance_radiology_binding_receipt_result_mismatches',
            'finance_radiology_source_mismatches',
        ] as $contract) {
            $this->assertStringContainsString("'{$contract}' =>", $source);
        }
        $this->assertStringContainsString("'finance_radiology_source_event_id'", $source);
    }

    public function test_integrity_helpers_reconcile_real_future_binding_and_materialized_source(): void
    {
        [, $tariff, $binding] = $this->materializedSourceFixture();

        app(FinanceRadiologyTariffBindingService::class)->appendVersion(
            $this->steward,
            $binding->public_id,
            $tariff->public_id,
            '2026-09-03',
            $binding->version,
            $binding->current_content_digest,
            'Versi pemetaan masa depan untuk bukti pemulihan.',
            'recovery-radio-binding-next',
        );

        $this->assertDatabaseCount('finance_radiology_tariff_binding_versions', 2);
        $this->assertDatabaseCount('finance_radiology_tariff_operation_receipts', 2);
        $this->assertDatabaseCount('finance_radiology_source_events', 1);
        $this->assertDatabaseCount('finance_charge_events', 1);
        $this->assertIntegrityHelpersReturnZero();
    }

    public function test_integrity_helpers_detect_binding_receipt_and_source_corruption(): void
    {
        $this->materializedSourceFixture();

        FinanceRadiologyTariffAppendOnlyGuard::runSyntheticReset(
            fn () => FinanceRadiologyTariffMutationScope::run(function (): void {
                DB::table('finance_radiology_tariff_binding_versions')
                    ->update(['previous_content_digest' => str_repeat('a', 64)]);
                DB::table('finance_radiology_tariff_operation_receipts')
                    ->update(['result_digest' => str_repeat('b', 64)]);
            }),
        );
        FinanceRadiologyTariffMutationScope::run(fn () => DB::table('finance_radiology_tariff_bindings')->update([
            'radiology_master_code' => 'CORRUPTED-MASTER',
            'current_content_digest' => str_repeat('c', 64),
        ]));
        FinanceAppendOnlyGuard::runSyntheticReset(
            fn () => FinanceMutationScope::run(fn () => DB::table('finance_charge_events')->delete()),
        );

        $this->assertGreaterThan(0, $this->integrityCount('financeRadiologyBindingVersionChainMismatchCount'));
        $this->assertGreaterThan(0, $this->integrityCount('financeRadiologyBindingHeadMismatchCount'));
        $this->assertGreaterThan(0, $this->integrityCount('financeRadiologyBindingUpstreamMismatchCount'));
        $this->assertGreaterThan(0, $this->integrityCount('financeRadiologyBindingReceiptResultMismatchCount'));
        $this->assertGreaterThan(0, $this->integrityCount('financeRadiologySourceMismatchCount'));
    }

    public function test_reset_removes_complete_radiology_finance_graph_and_preserves_audit_evidence(): void
    {
        $this->materializedSourceFixture();
        $auditIds = AuditEvent::query()
            ->whereIn('action', ['finance.tariff.mutate', 'finance.workflow.mutate'])
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $this->assertNotEmpty($auditIds);

        app(SyntheticResetService::class)->reset([
            'actor' => $this->steward,
            'reason' => 'finance_radiology_tariff_reset_test',
        ]);

        foreach ([
            'finance_operation_receipts', 'finance_bill_lines', 'finance_bill_versions',
            'finance_bills', 'finance_charge_events', 'finance_radiology_source_events',
            'finance_radiology_tariff_operation_receipts',
            'finance_radiology_tariff_binding_versions',
            'finance_radiology_tariff_bindings',
            'finance_tariff_operation_receipts', 'finance_tariff_item_versions',
            'finance_tariff_items', 'finance_tariff_catalogue_versions',
            'finance_tariff_catalogues', 'finance_cost_component_versions',
            'finance_cost_components', 'finance_cost_component_group_versions',
            'finance_cost_component_groups', 'finance_tariff_code_reservations',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table.' must be empty after reset.');
        }
        $this->assertSame(
            $auditIds,
            AuditEvent::query()->whereIn('id', $auditIds)->orderBy('id')->pluck('id')->all(),
        );
        $this->assertDatabaseHas('audit_events', [
            'action' => 'teaching.reset.completed',
            'resource_type' => 'simulation',
            'reason' => 'finance_radiology_tariff_reset_test',
        ]);
        $this->assertIntegrityHelpersReturnZero();
    }

    /** @return array{RadiologyExaminationMasterVersion,FinanceTariffItem,FinanceRadiologyTariffBinding} */
    private function materializedSourceFixture(): array
    {
        $master = app(RadiologyMasterService::class)->create(
            $this->admin,
            'RAD-RECOVERY',
            'Radiologi bukti pemulihan',
            null,
            'recovery-radio-master',
        )->record;
        $this->assertInstanceOf(RadiologyExaminationMaster::class, $master);
        $masterVersion = RadiologyExaminationMasterVersion::query()
            ->where('radiology_examination_master_id', $master->id)
            ->sole();
        $patient = Patient::factory()->create(['is_synthetic' => true]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'status' => Encounter::STATUS_IN_EXAMINATION,
            'clinic_name' => 'Poliklinik Pemulihan',
        ]);
        RadiologyMutationScope::run(function () use ($master, $masterVersion, $encounter): void {
            $order = RadiologyOrder::query()->create([
                'encounter_id' => $encounter->id,
                'master_id' => $master->id,
                'ordered_by_user_id' => $this->physician->id,
                'master_version' => $masterVersion->version,
                'master_version_public_id' => $masterVersion->public_id,
                'master_content_digest' => $masterVersion->content_digest,
                'master_code' => $master->examination_code,
                'master_display_name' => $masterVersion->display_name,
                'master_preparation_instruction' => $masterVersion->preparation_instruction,
                'care_setting' => $encounter->care_setting,
                'encounter_status_snapshot' => $encounter->status,
                'encounter_number_snapshot' => $encounter->public_id,
                'care_location_label_snapshot' => $encounter->clinic_name,
                'clinical_indication' => 'Indikasi pemeriksaan sintetis.',
                'status' => RadiologyOrder::PERFORMED,
                'version' => 2,
                'ordered_at' => now()->subHour(),
            ]);
            RadiologyPerformance::query()->create([
                'radiology_order_id' => $order->id,
                'performed_by_user_id' => $this->technologist->id,
                'performed_at' => now(),
                'created_at' => now(),
            ]);
        });

        $tariffs = app(FinanceTariffMasterService::class);
        $group = $tariffs->createGroup(
            $this->steward, 'RECOVERY-RADIO-GROUP', 'Kelompok radiologi pemulihan',
            'Membuat kelompok bukti pemulihan.', 'recovery-radio-group',
        )->record;
        $this->assertInstanceOf(FinanceCostComponentGroup::class, $group);
        $component = $tariffs->createComponent(
            $this->steward, $group->public_id, 'RECOVERY-RADIO-COMPONENT',
            'Komponen radiologi pemulihan', null, null,
            'Membuat komponen bukti pemulihan.', 'recovery-radio-component',
        )->record;
        $this->assertInstanceOf(FinanceCostComponent::class, $component);
        $catalogue = $tariffs->createCatalogue(
            $this->steward, 'RECOVERY-RADIO-CATALOGUE', 'Katalog radiologi pemulihan',
            'Membuat katalog bukti pemulihan.', 'recovery-radio-catalogue',
        )->record;
        $this->assertInstanceOf(FinanceTariffCatalogue::class, $catalogue);
        $tariff = $tariffs->createTariffItem(
            $this->steward, $catalogue->public_id, $component->public_id,
            'RECOVERY-RADIO-TARIFF', 'Tarif radiologi pemulihan', 'OUTPATIENT',
            'RADIOLOGY', null, null, 75000, '2026-09-02',
            'Membuat tarif bukti pemulihan.', 'recovery-radio-tariff',
        )->record;
        $this->assertInstanceOf(FinanceTariffItem::class, $tariff);
        $binding = app(FinanceRadiologyTariffBindingService::class)->create(
            $this->steward, $masterVersion->public_id, 'OUTPATIENT', $tariff->public_id,
            '2026-09-02', 'Pemetaan untuk bukti pemulihan.', 'recovery-radio-binding',
        )->record;
        $this->assertInstanceOf(FinanceRadiologyTariffBinding::class, $binding);

        app(FinanceBillService::class)->synchronize(
            $encounter->public_id,
            $this->cashier,
            'recovery-radio-finance-sync',
        );

        return [$masterVersion, $tariff->fresh(), $binding->fresh()];
    }

    private function assertIntegrityHelpersReturnZero(): void
    {
        foreach (self::INTEGRITY_METHODS as $methodName) {
            $this->assertSame(0, $this->integrityCount($methodName), $methodName);
        }
    }

    private function integrityCount(string $methodName): int
    {
        $snapshot = new SyntheticRecoverySnapshot;
        $value = (new ReflectionClass($snapshot))->getMethod($methodName)->invoke($snapshot);

        return is_int($value) ? $value : -1;
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $user->fresh();
    }
}
