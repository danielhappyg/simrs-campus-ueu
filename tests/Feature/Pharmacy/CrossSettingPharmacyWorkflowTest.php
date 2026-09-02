<?php

namespace Tests\Feature\Pharmacy;

use App\Models\Encounter;
use App\Models\Patient;
use App\Models\PharmacyFinancialSourceEvent;
use App\Models\PharmacyPrescription;
use App\Models\PharmacyReturnItem;
use App\Models\PharmacyStockMovement;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Pharmacy\PharmacyAuditUnavailable;
use App\Support\Pharmacy\PharmacyDenied;
use App\Support\Pharmacy\PharmacyEvidenceFingerprint;
use App\Support\Pharmacy\PharmacyMasterService;
use App\Support\Pharmacy\PharmacyProjection;
use App\Support\Pharmacy\PharmacyStockService;
use App\Support\Pharmacy\PharmacyWorkflowService;
use App\Support\Simulation\SyntheticResetService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class CrossSettingPharmacyWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_outpatient_flow_supports_partial_fefo_handover_remainder_return_and_reconciliation(): void
    {
        $inventory = $this->actor(RoleCapabilityMatrix::ROLE_PHARMACY_INVENTORY_CONTROLLER);
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $pharmacist = $this->actor(RoleCapabilityMatrix::ROLE_PHARMACIST);
        $technician = $this->actor(RoleCapabilityMatrix::ROLE_PHARMACY_TECHNICIAN);
        $registrar = $this->actor(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $encounter = Encounter::factory()->create([
            'patient_id' => Patient::factory()->create(['is_synthetic' => true])->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'status' => Encounter::STATUS_IN_EXAMINATION,
            'clinic_name' => 'Poliklinik Umum',
        ]);

        $masters = app(PharmacyMasterService::class);
        $medicine = $masters->createMedicine($inventory, [
            'medicine_code' => 'MED-UJI-500',
            'generic_name' => 'Obat Uji',
            'brand_name' => null,
            'strength_text' => '500 mg',
            'dosage_form' => 'TABLET',
            'base_unit' => 'TABLET',
            'route_choices' => ['ORAL'],
            'acquisition_value' => 400,
            'teaching_sale_value' => 1000,
        ], 'pharmacy-test-medicine-0001')->record;
        $depot = $masters->createDepot($inventory, [
            'depot_code' => 'DEPO-UJI-RJ',
            'display_name' => 'Depo Uji Rawat Jalan',
            'eligible_care_settings' => [Encounter::CARE_SETTING_OUTPATIENT],
        ], 'pharmacy-test-depot-0001')->record;
        $lot = app(PharmacyStockService::class)->openLot($inventory, $medicine->public_id, $depot->public_id, [
            'lot_code' => 'LOT-UJI-FEFO-01',
            'received_at' => now()->subDay(),
            'expiry_date' => now()->addMonth()->toDateString(),
            'opening_quantity' => 10,
            'source_reference' => 'SYNTHETIC-TEST-OPENING',
        ], 'pharmacy-test-lot-0001')->record;

        $workflow = app(PharmacyWorkflowService::class);
        $draftPayload = [[
            'medicine_public_id' => $medicine->public_id,
            'dose_text' => '1 tablet',
            'route' => 'ORAL',
            'frequency_text' => '3 kali sehari',
            'duration_text' => '5 hari',
            'requested_quantity' => 5,
            'instruction' => 'Sesudah makan',
        ]];
        $this->actingAs($registrar)
            ->post(route('pharmacy.encounters.prescriptions.store', $encounter->public_id), [
                'depot_public_id' => $depot->public_id,
                'clinical_note' => 'Upaya tidak sah.',
                'items' => $draftPayload,
                'idempotency_key' => 'pharmacy-http-denial-0001',
            ])
            ->assertForbidden();
        $this->assertDatabaseHas('audit_events', [
            'action' => 'pharmacy.workflow.mutate',
            'outcome' => 'DENIED',
            'reason' => 'role_not_permitted',
            'resource_id' => $encounter->public_id,
        ]);
        $this->assertDatabaseCount('pharmacy_prescriptions', 0);

        $draftResult = $workflow->createDraft($encounter->public_id, $physician, $depot->public_id, $draftPayload, 'Keluhan demam.', 'pharmacy-test-draft-0001');
        $draft = $draftResult->record;
        $draftFingerprint = app(PharmacyEvidenceFingerprint::class)->prescription($draft);
        $ordered = $workflow->order($draft->public_id, $physician, 1, $draftFingerprint, 'pharmacy-test-order-0001')->record;
        $ordered->load('items');
        $this->actingAs($pharmacist)
            ->from('/apotek/resep/'.$ordered->public_id)
            ->post(route('pharmacy.prescriptions.verify', $ordered->public_id), [
                'expected_fingerprint' => app(PharmacyEvidenceFingerprint::class)->prescription($ordered),
                'manual_allergy_review' => 'REVIEWED_NO_CONFLICT',
                'checklist' => [
                    'identity_confirmed' => true,
                    'context_confirmed' => true,
                    'medicine_readable' => true,
                    'instruction_readable' => true,
                ],
                'item_decisions' => [[
                    'item_public_id' => $ordered->items->sole()->public_id,
                    'verified_quantity' => 5,
                    'reason_code' => null,
                ]],
                'idempotency_key' => 'pharmacy-controller-verify-0001',
            ])
            ->assertRedirect('/apotek/resep/'.$ordered->public_id)
            ->assertSessionHasNoErrors();
        $prescription = $ordered->fresh();

        $this->actingAs($technician)
            ->from('/apotek/resep/'.$prescription->public_id)
            ->post(route('pharmacy.prescriptions.prepare', $prescription->public_id), [
                'expected_fingerprint' => app(PharmacyEvidenceFingerprint::class)->prescription($prescription),
                'item_quantities' => [$ordered->items->sole()->public_id => 0],
                'idempotency_key' => 'pharmacy-controller-prepare-zero-0001',
            ])
            ->assertRedirect('/apotek/resep/'.$prescription->public_id)
            ->assertSessionHasErrors('pharmacy');
        $this->assertSame(0, $prescription->preparations()->count(), 'Explicit zero must not default to the full remaining quantity.');

        $this->assertDeniedReason('verification_quantity_invalid', fn () => $workflow->prepare(
            $prescription->public_id,
            $technician,
            app(PharmacyEvidenceFingerprint::class)->prescription($prescription),
            'pharmacy-test-prepare-unknown',
            [str_repeat('0', 26) => 1],
        ));
        $this->assertDeniedReason('nothing_to_prepare', fn () => $workflow->prepare(
            $prescription->public_id,
            $technician,
            app(PharmacyEvidenceFingerprint::class)->prescription($prescription),
            'pharmacy-test-prepare-zero',
            [$ordered->items->sole()->public_id => 0],
        ));

        $firstPreparation = $workflow->prepare(
            $prescription->public_id,
            $technician,
            app(PharmacyEvidenceFingerprint::class)->prescription($prescription),
            'pharmacy-test-prepare-0001',
            [$ordered->items->sole()->public_id => 2],
        )->record;
        $firstHandover = $workflow->handover(
            $firstPreparation->public_id,
            $pharmacist,
            app(PharmacyEvidenceFingerprint::class)->preparation($firstPreparation->load('allocations')),
            'Pasien menerima dua tablet terlebih dahulu.',
            'pharmacy-test-handover-0001',
        )->record;
        $this->assertSame(PharmacyPrescription::PARTIALLY_HANDED_OVER, $firstHandover->prescription->fresh()->status);

        $partiallyHanded = $firstHandover->prescription->fresh();
        $secondPreparation = $workflow->prepare(
            $partiallyHanded->public_id,
            $technician,
            app(PharmacyEvidenceFingerprint::class)->prescription($partiallyHanded),
            'pharmacy-test-prepare-0002',
        )->record;
        $secondHandover = $workflow->handover(
            $secondPreparation->public_id,
            $pharmacist,
            app(PharmacyEvidenceFingerprint::class)->preparation($secondPreparation->load('allocations')),
            null,
            'pharmacy-test-handover-0002',
        )->record;
        $this->assertSame(PharmacyPrescription::HANDED_OVER, $secondHandover->prescription->fresh()->status);

        $unrelatedPrescription = $workflow->createDraft(
            $encounter->public_id,
            $physician,
            $depot->public_id,
            $draftPayload,
            'Resep pembanding.',
            'pharmacy-test-unrelated-draft-0001',
        )->record;
        $firstHandover->load('items.returnItems');
        $this->actingAs($pharmacist)
            ->from('/apotek/resep/'.$unrelatedPrescription->public_id)
            ->post(route('pharmacy.prescriptions.returns.store', $unrelatedPrescription->public_id), [
                'handover_public_id' => $firstHandover->public_id,
                'expected_handover_fingerprint' => app(PharmacyEvidenceFingerprint::class)->handover($firstHandover),
                'reason_code' => 'PATIENT_RETURN',
                'note' => 'Uji konteks resep.',
                'confirm_return' => true,
                'items' => [[
                    'handover_item_public_id' => $firstHandover->items->sole()->public_id,
                    'condition' => PharmacyReturnItem::RETURN_TO_STOCK,
                    'quantity' => 1,
                ]],
                'idempotency_key' => 'pharmacy-controller-return-mismatch-0001',
            ])
            ->assertRedirect('/apotek/resep/'.$unrelatedPrescription->public_id)
            ->assertSessionHasErrors('handover_public_id');
        $this->assertSame(0, PharmacyReturnItem::query()->count(), 'A handover from another prescription must not be returned.');

        $returned = $workflow->recordReturn(
            $firstHandover->public_id,
            $firstHandover->prescription->public_id,
            $pharmacist,
            app(PharmacyEvidenceFingerprint::class)->handover($firstHandover),
            'PATIENT_RETURN',
            'Satu tablet utuh dikembalikan.',
            [[
                'handover_item_public_id' => $firstHandover->items->sole()->public_id,
                'condition' => PharmacyReturnItem::RETURN_TO_STOCK,
                'quantity' => 1,
            ]],
            'pharmacy-test-return-0001',
        )->record;

        $this->assertSame(6, $lot->fresh()->available_quantity);
        $this->assertSame(4, PharmacyStockMovement::query()->count());
        $this->assertSame(5000, (int) PharmacyFinancialSourceEvent::query()->where('event_type', PharmacyFinancialSourceEvent::CHARGE)->sum('amount'));
        $this->assertSame(-1000, (int) PharmacyFinancialSourceEvent::query()->where('event_type', PharmacyFinancialSourceEvent::REVERSAL)->sum('amount'));
        $this->assertSame(1, (int) $returned->items()->sum('quantity'));

        $projection = app(PharmacyProjection::class)->prescription($secondHandover->prescription->fresh(), $pharmacist);
        $this->assertSame(5, $projection['control_totals']['handed_over_quantity']);
        $this->assertSame(4000, $projection['control_totals']['net_charge_source_rupiah']);
        $replay = app(PharmacyWorkflowService::class)->createDraft($encounter->public_id, $physician, $depot->public_id, $draftPayload, 'Keluhan demam.', 'pharmacy-test-draft-0001');
        $this->assertTrue($replay->replayed);
        $this->assertSame(PharmacyPrescription::DRAFT, $replay->record->status);
        $this->assertSame(1, $replay->record->version);
        $this->assertSame('Keluhan demam.', $replay->record->clinical_note);

        app(SyntheticResetService::class)->reset(['actor' => $physician, 'reason' => 'pharmacy_complete_flow_test']);
        $this->assertDatabaseCount('pharmacy_prescriptions', 0);
        $this->assertDatabaseCount('pharmacy_stock_movements', 0);
        $this->assertDatabaseCount('pharmacy_operation_receipts', 0);
        $this->assertDatabaseCount('pharmacy_medicines', 0);
        $this->assertDatabaseCount('patients', 0);
        $this->assertGreaterThanOrEqual(2, DB::table('audit_events')->whereIn('action', ['teaching.reset.started', 'teaching.reset.completed'])->count());
    }

    public function test_unauthorized_mutation_is_audited_before_validation_or_lookup_and_creates_no_domain_evidence(): void
    {
        $registrar = $this->actor(RoleCapabilityMatrix::ROLE_REGISTRAR);

        try {
            app(PharmacyMasterService::class)->createMedicine($registrar, [], 'bad');
            $this->fail('Expected authorization denial.');
        } catch (AuthorizationException) {
            // Exact-role policy must run before invalid key/payload validation.
        }

        $this->assertSame(1, DB::table('audit_events')->where([
            'action' => 'pharmacy.workflow.mutate',
            'outcome' => 'DENIED',
            'reason' => 'role_not_permitted',
        ])->count());
        $this->assertDatabaseCount('pharmacy_medicines', 0);
        $this->assertDatabaseCount('pharmacy_operation_receipts', 0);
    }

    public function test_global_worklists_are_pharmacy_scoped_and_encounter_projection_fails_closed(): void
    {
        $nurse = $this->actor(RoleCapabilityMatrix::ROLE_NURSE);
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $pharmacist = $this->actor(RoleCapabilityMatrix::ROLE_PHARMACIST);
        $technician = $this->actor(RoleCapabilityMatrix::ROLE_PHARMACY_TECHNICIAN);
        $registrar = $this->actor(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $encounter = Encounter::factory()->create([
            'patient_id' => Patient::factory()->create(['is_synthetic' => true])->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'status' => Encounter::STATUS_IN_EXAMINATION,
        ]);

        $this->actingAs($nurse)->get(route('pharmacy.worklist.index'))->assertForbidden();
        $this->actingAs($physician)->get(route('pharmacy.history.index'))->assertForbidden();
        $this->actingAs($physician)->get(route('pharmacy.prescriptions.show', '01K00000000000000000000000'))->assertForbidden();
        $this->actingAs($pharmacist)->get(route('pharmacy.worklist.index'))->assertOk();
        $this->actingAs($technician)->get(route('pharmacy.history.index'))->assertOk();

        $projection = app(PharmacyProjection::class)->encounter($encounter, $registrar);
        $this->assertFalse($projection['permissions']['can_view']);
        $this->assertSame([], $projection['prescriptions']);
        $this->assertSame([], $projection['medicine_options']);
        $this->assertSame([], $projection['depot_options']);
        $this->assertNull($projection['commands']['create_draft_url']);
    }

    public function test_unauthorized_mutation_fails_closed_when_denial_audit_is_unavailable(): void
    {
        $registrar = $this->actor(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $audit = Mockery::mock(AuditRecorder::class);
        $audit->shouldReceive('record')->once()->andReturnNull();
        $this->app->instance(AuditRecorder::class, $audit);

        $this->expectException(PharmacyAuditUnavailable::class);
        try {
            app(PharmacyMasterService::class)->createMedicine($registrar, [], 'bad');
        } finally {
            $this->assertDatabaseCount('pharmacy_medicines', 0);
            $this->assertDatabaseCount('pharmacy_operation_receipts', 0);
        }
    }

    public function test_authorized_validation_denial_is_audited_without_domain_or_receipt_write(): void
    {
        $inventory = $this->actor(RoleCapabilityMatrix::ROLE_PHARMACY_INVENTORY_CONTROLLER);

        $this->assertDeniedReason('validation_failed', fn () => app(PharmacyMasterService::class)
            ->createMedicine($inventory, [], 'pharmacy-invalid-master-0001'));

        $this->assertDatabaseHas('audit_events', [
            'action' => 'pharmacy.workflow.mutate',
            'outcome' => 'DENIED',
            'reason' => 'validation_failed',
        ]);
        $this->assertDatabaseCount('pharmacy_medicines', 0);
        $this->assertDatabaseCount('pharmacy_operation_receipts', 0);
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
