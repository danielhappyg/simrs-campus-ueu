<?php

namespace Tests\Feature\Operations;

use App\Models\Encounter;
use App\Models\FinanceBill;
use App\Models\Patient;
use App\Models\PharmacyDepot;
use App\Models\PharmacyHandover;
use App\Models\PharmacyMedicine;
use App\Models\PharmacyPreparation;
use App\Models\PharmacyPrescription;
use App\Models\PharmacyReturnItem;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Finance\FinanceBillService;
use App\Support\Finance\FinanceEvidenceFingerprint;
use App\Support\Operations\SyntheticRecoverySnapshot;
use App\Support\Pharmacy\PharmacyEvidenceFingerprint;
use App\Support\Pharmacy\PharmacyMasterService;
use App\Support\Pharmacy\PharmacyStockService;
use App\Support\Pharmacy\PharmacyWorkflowService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

final class FinanceRecoverySnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_finance_integrity_helpers_accept_an_empty_consistent_spine(): void
    {
        $snapshot = new SyntheticRecoverySnapshot;
        $reflection = new ReflectionClass($snapshot);

        foreach ([
            'financeRetainedSourceMismatchCount',
            'financeLineSourceMismatchCount',
            'financeVersionControlMismatchCount',
            'financePreviousVersionChainMismatchCount',
            'financeBillHeadMismatchCount',
            'financeReceiptResultMismatchCount',
        ] as $methodName) {
            $this->assertSame(0, $reflection->getMethod($methodName)->invoke($snapshot), $methodName);
        }
    }

    public function test_snapshot_registers_the_complete_finance_recovery_surface(): void
    {
        $source = file_get_contents(app_path('Support/Operations/SyntheticRecoverySnapshot.php'));
        $this->assertIsString($source);

        foreach ([
            "'finance_charge_events' =>",
            "'finance_bills' =>",
            "'finance_bill_versions' =>",
            "'finance_bill_lines' =>",
            "'finance_operation_receipts' =>",
            "'finance_charge_events_sha256' =>",
            "'finance_bills_sha256' =>",
            "'finance_bill_versions_sha256' =>",
            "'finance_bill_lines_sha256' =>",
            "'finance_operation_receipts_sha256' =>",
            "'finance_retained_source_mismatches' =>",
            "'finance_line_source_mismatches' =>",
            "'finance_version_control_mismatches' =>",
            "'finance_previous_version_chain_mismatches' =>",
            "'finance_bill_head_mismatches' =>",
            "'finance_receipt_result_mismatches' =>",
            "'finance_charge_without_pharmacy_source' =>",
            "'finance_line_without_charge' =>",
            "'finance_receipt_without_actor' =>",
        ] as $contract) {
            $this->assertStringContainsString($contract, $source);
        }

        $this->assertStringContainsString('The recovery fixture violates application domain integrity.', $source);
        $this->assertStringNotContainsString('violates emergency handoff or inpatient admission integrity', $source);
    }

    public function test_integrity_helpers_reconcile_a_real_issued_finance_version(): void
    {
        $inventory = $this->actor(RoleCapabilityMatrix::ROLE_PHARMACY_INVENTORY_CONTROLLER);
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $pharmacist = $this->actor(RoleCapabilityMatrix::ROLE_PHARMACIST);
        $technician = $this->actor(RoleCapabilityMatrix::ROLE_PHARMACY_TECHNICIAN);
        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $encounter = Encounter::factory()->create([
            'patient_id' => Patient::factory()->create(['is_synthetic' => true])->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'status' => Encounter::STATUS_IN_EXAMINATION,
            'clinic_name' => 'Poliklinik Uji Pemulihan',
        ]);

        $masters = app(PharmacyMasterService::class);
        $medicineResult = $masters->createMedicine($inventory, [
            'medicine_code' => 'MED-REC-FIN-01',
            'generic_name' => 'Obat Pemulihan',
            'brand_name' => null,
            'strength_text' => '500 mg',
            'dosage_form' => 'TABLET',
            'base_unit' => 'TABLET',
            'route_choices' => ['ORAL'],
            'acquisition_value' => 400,
            'teaching_sale_value' => 1250,
        ], 'recovery-finance-medicine');
        $medicine = PharmacyMedicine::query()->whereKey($medicineResult->record->getKey())->sole();
        $depotResult = $masters->createDepot($inventory, [
            'depot_code' => 'DEPO-REC-FIN',
            'display_name' => 'Depo Pemulihan Keuangan',
            'eligible_care_settings' => [Encounter::CARE_SETTING_OUTPATIENT],
        ], 'recovery-finance-depot');
        $depot = PharmacyDepot::query()->whereKey($depotResult->record->getKey())->sole();
        app(PharmacyStockService::class)->openLot($inventory, $medicine->public_id, $depot->public_id, [
            'lot_code' => 'LOT-REC-FIN-01',
            'received_at' => now()->subDay(),
            'expiry_date' => now()->addMonth()->toDateString(),
            'opening_quantity' => 5,
            'source_reference' => 'RECOVERY-FINANCE-TEST',
        ], 'recovery-finance-lot');

        $workflow = app(PharmacyWorkflowService::class);
        $draftResult = $workflow->createDraft($encounter->public_id, $physician, $depot->public_id, [[
            'medicine_public_id' => $medicine->public_id,
            'dose_text' => '1 tablet',
            'route' => 'ORAL',
            'frequency_text' => '2 kali sehari',
            'duration_text' => '1 hari',
            'requested_quantity' => 2,
            'instruction' => 'Sesudah makan',
        ]], 'Resep bukti pemulihan.', 'recovery-finance-draft');
        $prescription = PharmacyPrescription::query()->whereKey($draftResult->record->getKey())->sole();
        $orderResult = $workflow->order(
            $prescription->public_id,
            $physician,
            1,
            app(PharmacyEvidenceFingerprint::class)->prescription($prescription),
            'recovery-finance-order',
        );
        $prescription = PharmacyPrescription::query()->with('items')->whereKey($orderResult->record->getKey())->sole();
        $workflow->verify(
            $prescription->public_id,
            $pharmacist,
            app(PharmacyEvidenceFingerprint::class)->prescription($prescription),
            'REVIEWED_NO_CONFLICT',
            ['identity_confirmed' => true, 'context_confirmed' => true, 'medicine_readable' => true, 'instruction_readable' => true],
            [['item_public_id' => $prescription->items->sole()->public_id, 'verified_quantity' => 2, 'reason_code' => null]],
            'recovery-finance-verify',
        );
        $prescription = PharmacyPrescription::query()->whereKey($prescription->id)->sole();
        $preparationResult = $workflow->prepare(
            $prescription->public_id,
            $technician,
            app(PharmacyEvidenceFingerprint::class)->prescription($prescription),
            'recovery-finance-prepare',
        );
        $preparation = PharmacyPreparation::query()->with('allocations')->whereKey($preparationResult->record->getKey())->sole();
        $handoverResult = $workflow->handover(
            $preparation->public_id,
            $pharmacist,
            app(PharmacyEvidenceFingerprint::class)->preparation($preparation),
            null,
            'recovery-finance-handover',
        );
        $handover = PharmacyHandover::query()->with('items.returnItems')->whereKey($handoverResult->record->getKey())->sole();

        $finance = app(FinanceBillService::class);
        $syncResult = $finance->synchronize($encounter->public_id, $cashier, 'recovery-finance-sync');
        $bill = FinanceBill::query()->whereKey($syncResult->record->getKey())->sole();
        $finance->issue(
            $bill->public_id,
            $cashier,
            app(FinanceEvidenceFingerprint::class)->bill($bill),
            'Penerbitan bukti pemulihan.',
            'recovery-finance-issue',
        );
        $workflow->recordReturn(
            $handover->public_id,
            $prescription->public_id,
            $pharmacist,
            app(PharmacyEvidenceFingerprint::class)->handover($handover),
            'PATIENT_RETURN',
            'Satu tablet dikembalikan untuk bukti versi kedua.',
            [[
                'handover_item_public_id' => $handover->items->sole()->public_id,
                'condition' => PharmacyReturnItem::RETURN_TO_STOCK,
                'quantity' => 1,
            ]],
            'recovery-finance-return',
        );
        $resyncResult = $finance->synchronize($encounter->public_id, $cashier, 'recovery-finance-resync');
        $bill = FinanceBill::query()->whereKey($resyncResult->record->getKey())->sole();
        $finance->issue(
            $bill->public_id,
            $cashier,
            app(FinanceEvidenceFingerprint::class)->bill($bill),
            'Penerbitan ulang setelah retur.',
            'recovery-finance-reissue',
        );

        $snapshot = new SyntheticRecoverySnapshot;
        $reflection = new ReflectionClass($snapshot);
        foreach ([
            'financeRetainedSourceMismatchCount',
            'financeLineSourceMismatchCount',
            'financeVersionControlMismatchCount',
            'financePreviousVersionChainMismatchCount',
            'financeBillHeadMismatchCount',
            'financeReceiptResultMismatchCount',
        ] as $methodName) {
            $this->assertSame(0, $reflection->getMethod($methodName)->invoke($snapshot), $methodName);
        }
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $user->fresh();
    }
}
