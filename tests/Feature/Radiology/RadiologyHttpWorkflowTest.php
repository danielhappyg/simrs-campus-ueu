<?php

namespace Tests\Feature\Radiology;

use App\Models\Encounter;
use App\Models\Patient;
use App\Models\RadiologyExaminationMaster;
use App\Models\RadiologyOrder;
use App\Models\RadiologyReportAcknowledgement;
use App\Models\RadiologyReportVersion;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Radiology\RadiologyMasterService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FinalizesEmergencyInitialTriage;
use Tests\TestCase;

final class RadiologyHttpWorkflowTest extends TestCase
{
    use FinalizesEmergencyInitialTriage;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seedEmergencyTriageVocabulary();
    }

    public function test_http_workflow_runs_from_order_through_current_acknowledgement(): void
    {
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $technologist = $this->actor(RoleCapabilityMatrix::ROLE_RADIOLOGY_TECHNOLOGIST);
        $radiologist = $this->actor(RoleCapabilityMatrix::ROLE_RADIOLOGIST);
        $master = app(RadiologyMasterService::class)->create(
            $admin,
            'RAD-HTTP-01',
            'Foto Thoraks',
            'Lepaskan benda logam.',
            'radiology-http-master-0001',
        )->record;
        $encounter = $this->encounter(Encounter::CARE_SETTING_EMERGENCY);

        $this->actingAs($physician)->post(route('radiology.emergency.orders.store', $encounter), [
            'definition_version' => 'CROSS_SETTING_RADIOLOGY_ORDER_REPORT_V1',
            'examination_public_id' => $master->public_id,
            'clinical_question' => 'Evaluasi sesak dan batuk menetap.',
            'idempotency_key' => 'radiology-http-order-0001',
        ])->assertRedirect();
        $order = RadiologyOrder::query()->sole();

        $this->actingAs($technologist)->post(route('radiology.orders.perform', $order), [
            'expected_version' => 1,
            'idempotency_key' => 'radiology-http-perform-0001',
        ])->assertRedirect();
        $this->assertSame(RadiologyOrder::PERFORMED, $order->fresh()->status);

        $this->actingAs($radiologist)->post(route('radiology.orders.report.save', $order), [
            'expected_version' => 0,
            'fields' => [
                'findings' => 'Tidak tampak infiltrat fokal.',
                'impression' => 'Tidak tampak kelainan paru akut.',
                'recommendation' => null,
            ],
            'idempotency_key' => 'radiology-http-draft-0001',
        ])->assertRedirect();
        $draft = RadiologyReportVersion::query()->sole();

        $this->actingAs($radiologist)->post(route('radiology.orders.report.verify', $order), [
            'expected_version' => $draft->version,
            'idempotency_key' => 'radiology-http-verify-0001',
        ])->assertRedirect();
        $verified = RadiologyReportVersion::query()->where('state', RadiologyReportVersion::VERIFIED)->sole();
        $order->refresh();

        $this->actingAs($physician)->post(route('radiology.orders.report.acknowledge', $order), [
            'expected_order_version' => $order->version,
            'expected_report_version' => $verified->version,
            'idempotency_key' => 'radiology-http-ack-0001',
        ])->assertRedirect();

        $this->assertDatabaseCount('radiology_report_acknowledgements', 1);
        $this->assertNotNull(RadiologyReportAcknowledgement::query()->sole()->report_fingerprint);
    }

    public function test_encounter_page_and_role_scoped_worklists_receive_radiology_projection(): void
    {
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $technologist = $this->actor(RoleCapabilityMatrix::ROLE_RADIOLOGY_TECHNOLOGIST);
        $radiologist = $this->actor(RoleCapabilityMatrix::ROLE_RADIOLOGIST);
        $master = app(RadiologyMasterService::class)->create(
            $admin,
            'RAD-HTTP-02',
            'USG Abdomen',
            null,
            'radiology-http-master-0002',
        )->record;
        $encounter = $this->encounter(Encounter::CARE_SETTING_EMERGENCY);

        $this->actingAs($physician)->post(route('radiology.emergency.orders.store', $encounter), [
            'definition_version' => 'CROSS_SETTING_RADIOLOGY_ORDER_REPORT_V1',
            'examination_public_id' => $master->public_id,
            'clinical_question' => 'Evaluasi nyeri abdomen kanan atas.',
            'idempotency_key' => 'radiology-http-order-0002',
        ])->assertRedirect();
        $order = RadiologyOrder::query()->sole();

        $this->actingAs($physician)->get(route('pemeriksaan.igd.show', $encounter))
            ->assertInertia(fn (Assert $page) => $page
                ->component('pemeriksaan/igd/show')
                ->where('radiology.definition_version', 'CROSS_SETTING_RADIOLOGY_ORDER_REPORT_V1')
                ->where('radiology.commands.create_order_url', route('radiology.emergency.orders.store', $encounter))
                ->has('radiology.orders', 1));

        $this->actingAs($technologist)->get(route('radiology.worklist.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('pemeriksaan/radiologi/index')
                ->has('orders', 1)
                ->where('permissions.can_perform', true)
                ->where('permissions.can_report', false));

        $this->actingAs($radiologist)->get(route('radiology.worklist.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 0)
                ->where('permissions.can_perform', false)
                ->where('permissions.can_report', true));

        $this->actingAs($technologist)->post(route('radiology.orders.perform', $order), [
            'expected_version' => 1,
            'idempotency_key' => 'radiology-http-perform-0002',
        ])->assertRedirect();

        $this->actingAs($radiologist)->get(route('radiology.worklist.index'))
            ->assertInertia(fn (Assert $page) => $page->has('orders', 1));
    }

    public function test_authorization_precedes_unknown_resource_lookup_and_mixed_roles_are_denied(): void
    {
        $registrar = $this->actor(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $unknown = (string) Str::ulid();

        $this->actingAs($registrar)->post(route('radiology.orders.perform', ['order' => $unknown]), [
            'expected_version' => 1,
            'idempotency_key' => 'radiology-http-denial-0001',
        ])->assertForbidden();

        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $physician->roles()->attach(Role::query()->where('slug', RoleCapabilityMatrix::ROLE_NURSE)->sole()->id);
        $this->actingAs($physician->fresh())->post(route('radiology.outpatient.orders.store', ['encounter' => $unknown]), [
            'definition_version' => 'CROSS_SETTING_RADIOLOGY_ORDER_REPORT_V1',
            'examination_public_id' => $unknown,
            'clinical_question' => 'Permintaan harus ditolak sebelum lookup.',
            'idempotency_key' => 'radiology-http-denial-0002',
        ])->assertForbidden();
    }

    public function test_setting_specific_order_endpoint_refuses_foreign_encounter(): void
    {
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $encounter = $this->encounter(Encounter::CARE_SETTING_EMERGENCY);

        $this->actingAs($physician)->post(route('radiology.outpatient.orders.store', $encounter), [
            'definition_version' => 'CROSS_SETTING_RADIOLOGY_ORDER_REPORT_V1',
            'examination_public_id' => (string) Str::ulid(),
            'clinical_question' => 'Endpoint tidak sesuai jenis episode.',
            'idempotency_key' => 'radiology-http-setting-0001',
        ])->assertNotFound();
    }

    public function test_admin_can_manage_versioned_radiology_master_through_http(): void
    {
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);

        $this->actingAs($admin)->get(route('radiology.masters.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('manajemen-data/radiologi/index')
                ->where('permissions.can_manage', true)
                ->has('examinations', 0));

        $this->actingAs($admin)->post(route('radiology.masters.store'), [
            'code' => 'RAD-HTTP-03',
            'display_name' => 'CT Kepala',
            'preparation_instruction' => 'Ikuti instruksi petugas.',
            'idempotency_key' => 'radiology-http-master-0003',
        ])->assertRedirect();
        $master = RadiologyExaminationMaster::query()->sole();

        $this->actingAs($admin)->patch(route('radiology.masters.update', $master), [
            'expected_version' => 1,
            'display_name' => 'CT Scan Kepala',
            'preparation_instruction' => 'Ikuti instruksi petugas radiologi.',
            'idempotency_key' => 'radiology-http-master-0004',
        ])->assertRedirect();
        $this->assertSame('CT Scan Kepala', $master->fresh()->display_name);

        $this->actingAs($admin)->post(route('radiology.masters.retire', $master), [
            'expected_version' => 2,
            'idempotency_key' => 'radiology-http-master-0005',
        ])->assertRedirect();
        $this->assertSame(RadiologyExaminationMaster::RETIRED, $master->fresh()->state);
        $this->assertDatabaseCount('radiology_examination_master_versions', 3);
    }

    private function encounter(string $careSetting): Encounter
    {
        $encounter = Encounter::factory()->create([
            'patient_id' => Patient::factory()->create(['is_synthetic' => true])->id,
            'care_setting' => $careSetting,
            'status' => $careSetting === Encounter::CARE_SETTING_EMERGENCY
                ? Encounter::STATUS_REGISTERED
                : Encounter::STATUS_IN_EXAMINATION,
        ]);
        if ($careSetting === Encounter::CARE_SETTING_EMERGENCY) {
            return $this->finalizeEmergencyInitialTriage($encounter, keyPrefix: 'radiology-http-triage');
        }

        return $encounter;
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $user->fresh();
    }
}
