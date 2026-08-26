<?php

namespace Tests\Feature\Outpatient;

use App\Models\Encounter;
use App\Models\LabDiagnosticResult;
use App\Models\LabServiceRequest;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use Database\Seeders\OutpatientMastersSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OutpatientLabFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(OutpatientMastersSeeder::class);
    }

    public function test_physician_can_create_lab_order_and_nurse_can_enter_result(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);

        $patient = Patient::factory()->create([
            'created_by_user_id' => $registrar->id,
        ]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $registrar->id,
            'status' => Encounter::STATUS_IN_EXAMINATION,
        ]);

        $this->actingAs($physician)
            ->post(route('pemeriksaan.rawat-jalan.lab-orders.store', $encounter), [
                'test_code' => 'HB',
                'clinical_question' => 'Evaluasi anemia',
            ])
            ->assertRedirect(route('pemeriksaan.rawat-jalan.show', $encounter));

        $order = LabServiceRequest::query()->firstOrFail();
        $this->assertSame(LabServiceRequest::STATUS_ACTIVE, $order->status);
        $this->assertSame('HB', $order->test_code);
        $this->assertSame('Hemoglobin', $order->test_label);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'clinical.lab.order.create',
            'resource_type' => 'lab_service_request',
            'resource_id' => $order->public_id,
            'outcome' => 'SUCCESS',
        ]);

        $this->actingAs($nurse)
            ->get(route('pemeriksaan.laboratorium.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pemeriksaan/laboratorium/index')
                ->has('orders', 1)
                ->where('orders.0.public_id', $order->public_id));

        $this->actingAs($nurse)
            ->post(route('pemeriksaan.laboratorium.results.store', $order), [
                'result_text' => 'Hb 12.4 g/dL',
                'status' => LabDiagnosticResult::STATUS_FINAL,
            ])
            ->assertRedirect(route('pemeriksaan.laboratorium.index'));

        $order->refresh();
        $this->assertSame(LabServiceRequest::STATUS_COMPLETED, $order->status);
        $this->assertNotNull($order->result);
        $this->assertSame('Hb 12.4 g/dL', $order->result->result_text);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'clinical.lab.result.write',
            'resource_type' => 'lab_service_request',
            'resource_id' => $order->public_id,
            'outcome' => 'SUCCESS',
        ]);

        $this->actingAs($physician)
            ->get(route('pemeriksaan.rawat-jalan.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pemeriksaan/rawat-jalan/show')
                ->has('encounter.lab_orders', 1)
                ->where('encounter.lab_orders.0.result.result_text', 'Hb 12.4 g/dL'));
    }

    public function test_nurse_cannot_create_lab_order(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);

        $patient = Patient::factory()->create([
            'created_by_user_id' => $registrar->id,
        ]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $registrar->id,
            'status' => Encounter::STATUS_IN_EXAMINATION,
        ]);

        $this->actingAs($nurse)
            ->post(route('pemeriksaan.rawat-jalan.lab-orders.store', $encounter), [
                'test_code' => 'GDS',
            ])
            ->assertForbidden();
    }

    public function test_closed_encounter_rejects_lab_order(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);

        $patient = Patient::factory()->create([
            'created_by_user_id' => $registrar->id,
        ]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $registrar->id,
            'status' => Encounter::STATUS_CLOSED,
        ]);

        $this->actingAs($physician)
            ->post(route('pemeriksaan.rawat-jalan.lab-orders.store', $encounter), [
                'test_code' => 'UR',
            ])
            ->assertStatus(422);
    }

    public function test_result_entry_returns_to_the_filtered_worklist_page(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);
        $patient = Patient::factory()->create([
            'created_by_user_id' => $registrar->id,
            'is_synthetic' => true,
        ]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $registrar->id,
            'status' => Encounter::STATUS_IN_EXAMINATION,
        ]);
        $orders = LabServiceRequest::factory()->count(101)->create([
            'encounter_id' => $encounter->id,
            'requested_by_user_id' => $physician->id,
            'status' => LabServiceRequest::STATUS_ACTIVE,
            'test_code' => 'HB',
            'test_label' => 'Hemoglobin',
        ]);
        $order = $orders->last();
        $this->assertInstanceOf(LabServiceRequest::class, $order);

        $this->actingAs($nurse)
            ->followingRedirects()
            ->post(route('pemeriksaan.laboratorium.results.store', $order), [
                'result_text' => 'Hasil sintetis final.',
                'status' => LabDiagnosticResult::STATUS_FINAL,
                'q' => 'Hemoglobin',
                'page' => 2,
            ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pemeriksaan/laboratorium/index')
                ->has('orders', 100)
                ->where('filters.q', 'Hemoglobin')
                ->where('flash.success', 'Hasil lab disimpan.')
                ->where('pagination.current_page', 1)
                ->where('pagination.last_page', 1)
                ->where('pagination.total', 100));
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
