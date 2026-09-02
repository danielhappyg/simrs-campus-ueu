<?php

namespace Tests\Feature\Outpatient;

use App\Models\Encounter;
use App\Models\LabDiagnosticResult;
use App\Models\LabServiceRequest;
use App\Models\OutpatientClinicalDocument;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Clinical\OutpatientLabLifecycle;
use App\Support\Clinical\OutpatientRmCompletenessService;
use Database\Seeders\OutpatientMastersSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class OutpatientLifecycleContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(OutpatientMastersSeeder::class);
    }

    public function test_active_legacy_lab_order_still_blocks_rm_closure(): void
    {
        $registrar = $this->actor(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $rmik = $this->actor(RoleCapabilityMatrix::ROLE_RMIK);
        $encounter = $this->encounter($registrar, Encounter::STATUS_READY_FOR_RM);
        $order = $this->order($encounter, $physician);
        $snapshot = app(OutpatientRmCompletenessService::class)->snapshot($encounter);

        $this->actingAs($rmik)->post(route('rm.rawat-jalan.reviews.store', $encounter), [
            'expected_version' => 0,
            'source_fingerprint' => $snapshot['source_fingerprint'],
        ])->assertRedirect();
        $this->actingAs($rmik)->post(route('rm.rawat-jalan.signoff', $encounter), [
            'expected_version' => 1,
            'source_fingerprint' => $snapshot['source_fingerprint'],
        ])->assertStatus(422);

        $this->assertSame(LabServiceRequest::STATUS_ACTIVE, $order->fresh()->status);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'rmik.completeness.signoff',
            'resource_id' => $encounter->public_id,
            'outcome' => 'DENIED',
            'reason' => 'active_lab_orders',
        ]);
    }

    public function test_legacy_service_can_finalize_retained_order_and_release_rm_closure(): void
    {
        $registrar = $this->actor(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->actor(RoleCapabilityMatrix::ROLE_NURSE);
        $rmik = $this->actor(RoleCapabilityMatrix::ROLE_RMIK);
        $encounter = $this->encounter($registrar, Encounter::STATUS_READY_FOR_RM);
        $order = $this->order($encounter, $physician);
        $this->documents($encounter, $nurse, $physician);

        app(OutpatientLabLifecycle::class)->writeFinalLabResult($order, $nurse, 'Hb 12.4 g/dL');
        $snapshot = app(OutpatientRmCompletenessService::class)->snapshot($encounter);
        $this->actingAs($rmik)->post(route('rm.rawat-jalan.reviews.store', $encounter), [
            'expected_version' => 0,
            'source_fingerprint' => $snapshot['source_fingerprint'],
        ])->assertRedirect();
        $this->actingAs($rmik)->post(route('rm.rawat-jalan.signoff', $encounter), [
            'expected_version' => 1,
            'source_fingerprint' => $snapshot['source_fingerprint'],
        ])->assertRedirect();

        $this->assertSame(LabServiceRequest::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertSame(LabDiagnosticResult::STATUS_FINAL, $order->fresh()->result?->status);
        $this->assertSame(Encounter::STATUS_CLOSED, $encounter->fresh()->status);
    }

    public function test_legacy_service_preserves_stable_denials_and_immutable_final_result(): void
    {
        $registrar = $this->actor(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->actor(RoleCapabilityMatrix::ROLE_NURSE);
        $closedOrder = $this->order($this->encounter($registrar, Encounter::STATUS_CLOSED), $physician);
        $this->assertLifecycleDenial($closedOrder, $nurse, 'encounter_closed');

        $completedOrder = $this->order($this->encounter($registrar, Encounter::STATUS_IN_EXAMINATION), $physician, LabServiceRequest::STATUS_COMPLETED);
        $result = LabDiagnosticResult::factory()->create([
            'lab_service_request_id' => $completedOrder->id,
            'entered_by_user_id' => $nurse->id,
            'status' => LabDiagnosticResult::STATUS_FINAL,
            'result_text' => 'Hasil final asli',
        ]);
        $this->assertLifecycleDenial($completedOrder, $nurse, 'result_already_final');
        $this->assertSame('Hasil final asli', $result->fresh()->result_text);

        $cancelledOrder = $this->order($this->encounter($registrar, Encounter::STATUS_IN_EXAMINATION), $physician, LabServiceRequest::STATUS_CANCELLED);
        $this->assertLifecycleDenial($cancelledOrder, $nurse, 'order_not_active');
        $this->assertDatabaseCount('lab_diagnostic_results', 1);
    }

    public function test_legacy_service_rolls_back_when_required_audit_cannot_be_written(): void
    {
        $registrar = $this->actor(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->actor(RoleCapabilityMatrix::ROLE_NURSE);
        $order = $this->order($this->encounter($registrar, Encounter::STATUS_IN_EXAMINATION), $physician);
        $recorder = Mockery::mock(AuditRecorder::class);
        $recorder->shouldReceive('record')->once()->andReturnNull();
        $this->app->instance(AuditRecorder::class, $recorder);

        try {
            app(OutpatientLabLifecycle::class)->writeFinalLabResult($order, $nurse, 'Tidak boleh commit');
            $this->fail('Audit failure must abort the compatibility mutation.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $this->assertSame(LabServiceRequest::STATUS_ACTIVE, $order->fresh()->status);
        $this->assertNull($order->fresh()->result);
    }

    private function assertLifecycleDenial(LabServiceRequest $order, User $actor, string $reason): void
    {
        try {
            app(OutpatientLabLifecycle::class)->writeFinalLabResult($order, $actor, 'Tidak boleh disimpan');
            $this->fail("Expected {$reason} denial.");
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertDatabaseHas('audit_events', [
            'action' => 'clinical.lab.result.write',
            'resource_id' => $order->public_id,
            'outcome' => 'DENIED',
            'reason' => $reason,
        ]);
    }

    private function encounter(User $registrar, string $status): Encounter
    {
        return Encounter::factory()->create([
            'patient_id' => Patient::factory()->create(['created_by_user_id' => $registrar->id, 'is_synthetic' => true])->id,
            'registered_by_user_id' => $registrar->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'status' => $status,
        ]);
    }

    private function order(Encounter $encounter, User $physician, string $status = LabServiceRequest::STATUS_ACTIVE): LabServiceRequest
    {
        return LabServiceRequest::factory()->create([
            'encounter_id' => $encounter->id,
            'requested_by_user_id' => $physician->id,
            'test_code' => 'HB',
            'test_label' => 'Hemoglobin',
            'status' => $status,
        ]);
    }

    private function documents(Encounter $encounter, User $nurse, User $physician): void
    {
        OutpatientClinicalDocument::query()->create([
            'encounter_id' => $encounter->id, 'author_user_id' => $nurse->id, 'finalized_by_user_id' => $nurse->id,
            'document_type' => OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT, 'document_state' => OutpatientClinicalDocument::STATE_FINAL,
            'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION, 'version' => 1,
            'fields' => ['nursing_assessment' => 'Sintetis'], 'finalized_at' => now(),
        ]);
        OutpatientClinicalDocument::query()->create([
            'encounter_id' => $encounter->id, 'author_user_id' => $physician->id, 'finalized_by_user_id' => $physician->id,
            'document_type' => OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT, 'document_state' => OutpatientClinicalDocument::STATE_FINAL,
            'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION, 'version' => 1,
            'fields' => ['anamnesis' => 'A', 'objective_examination' => 'B', 'clinical_assessment' => 'C', 'care_plan' => 'D'],
            'finalized_at' => now(),
        ]);
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $user->fresh();
    }
}
