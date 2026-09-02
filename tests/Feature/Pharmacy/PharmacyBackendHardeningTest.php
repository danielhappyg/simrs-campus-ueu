<?php

namespace Tests\Feature\Pharmacy;

use App\Models\Encounter;
use App\Models\Patient;
use App\Models\PharmacyDepot;
use App\Models\PharmacyHandover;
use App\Models\PharmacyMedicine;
use App\Models\PharmacyPreparation;
use App\Models\PharmacyPrescription;
use App\Models\PharmacyReturnItem;
use App\Models\PharmacyStockLot;
use App\Models\PharmacyStockMovement;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Pharmacy\PharmacyDenied;
use App\Support\Pharmacy\PharmacyEncounterLifecycleGate;
use App\Support\Pharmacy\PharmacyEvidenceFingerprint;
use App\Support\Pharmacy\PharmacyMasterService;
use App\Support\Pharmacy\PharmacyProjection;
use App\Support\Pharmacy\PharmacyStockService;
use App\Support\Pharmacy\PharmacyWorkflowService;
use App\Support\Simulation\SyntheticResetService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PharmacyBackendHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_medicine_and_depot_versions_and_retirement_are_terminal(): void
    {
        $inventory = $this->actor(RoleCapabilityMatrix::ROLE_PHARMACY_INVENTORY_CONTROLLER);
        [$medicine, $depot] = $this->masters($inventory);
        $master = app(PharmacyMasterService::class);

        $oldMedicine = $master->reviseMedicine($medicine->public_id, $inventory, 1, $this->medicineData('Nama Baru'), 'hardening-med-revise')->record;
        $master->reviseMedicine($medicine->public_id, $inventory, 2, $this->medicineData('Nama Pensiun', PharmacyMedicine::RETIRED), 'hardening-med-retire');
        $this->assertDeniedReason('master_retired', fn () => $master->reviseMedicine($medicine->public_id, $inventory, 3, $this->medicineData('Tidak Boleh'), 'hardening-med-after-retire'));

        $master->reviseDepot($depot->public_id, $inventory, 1, $this->depotData('Depo Baru'), 'hardening-depot-revise');
        $master->reviseDepot($depot->public_id, $inventory, 2, $this->depotData('Depo Pensiun', PharmacyDepot::RETIRED), 'hardening-depot-retire');
        $this->assertDeniedReason('master_retired', fn () => $master->reviseDepot($depot->public_id, $inventory, 3, $this->depotData('Tidak Boleh'), 'hardening-depot-after-retire'));

        $replay = $master->reviseMedicine($medicine->public_id, $inventory, 1, $this->medicineData('Nama Baru'), 'hardening-med-revise');
        $this->assertTrue($replay->replayed);
        $this->assertSame('Nama Baru', $replay->record->generic_name);
        $this->assertSame(2, $oldMedicine->version);
    }

    public function test_opening_lot_expiry_quarantine_and_terminal_state_are_enforced(): void
    {
        $fixture = $this->fixture(2, expiryDate: now()->subDay()->toDateString());
        $stock = app(PharmacyStockService::class);
        $lot = $fixture['lot'];
        $verified = $this->verified($fixture, 1);

        $this->assertDeniedReason('insufficient_stock', fn () => app(PharmacyWorkflowService::class)->prepare(
            $verified->public_id,
            $fixture['technician'],
            $this->fingerprint($verified),
            'hardening-expired-prepare',
        ));

        $stock->changeLotState($lot->public_id, $fixture['inventory'], PharmacyStockLot::QUARANTINED, 'QUALITY HOLD', 'hardening-lot-quarantine');
        $this->assertDeniedReason('lot_quarantined', fn () => $stock->correctLot($lot->public_id, $fixture['inventory'], 1, 0, 'ADD AVAILABLE', 'hardening-lot-quarantine-add'));
        $stock->changeLotState($lot->public_id, $fixture['inventory'], PharmacyStockLot::ACTIVE, 'QUALITY RELEASE', 'hardening-lot-release');
        $stock->correctLot($lot->public_id, $fixture['inventory'], -2, 0, 'REMOVE BALANCE', 'hardening-lot-empty');
        $stock->changeLotState($lot->public_id, $fixture['inventory'], PharmacyStockLot::RETIRED, 'LOT RETIRED', 'hardening-lot-retire');
        $retirementMovement = $lot->movements()->latest('id')->firstOrFail();
        $this->assertSame(5, $lot->movements()->count());
        $this->assertSame(PharmacyStockMovement::QUARANTINE, $retirementMovement->movement_type);
        $this->assertSame(0, $retirementMovement->available_delta);
        $this->assertSame(0, $retirementMovement->quarantined_delta);
        $this->assertSame('LOT', $retirementMovement->source_type);
        $this->assertDeniedReason('lot_retired', fn () => $stock->changeLotState($lot->public_id, $fixture['inventory'], PharmacyStockLot::ACTIVE, 'REOPEN', 'hardening-lot-reopen'));
        $this->assertDeniedReason('lot_retired', fn () => $stock->correctLot($lot->public_id, $fixture['inventory'], 1, 0, 'ADD', 'hardening-lot-retired-add'));
    }

    public function test_verification_and_refusal_require_exact_roles_and_manual_checks(): void
    {
        $fixture = $this->fixture(2);
        $ordered = $this->ordered($fixture, 2);
        $workflow = app(PharmacyWorkflowService::class);
        $nurse = $this->actor(RoleCapabilityMatrix::ROLE_NURSE);

        try {
            $workflow->verify($ordered->public_id, $nurse, $this->fingerprint($ordered), 'REVIEWED_NO_CONFLICT', $this->checklist(), $this->decisions($ordered, 2), 'hardening-wrong-role');
            $this->fail('Expected exact-role denial.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('audit_events', ['outcome' => 'DENIED', 'reason' => 'role_not_permitted']);
        }

        $incomplete = $this->checklist();
        $incomplete['instruction_readable'] = false;
        $this->assertDeniedReason('verification_check_failed', fn () => $workflow->verify(
            $ordered->public_id,
            $fixture['pharmacist'],
            $this->fingerprint($ordered),
            'REVIEWED_NO_CONFLICT',
            $incomplete,
            $this->decisions($ordered, 2),
            'hardening-manual-check-denial',
        ));

        $refusal = $workflow->refuse(
            $ordered->public_id,
            $fixture['pharmacist'],
            $this->fingerprint($ordered),
            'REVIEWED_WITH_NOTE',
            $incomplete,
            'INSTRUCTION UNCLEAR',
            'Konfirmasi ulang diperlukan.',
            'hardening-refusal',
        )->record;
        $this->assertSame('REFUSED', $refusal->decision);
        $this->assertSame(PharmacyPrescription::REFUSED, $ordered->fresh()->status);
    }

    public function test_fefo_allocation_and_current_context_revalidation_fail_closed(): void
    {
        $fixture = $this->fixture(3, expiryDate: now()->addMonths(2)->toDateString());
        $stock = app(PharmacyStockService::class);
        $earlier = $stock->openLot($fixture['inventory'], $fixture['medicine']->public_id, $fixture['depot']->public_id, [
            'lot_code' => 'LOT-EARLIER', 'received_at' => now()->subDay(), 'expiry_date' => now()->addMonth()->toDateString(),
            'opening_quantity' => 2, 'source_reference' => 'HARDENING-EARLIER',
        ], 'hardening-earlier-lot')->record;
        $verified = $this->verified($fixture, 4);
        $preparation = app(PharmacyWorkflowService::class)->prepare($verified->public_id, $fixture['technician'], $this->fingerprint($verified), 'hardening-fefo-prepare')->record;
        $this->assertSame(
            [$earlier->id, $fixture['lot']->id],
            $preparation->allocations()->orderBy('fefo_sequence')->pluck('stock_lot_id')->all(),
        );

        $stock->correctLot($earlier->public_id, $fixture['inventory'], -1, 0, 'CONCURRENT COUNT', 'hardening-fefo-stale-stock');
        $this->assertDeniedReason('stale_preparation', fn () => app(PharmacyWorkflowService::class)->handover($preparation->public_id, $fixture['pharmacist'], app(PharmacyEvidenceFingerprint::class)->preparation($preparation->load('allocations')), null, 'hardening-stale-stock-handover'));
        $replacement = app(PharmacyWorkflowService::class)->prepare(
            $verified->public_id,
            $fixture['technician'],
            $this->fingerprint($verified->fresh()),
            'hardening-replace-stale-preparation',
            null,
            'Stok berubah setelah penyiapan.',
        )->record;
        $this->assertSame(PharmacyPreparation::SUPERSEDED, $preparation->fresh()->state);
        $this->assertSame($preparation->id, $replacement->replaces_preparation_id);

        app(PharmacyMasterService::class)->reviseDepot($fixture['depot']->public_id, $fixture['inventory'], 1, $this->depotData('Depo Berubah'), 'hardening-depot-context-change');
        $this->assertDeniedReason('pharmacy_master_changed', fn () => app(PharmacyWorkflowService::class)->handover($replacement->public_id, $fixture['pharmacist'], app(PharmacyEvidenceFingerprint::class)->preparation($replacement->load('allocations')), null, 'hardening-stale-handover'));
    }

    public function test_full_partial_and_unfilled_close_states_reconcile(): void
    {
        $fixture = $this->fixture(6);
        $verified = $this->verified($fixture, 5);
        $workflow = app(PharmacyWorkflowService::class);
        $item = $verified->items()->sole();
        $preparation = $workflow->prepare($verified->public_id, $fixture['technician'], $this->fingerprint($verified), 'hardening-partial-prepare', [$item->public_id => 2])->record;
        $handover = $workflow->handover($preparation->public_id, $fixture['pharmacist'], app(PharmacyEvidenceFingerprint::class)->preparation($preparation->load('allocations')), 'STOCK LIMIT', 'hardening-partial-handover')->record;
        $this->assertSame(PharmacyHandover::PARTIAL, $handover->state);
        $partial = $verified->fresh();
        $closed = $workflow->closeUnfilled($partial->public_id, $fixture['pharmacist'], $this->fingerprint($partial), 'PATIENT DECLINED', 'hardening-unfilled-close')->record;
        $this->assertSame(PharmacyPrescription::UNFILLED_CLOSED, $closed->status);
    }

    public function test_all_three_return_conditions_and_overage_are_enforced(): void
    {
        $fixture = $this->fixture(5);
        $verified = $this->verified($fixture, 3);
        $workflow = app(PharmacyWorkflowService::class);
        $preparation = $workflow->prepare($verified->public_id, $fixture['technician'], $this->fingerprint($verified), 'hardening-return-prepare')->record;
        $handover = $workflow->handover($preparation->public_id, $fixture['pharmacist'], app(PharmacyEvidenceFingerprint::class)->preparation($preparation->load('allocations')), null, 'hardening-return-handover')->record;
        $handover->load('items.returnItems');
        $handoverItem = $handover->items->sole();

        foreach ([PharmacyReturnItem::RETURN_TO_STOCK, PharmacyReturnItem::QUARANTINE, PharmacyReturnItem::DESTROYED_OR_NOT_RETURNABLE] as $index => $condition) {
            $workflow->recordReturn($handover->public_id, $verified->public_id, $fixture['pharmacist'], app(PharmacyEvidenceFingerprint::class)->handover($handover->fresh()->load('items.returnItems')), 'PATIENT RETURN', null, [['handover_item_public_id' => $handoverItem->public_id, 'condition' => $condition, 'quantity' => 1]], 'hardening-return-'.$index);
        }
        $this->assertSame(3, (int) $handoverItem->returnItems()->sum('quantity'));
        $nonReturnableMovement = PharmacyStockMovement::query()
            ->where('handover_item_id', $handoverItem->id)
            ->where('movement_type', PharmacyStockMovement::RETURN)
            ->where('reason_code', PharmacyReturnItem::DESTROYED_OR_NOT_RETURNABLE)
            ->sole();
        $this->assertSame(0, $nonReturnableMovement->available_delta);
        $this->assertSame(0, $nonReturnableMovement->quarantined_delta);
        $this->assertSame('RETURN_ITEM', $nonReturnableMovement->source_type);
        $this->assertDeniedReason('return_quantity_invalid', fn () => $workflow->recordReturn($handover->public_id, $verified->public_id, $fixture['pharmacist'], app(PharmacyEvidenceFingerprint::class)->handover($handover->fresh()->load('items.returnItems')), 'OVERAGE', null, [['handover_item_public_id' => $handoverItem->public_id, 'condition' => PharmacyReturnItem::RETURN_TO_STOCK, 'quantity' => 1]], 'hardening-return-overage'));
    }

    public function test_stock_and_financial_reconciliation_is_exact(): void
    {
        $fixture = $this->fixture(2);
        $verified = $this->verified($fixture, 2);
        $workflow = app(PharmacyWorkflowService::class);
        $preparation = $workflow->prepare($verified->public_id, $fixture['technician'], $this->fingerprint($verified), 'hardening-reconcile-prepare')->record;
        $workflow->handover($preparation->public_id, $fixture['pharmacist'], app(PharmacyEvidenceFingerprint::class)->preparation($preparation->load('allocations')), null, 'hardening-reconcile-handover');

        $projection = app(PharmacyProjection::class)->stockCard($fixture['inventory']);
        $this->assertTrue(collect($projection['lots'])->firstWhere('public_id', $fixture['lot']->public_id)['reconciled']);
        $detail = app(PharmacyProjection::class)->prescription($verified->fresh(), $fixture['pharmacist']);
        $this->assertSame(2000, $detail['control_totals']['gross_charge_source_rupiah']);
        $this->assertSame(2000, $detail['control_totals']['net_charge_source_rupiah']);
    }

    public function test_lifecycle_gate_blocks_active_evidence_and_ignores_partial_loaded_relations(): void
    {
        $fixture = $this->fixture(2);
        $verified = $this->verified($fixture, 2);
        $preparation = app(PharmacyWorkflowService::class)->prepare($verified->public_id, $fixture['technician'], $this->fingerprint($verified), 'hardening-gate-prepare')->record;
        $encounter = $fixture['encounter']->fresh();
        $encounter->load('pharmacyPrescriptions');

        $snapshot = app(PharmacyEncounterLifecycleGate::class)->inspectReadModel($encounter);
        $this->assertContains($verified->public_id, $snapshot['active_prescription_public_ids']);
        $this->assertContains($preparation->public_id, $snapshot['active_preparation_public_ids']);
    }

    public function test_changed_payload_idempotency_conflict_and_retained_replay(): void
    {
        $inventory = $this->actor(RoleCapabilityMatrix::ROLE_PHARMACY_INVENTORY_CONTROLLER);
        $master = app(PharmacyMasterService::class);
        $medicine = $master->createMedicine($inventory, $this->medicineData('Versi Awal', code: 'MED-REPLAY'), 'hardening-replay-create')->record;
        $master->reviseMedicine($medicine->public_id, $inventory, 1, $this->medicineData('Versi Kedua'), 'hardening-replay-revise');

        $replay = $master->createMedicine($inventory, $this->medicineData('Versi Awal', code: 'MED-REPLAY'), 'hardening-replay-create');
        $this->assertTrue($replay->replayed);
        $this->assertSame('Versi Awal', $replay->record->generic_name);
        $this->assertSame(1, $replay->record->version);
        $this->assertDeniedReason('idempotency_key_conflict', fn () => $master->createMedicine($inventory, $this->medicineData('Muatan Berubah', code: 'MED-OTHER'), 'hardening-replay-create'));
    }

    public function test_reset_deletes_replacement_preparation_self_reference_chain(): void
    {
        $fixture = $this->fixture(4);
        $verified = $this->verified($fixture, 2);
        $workflow = app(PharmacyWorkflowService::class);
        $first = $workflow->prepare($verified->public_id, $fixture['technician'], $this->fingerprint($verified), 'hardening-reset-first')->record;
        app(PharmacyStockService::class)->correctLot($fixture['lot']->public_id, $fixture['inventory'], -1, 0, 'COUNT_CHANGE', 'hardening-reset-stock-change');
        $replacement = $workflow->prepare(
            $verified->public_id,
            $fixture['technician'],
            $this->fingerprint($verified->fresh()),
            'hardening-reset-replacement',
            null,
            'Stok berubah setelah penyiapan.',
        )->record;

        $this->assertSame($first->id, $replacement->replaces_preparation_id);

        app(SyntheticResetService::class)->reset([
            'actor' => $fixture['inventory'],
            'reason' => 'pharmacy_replacement_chain_reset_test',
        ]);

        $this->assertDatabaseCount('pharmacy_preparations', 0);
    }

    /** @return array<string,mixed> */
    private function fixture(int $stockQuantity, ?string $expiryDate = null): array
    {
        $inventory = $this->actor(RoleCapabilityMatrix::ROLE_PHARMACY_INVENTORY_CONTROLLER);
        [$medicine, $depot] = $this->masters($inventory);
        $encounter = Encounter::factory()->create([
            'patient_id' => Patient::factory()->create(['is_synthetic' => true])->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'status' => Encounter::STATUS_IN_EXAMINATION,
            'clinic_name' => 'Poliklinik Hardening',
        ]);
        $lot = app(PharmacyStockService::class)->openLot($inventory, $medicine->public_id, $depot->public_id, [
            'lot_code' => 'LOT-HARDENING', 'received_at' => now()->subDay(),
            'expiry_date' => $expiryDate ?? now()->addMonths(6)->toDateString(),
            'opening_quantity' => $stockQuantity, 'source_reference' => 'HARDENING-OPENING',
        ], 'hardening-open-lot')->record;

        return compact('inventory', 'medicine', 'depot', 'encounter', 'lot') + [
            'physician' => $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN),
            'pharmacist' => $this->actor(RoleCapabilityMatrix::ROLE_PHARMACIST),
            'technician' => $this->actor(RoleCapabilityMatrix::ROLE_PHARMACY_TECHNICIAN),
        ];
    }

    /** @return array{PharmacyMedicine,PharmacyDepot} */
    private function masters(User $inventory): array
    {
        $master = app(PharmacyMasterService::class);
        $medicine = $master->createMedicine($inventory, $this->medicineData('Obat Hardening'), 'hardening-create-medicine')->record;
        $depot = $master->createDepot($inventory, $this->depotData('Depo Hardening', code: 'DEPO-HARD'), 'hardening-create-depot')->record;

        return [$medicine, $depot];
    }

    private function ordered(array $fixture, int $quantity): PharmacyPrescription
    {
        $workflow = app(PharmacyWorkflowService::class);
        $draft = $workflow->createDraft($fixture['encounter']->public_id, $fixture['physician'], $fixture['depot']->public_id, [$this->item($fixture['medicine'], $quantity)], null, 'hardening-create-draft')->record;

        return $workflow->order($draft->public_id, $fixture['physician'], 1, $this->fingerprint($draft), 'hardening-order')->record;
    }

    private function verified(array $fixture, int $quantity): PharmacyPrescription
    {
        $ordered = $this->ordered($fixture, $quantity)->load('items');
        app(PharmacyWorkflowService::class)->verify($ordered->public_id, $fixture['pharmacist'], $this->fingerprint($ordered), 'REVIEWED_NO_CONFLICT', $this->checklist(), $this->decisions($ordered, $quantity), 'hardening-verify');

        return $ordered->fresh()->load('items', 'verification');
    }

    private function fingerprint(PharmacyPrescription $prescription): string
    {
        return app(PharmacyEvidenceFingerprint::class)->prescription($prescription);
    }

    private function decisions(PharmacyPrescription $prescription, int $quantity): array
    {
        return [['item_public_id' => $prescription->items->sole()->public_id, 'verified_quantity' => $quantity, 'reason_code' => null]];
    }

    private function checklist(): array
    {
        return ['identity_confirmed' => true, 'context_confirmed' => true, 'medicine_readable' => true, 'instruction_readable' => true];
    }

    private function item(PharmacyMedicine $medicine, int $quantity): array
    {
        return ['medicine_public_id' => $medicine->public_id, 'dose_text' => '1 tablet', 'route' => 'ORAL', 'frequency_text' => '1 kali sehari', 'duration_text' => '3 hari', 'requested_quantity' => $quantity, 'instruction' => 'Sesudah makan'];
    }

    private function medicineData(string $name, string $state = PharmacyMedicine::ACTIVE, string $code = 'MED-HARD'): array
    {
        return ['medicine_code' => $code, 'generic_name' => $name, 'brand_name' => null, 'strength_text' => '500 mg', 'dosage_form' => 'TABLET', 'base_unit' => 'TABLET', 'route_choices' => ['ORAL'], 'acquisition_value' => 400, 'teaching_sale_value' => 1000, 'state' => $state];
    }

    private function depotData(string $name, string $state = PharmacyDepot::ACTIVE, string $code = 'DEPO-HARD'): array
    {
        return ['depot_code' => $code, 'display_name' => $name, 'eligible_care_settings' => [Encounter::CARE_SETTING_OUTPATIENT], 'state' => $state];
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $user->fresh();
    }

    private function assertDeniedReason(string $reason, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected a pharmacy denial.');
        } catch (PharmacyDenied $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }
}
