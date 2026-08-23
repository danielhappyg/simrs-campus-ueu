<?php

namespace Tests\Feature\Outpatient;

use App\Models\Encounter;
use App\Models\LabDiagnosticResult;
use App\Models\LabServiceRequest;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use Database\Seeders\OutpatientMastersSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class OutpatientLifecycleContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(OutpatientMastersSeeder::class);
    }

    public function test_active_lab_order_blocks_rm_closure_and_records_stable_reason(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $rmik = $this->userWithRole(RoleCapabilityMatrix::ROLE_RMIK);
        $encounter = $this->encounter($registrar, Encounter::STATUS_READY_FOR_RM);
        $order = $this->labOrder($encounter, $physician);

        $this->actingAs($rmik)
            ->get(route('rm.rawat-jalan.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('encounters.0.public_id', $encounter->public_id)
                ->where('encounters.0.active_lab_order_count', 1));

        $this->actingAs($rmik)
            ->post(route('rm.rawat-jalan.complete', $encounter))
            ->assertStatus(422);

        $this->assertSame(Encounter::STATUS_READY_FOR_RM, $encounter->fresh()->status);
        $this->assertSame(LabServiceRequest::STATUS_ACTIVE, $order->fresh()->status);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'rmik.review.complete',
            'resource_id' => $encounter->public_id,
            'outcome' => 'DENIED',
            'reason' => 'active_lab_orders',
        ]);
    }

    public function test_final_result_completes_order_and_allows_rm_closure(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);
        $rmik = $this->userWithRole(RoleCapabilityMatrix::ROLE_RMIK);
        $encounter = $this->encounter($registrar, Encounter::STATUS_READY_FOR_RM);
        $order = $this->labOrder($encounter, $physician);

        $this->actingAs($nurse)
            ->post(route('pemeriksaan.laboratorium.results.store', $order), [
                'result_text' => 'Hb 12.4 g/dL',
                'status' => LabDiagnosticResult::STATUS_FINAL,
            ])
            ->assertRedirect(route('pemeriksaan.laboratorium.index'));

        $this->actingAs($rmik)
            ->post(route('rm.rawat-jalan.complete', $encounter))
            ->assertRedirect(route('rm.rawat-jalan.index'));

        $this->assertSame(LabServiceRequest::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertSame(LabDiagnosticResult::STATUS_FINAL, $order->fresh()->result?->status);
        $this->assertSame(Encounter::STATUS_CLOSED, $encounter->fresh()->status);
    }

    public function test_preliminary_result_is_rejected_without_mutating_order(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);
        $encounter = $this->encounter($registrar, Encounter::STATUS_IN_EXAMINATION);
        $order = $this->labOrder($encounter, $physician);

        $this->actingAs($nurse)
            ->post(route('pemeriksaan.laboratorium.results.store', $order), [
                'result_text' => 'Belum diverifikasi',
                'status' => LabDiagnosticResult::STATUS_PRELIMINARY,
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(LabServiceRequest::STATUS_ACTIVE, $order->fresh()->status);
        $this->assertNull($order->fresh()->result);
    }

    public function test_closed_encounter_rejects_late_result_and_records_stable_reason(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);
        $encounter = $this->encounter($registrar, Encounter::STATUS_CLOSED);
        $order = $this->labOrder($encounter, $physician);

        $this->actingAs($nurse)
            ->post(route('pemeriksaan.laboratorium.results.store', $order), [
                'result_text' => 'Hasil terlambat',
                'status' => LabDiagnosticResult::STATUS_FINAL,
            ])
            ->assertStatus(422);

        $this->assertSame(LabServiceRequest::STATUS_ACTIVE, $order->fresh()->status);
        $this->assertNull($order->fresh()->result);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'clinical.lab.result.write',
            'resource_id' => $order->public_id,
            'outcome' => 'DENIED',
            'reason' => 'encounter_closed',
        ]);
    }

    public function test_final_result_is_immutable_and_duplicate_attempt_is_audited(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);
        $encounter = $this->encounter($registrar, Encounter::STATUS_IN_EXAMINATION);
        $order = $this->labOrder($encounter, $physician, LabServiceRequest::STATUS_COMPLETED);
        $result = LabDiagnosticResult::factory()->create([
            'lab_service_request_id' => $order->id,
            'entered_by_user_id' => $nurse->id,
            'status' => LabDiagnosticResult::STATUS_FINAL,
            'result_text' => 'Hasil final asli',
        ]);

        $this->actingAs($nurse)
            ->post(route('pemeriksaan.laboratorium.results.store', $order), [
                'result_text' => 'Hasil pengganti',
                'status' => LabDiagnosticResult::STATUS_FINAL,
            ])
            ->assertStatus(422);

        $this->assertSame('Hasil final asli', $result->fresh()->result_text);
        $this->assertDatabaseCount('lab_diagnostic_results', 1);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'clinical.lab.result.write',
            'resource_id' => $order->public_id,
            'outcome' => 'DENIED',
            'reason' => 'result_already_final',
        ]);
    }

    public function test_inactive_order_without_result_is_rejected_with_stable_reason(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);
        $encounter = $this->encounter($registrar, Encounter::STATUS_IN_EXAMINATION);
        $order = $this->labOrder($encounter, $physician, LabServiceRequest::STATUS_CANCELLED);

        $this->actingAs($nurse)
            ->post(route('pemeriksaan.laboratorium.results.store', $order), [
                'result_text' => 'Tidak boleh disimpan',
                'status' => LabDiagnosticResult::STATUS_FINAL,
            ])
            ->assertStatus(422);

        $this->assertNull($order->fresh()->result);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'clinical.lab.result.write',
            'resource_id' => $order->public_id,
            'outcome' => 'DENIED',
            'reason' => 'order_not_active',
        ]);
    }

    public function test_wrong_role_gets_forbidden_before_closed_encounter_state_is_disclosed(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $encounter = $this->encounter($registrar, Encounter::STATUS_CLOSED);
        $order = $this->labOrder($encounter, $physician);

        $this->actingAs($registrar)
            ->post(route('pemeriksaan.laboratorium.results.store', $order), [
                'result_text' => 'Percobaan tanpa hak',
                'status' => LabDiagnosticResult::STATUS_FINAL,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('audit_events', [
            'action' => 'clinical.lab.result.write',
            'resource_id' => $order->public_id,
            'outcome' => 'DENIED',
            'reason' => 'encounter_closed',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'authorization.denied',
            'resource_type' => 'http_route',
            'resource_id' => 'pemeriksaan.laboratorium.results.store',
            'outcome' => 'DENIED',
            'reason' => 'authorization_check_failed',
        ]);
    }

    public function test_successful_result_mutation_rolls_back_when_audit_write_fails(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);
        $encounter = $this->encounter($registrar, Encounter::STATUS_IN_EXAMINATION);
        $order = $this->labOrder($encounter, $physician);

        $recorder = Mockery::mock(AuditRecorder::class);
        $recorder->shouldReceive('record')->once()->andReturnNull();
        $this->app->instance(AuditRecorder::class, $recorder);

        $this->actingAs($nurse)
            ->post(route('pemeriksaan.laboratorium.results.store', $order), [
                'result_text' => 'Tidak boleh commit',
                'status' => LabDiagnosticResult::STATUS_FINAL,
            ])
            ->assertServerError();

        $this->assertSame(LabServiceRequest::STATUS_ACTIVE, $order->fresh()->status);
        $this->assertNull($order->fresh()->result);
    }

    private function encounter(User $registrar, string $status): Encounter
    {
        $patient = Patient::factory()->create([
            'created_by_user_id' => $registrar->id,
            'is_synthetic' => true,
        ]);

        return Encounter::factory()->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $registrar->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'status' => $status,
        ]);
    }

    private function labOrder(
        Encounter $encounter,
        User $physician,
        string $status = LabServiceRequest::STATUS_ACTIVE,
    ): LabServiceRequest {
        return LabServiceRequest::factory()->create([
            'encounter_id' => $encounter->id,
            'requested_by_user_id' => $physician->id,
            'test_code' => 'HB',
            'test_label' => 'Hemoglobin',
            'status' => $status,
        ]);
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
