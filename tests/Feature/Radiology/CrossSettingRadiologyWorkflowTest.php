<?php

namespace Tests\Feature\Radiology;

use App\Models\Encounter;
use App\Models\Patient;
use App\Models\RadiologyOrder;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Radiology\RadiologyClosureGate;
use App\Support\Radiology\RadiologyMasterService;
use App\Support\Radiology\RadiologyWorkflowService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FinalizesEmergencyInitialTriage;
use Tests\TestCase;

class CrossSettingRadiologyWorkflowTest extends TestCase
{
    use FinalizesEmergencyInitialTriage;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seedEmergencyTriageVocabulary();
    }

    public function test_cross_setting_order_verified_amendment_and_fresh_acknowledgement_chain(): void
    {
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $tech = $this->actor(RoleCapabilityMatrix::ROLE_RADIOLOGY_TECHNOLOGIST);
        $radiologist = $this->actor(RoleCapabilityMatrix::ROLE_RADIOLOGIST);
        $master = app(RadiologyMasterService::class)->create($admin, 'RAD-THORAX', 'Thoraks AP/PA', 'Ikuti instruksi petugas.', 'master-create-0001')->record;
        $encounter = Encounter::factory()->create(['patient_id' => Patient::factory()->create(['is_synthetic' => true])->id, 'care_setting' => Encounter::CARE_SETTING_EMERGENCY, 'status' => Encounter::STATUS_REGISTERED]);
        $encounter = $this->finalizeEmergencyInitialTriage($encounter, keyPrefix: 'radiology-fixture-triage');
        $service = app(RadiologyWorkflowService::class);
        $order = $service->createOrder($encounter->public_id, $master->public_id, $physician, 'Evaluasi kelainan pada toraks.', 'order-create-0001')->record;
        $this->assertSame(RadiologyOrder::ORDERED, $order->status);
        $service->perform($order->public_id, $tech, 1, 'order-perform-0001');
        $order->refresh();
        $this->assertSame(RadiologyOrder::PERFORMED, $order->status);
        $draft = $service->saveDraft($order->public_id, $radiologist, 0, 'Tidak tampak infiltrat.', 'Cor dalam batas normal.', null, 'report-draft-0001')->record;
        $verified = $service->verify($order->public_id, $radiologist, $draft->version, 'report-verify-0001')->record;
        $order->refresh();
        $this->assertSame(RadiologyOrder::REPORTED_VERIFIED, $order->status);
        $gate = app(RadiologyClosureGate::class);
        $this->assertSame([$order->public_id], $gate->inspect($encounter)['stale_acknowledgement_order_public_ids']);
        $service->acknowledge($order->public_id, $physician, $order->version, $verified->version, 'report-ack-0001');
        $this->assertSame([], $gate->inspect($encounter)['stale_acknowledgement_order_public_ids']);
        $amended = $service->amendVerified($order->public_id, $radiologist, $verified->version, 'CLINICAL_CLARIFICATION', 'Keterangan tambahan: proyeksi terbatas.', 'report-amend-0001')->record;
        $this->assertSame([$order->public_id], $gate->inspect($encounter)['stale_acknowledgement_order_public_ids']);
        $service->acknowledge($order->public_id, $physician, $order->fresh()->version, $amended->version, 'report-ack-0002');
        $this->assertSame([], $gate->inspect($encounter)['stale_acknowledgement_order_public_ids']);
        $this->assertDatabaseCount('radiology_report_versions', 3);
        $this->assertDatabaseCount('radiology_report_acknowledgements', 2);
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $user->fresh();
    }
}
