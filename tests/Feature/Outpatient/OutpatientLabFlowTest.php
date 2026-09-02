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

final class OutpatientLabFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(OutpatientMastersSeeder::class);
    }

    public function test_legacy_http_writes_are_retired_without_mutating_evidence(): void
    {
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);
        $encounter = $this->encounter($physician, Encounter::STATUS_IN_EXAMINATION);

        $this->actingAs($physician)->post(route('pemeriksaan.rawat-jalan.lab-orders.store', $encounter), [
            'test_code' => 'HB',
            'clinical_question' => 'Evaluasi anemia',
        ])->assertGone();
        $this->assertDatabaseCount('lab_service_requests', 0);

        $order = $this->legacyOrder($encounter, $physician);
        $this->actingAs($nurse)->post(route('pemeriksaan.laboratorium.results.store', $order), [
            'result_text' => 'Tidak boleh tersimpan.',
            'status' => LabDiagnosticResult::STATUS_FINAL,
        ])->assertGone();
        $this->assertDatabaseCount('lab_diagnostic_results', 0);
        $this->assertSame(LabServiceRequest::STATUS_ACTIVE, $order->fresh()->status);
    }

    public function test_active_legacy_order_remains_visible_as_read_only_compatibility_evidence(): void
    {
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);
        $order = $this->legacyOrder($this->encounter($physician, Encounter::STATUS_IN_EXAMINATION), $physician);

        $this->actingAs($nurse)->get(route('pemeriksaan.laboratorium.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pemeriksaan/laboratorium/index')
                ->has('orders', 1)
                ->where('orders.0.public_id', $order->public_id)
                ->where('orders.0.source', 'LEGACY_READ_ONLY')
                ->where('orders.0.examination.code', 'HB')
                ->where('orders.0.actions.cancel_url', null)
                ->where('orders.0.actions.collect_url', null)
                ->where('orders.0.actions.save_result_url', null));
    }

    public function test_legacy_final_result_is_physician_readable_and_redacted_from_other_roles(): void
    {
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);
        $activeEncounter = $this->encounter($physician, Encounter::STATUS_IN_EXAMINATION);
        $completed = $this->legacyOrder($activeEncounter, $physician, LabServiceRequest::STATUS_COMPLETED);
        LabDiagnosticResult::factory()->create([
            'lab_service_request_id' => $completed->id,
            'entered_by_user_id' => $nurse->id,
            'status' => LabDiagnosticResult::STATUS_FINAL,
            'result_text' => 'Hb 12.4 g/dL',
        ]);
        $cancelled = $this->legacyOrder($this->encounter($physician, Encounter::STATUS_CANCELLED), $physician);

        $this->actingAs($physician)->get(route('pemeriksaan.rawat-jalan.show', $activeEncounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('encounter.lab_orders')
                ->where('laboratory.orders.0.source', 'LEGACY_READ_ONLY')
                ->where('laboratory.orders.0.state', 'REPORTED_VERIFIED')
                ->where('laboratory.orders.0.result.components.0.value', 'Hb 12.4 g/dL')
                ->where('laboratory.orders.0.actions.save_result_url', null));
        $this->actingAs($nurse)->get(route('pemeriksaan.rawat-jalan.show', $activeEncounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('encounter.lab_orders')
                ->where('laboratory.orders.0.source', 'LEGACY_READ_ONLY')
                ->where('laboratory.orders.0.result', null));
        foreach ([RoleCapabilityMatrix::ROLE_REGISTRAR, RoleCapabilityMatrix::ROLE_ADMIN, RoleCapabilityMatrix::ROLE_RMIK] as $role) {
            $this->actingAs($this->userWithRole($role))->get(route('pemeriksaan.rawat-jalan.show', $activeEncounter))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->missing('encounter.lab_orders')
                    ->has('laboratory.orders', 0));
        }
        $this->actingAs($nurse)->get(route('pemeriksaan.laboratorium.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders', fn ($orders): bool => collect($orders)->doesntContain('public_id', $cancelled->public_id)));
    }

    private function encounter(User $actor, string $status): Encounter
    {
        return Encounter::factory()->create([
            'patient_id' => Patient::factory()->create(['created_by_user_id' => $actor->id, 'is_synthetic' => true])->id,
            'registered_by_user_id' => $actor->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'status' => $status,
        ]);
    }

    private function legacyOrder(Encounter $encounter, User $physician, string $status = LabServiceRequest::STATUS_ACTIVE): LabServiceRequest
    {
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
        $user->roles()->sync([Role::query()->where('slug', $roleSlug)->sole()->id]);

        return $user->fresh();
    }
}
