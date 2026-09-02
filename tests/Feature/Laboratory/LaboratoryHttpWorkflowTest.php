<?php

namespace Tests\Feature\Laboratory;

use App\Models\Encounter;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryResultVersion;
use App\Models\LaboratorySpecimenAttempt;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Laboratory\LaboratoryMasterService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FinalizesEmergencyInitialTriage;
use Tests\TestCase;

final class LaboratoryHttpWorkflowTest extends TestCase
{
    use FinalizesEmergencyInitialTriage;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seedEmergencyTriageVocabulary();
    }

    public function test_exact_ui_payloads_complete_the_governed_cross_setting_workflow(): void
    {
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->actor(RoleCapabilityMatrix::ROLE_NURSE);
        $technologist = $this->actor(RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST);
        $verifier = $this->actor(RoleCapabilityMatrix::ROLE_LABORATORY_VERIFIER);
        $master = app(LaboratoryMasterService::class)->create(
            $admin,
            'LAB-HTTP',
            'Darah lengkap HTTP',
            'Darah EDTA',
            'Koleksi sesuai prosedur.',
            [
                ['code' => 'HGB', 'display_name' => 'Hemoglobin', 'value_kind' => 'NUMERIC', 'unit_text' => 'g/dL', 'reference_text' => 'Rujukan unit.', 'critical_allowed' => true],
                ['code' => 'PLT', 'display_name' => 'Trombosit', 'value_kind' => 'NUMERIC', 'unit_text' => '10^3/uL', 'reference_text' => 'Rujukan unit.', 'critical_allowed' => true],
            ],
            'laboratory-http-master-0001',
        )->record;
        $encounter = Encounter::factory()->create([
            'patient_id' => Patient::factory()->create(['is_synthetic' => true])->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'status' => Encounter::STATUS_IN_EXAMINATION,
        ]);

        $this->actingAs($physician)->post(route('laboratory.outpatient.orders.store', $encounter), [
            'definition_version' => 'CROSS_SETTING_LABORATORY_SPECIMEN_RESULT_V1',
            'examination_public_id' => $master->public_id,
            'priority' => 'URGENT',
            'clinical_question' => 'Evaluasi anemia dan trombosit.',
            'idempotency_key' => 'laboratory-http-order-0001',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $order = LaboratoryOrder::query()->sole();

        $this->actingAs($nurse)->post(route('laboratory.specimens.collect', $order), [
            'expected_order_version' => 1,
            'note' => 'Koleksi bedside.',
            'idempotency_key' => 'laboratory-http-collect-0001',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $attempt = LaboratorySpecimenAttempt::query()->sole();

        $this->actingAs($technologist)->post(route('laboratory.specimens.receive', $attempt), [
            'specimen_public_id' => $attempt->public_id,
            'idempotency_key' => 'laboratory-http-receive-0001',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($technologist)->post(route('laboratory.specimens.accept', $attempt), [
            'expected_order_version' => 1,
            'idempotency_key' => 'laboratory-http-accept-0001',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($technologist)->post(route('laboratory.orders.results.save', $order), [
            'expected_order_version' => 2,
            'expected_result_version' => 0,
            'specimen_public_id' => $attempt->public_id,
            'results' => $this->results('6.2', '45'),
            'idempotency_key' => 'laboratory-http-draft-0001',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $draft = LaboratoryResultVersion::query()->sole();

        $this->actingAs($verifier)->post(route('laboratory.orders.results.verify', $order), [
            'expected_order_version' => 2,
            'expected_result_version' => $draft->version,
            'critical_communication' => [
                'recipient_user_public_id' => $physician->public_id,
                'communication_method' => 'TELEPHONE',
                'outcome' => 'COMMUNICATED',
                'note' => 'Dibacakan ulang oleh dokter pemesan.',
                'communicated_at' => now()->toIso8601String(),
            ],
            'idempotency_key' => 'laboratory-http-verify-0001',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $verified = LaboratoryResultVersion::query()->orderByDesc('version')->firstOrFail();

        $this->actingAs($physician)->post(route('laboratory.orders.results.acknowledge', $order), [
            'expected_order_version' => 3,
            'expected_result_version' => $verified->version,
            'idempotency_key' => 'laboratory-http-ack-0001',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($verifier)->post(route('laboratory.orders.results.amend', $order), [
            'expected_order_version' => 3,
            'expected_result_version' => $verified->version,
            'reason_code' => 'TECHNICAL_CORRECTION',
            'results' => $this->results('6.4', '48'),
            'critical_communication' => [
                'recipient_user_public_id' => $physician->public_id,
                'communication_method' => 'DIRECT',
                'outcome' => 'COMMUNICATED',
                'note' => 'Amandemen kritis disampaikan kembali.',
                'communicated_at' => now()->toIso8601String(),
            ],
            'idempotency_key' => 'laboratory-http-amend-0001',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseCount('laboratory_orders', 1);
        $this->assertDatabaseCount('laboratory_specimen_attempts', 1);
        $this->assertDatabaseCount('laboratory_specimen_events', 2);
        $this->assertDatabaseCount('laboratory_result_versions', 3);
        $this->assertDatabaseCount('laboratory_critical_communications', 2);
        $this->assertDatabaseCount('laboratory_result_acknowledgements', 1);
    }

    public function test_emergency_and_inpatient_http_lifecycles_render_the_governed_encounter_projection(): void
    {
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->actor(RoleCapabilityMatrix::ROLE_NURSE);
        $technologist = $this->actor(RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST);
        $verifier = $this->actor(RoleCapabilityMatrix::ROLE_LABORATORY_VERIFIER);
        $master = app(LaboratoryMasterService::class)->create(
            $admin,
            'LAB-HTTP-X',
            'Darah lengkap lintas layanan',
            'Darah EDTA',
            null,
            [
                ['code' => 'HGB', 'display_name' => 'Hemoglobin', 'value_kind' => 'NUMERIC', 'unit_text' => 'g/dL', 'reference_text' => null, 'critical_allowed' => true],
                ['code' => 'PLT', 'display_name' => 'Trombosit', 'value_kind' => 'NUMERIC', 'unit_text' => '10^3/uL', 'reference_text' => null, 'critical_allowed' => true],
            ],
            'laboratory-http-cross-master-0001',
        )->record;

        foreach ([
            [Encounter::CARE_SETTING_EMERGENCY, 'laboratory.emergency.orders.store', 'pemeriksaan.igd.show'],
            [Encounter::CARE_SETTING_INPATIENT, 'laboratory.inpatient.orders.store', 'pemeriksaan.rawat-inap.show'],
        ] as $index => [$careSetting, $createRoute, $showRoute]) {
            $suffix = (string) ($index + 1);
            $encounter = Encounter::factory()->create([
                'patient_id' => Patient::factory()->create(['is_synthetic' => true])->id,
                'care_setting' => $careSetting,
                'status' => $careSetting === Encounter::CARE_SETTING_EMERGENCY
                    ? Encounter::STATUS_REGISTERED
                    : Encounter::STATUS_IN_EXAMINATION,
            ]);
            if ($careSetting === Encounter::CARE_SETTING_EMERGENCY) {
                $encounter = $this->finalizeEmergencyInitialTriage($encounter, $nurse, 'lab-http-triage');
            }
            $this->actingAs($physician)->post(route($createRoute, $encounter), [
                'definition_version' => 'CROSS_SETTING_LABORATORY_SPECIMEN_RESULT_V1',
                'examination_public_id' => $master->public_id,
                'priority' => 'ROUTINE',
                'clinical_question' => 'Validasi alur lintas layanan.',
                'idempotency_key' => "lab-http-cross-order-000{$suffix}",
            ])->assertRedirect()->assertSessionHasNoErrors();
            $order = LaboratoryOrder::query()->where('encounter_id', $encounter->id)->sole();
            $this->actingAs($nurse)->post(route('laboratory.specimens.collect', $order), [
                'expected_order_version' => 1,
                'note' => null,
                'idempotency_key' => "lab-http-cross-collect-000{$suffix}",
            ])->assertRedirect()->assertSessionHasNoErrors();
            $attempt = LaboratorySpecimenAttempt::query()->where('laboratory_order_id', $order->id)->sole();
            $this->actingAs($technologist)->post(route('laboratory.specimens.receive', $attempt), [
                'specimen_public_id' => $attempt->public_id,
                'idempotency_key' => "lab-http-cross-receive-000{$suffix}",
            ])->assertRedirect()->assertSessionHasNoErrors();
            $this->actingAs($technologist)->post(route('laboratory.specimens.accept', $attempt), [
                'expected_order_version' => 1,
                'idempotency_key' => "lab-http-cross-accept-000{$suffix}",
            ])->assertRedirect()->assertSessionHasNoErrors();
            $this->actingAs($technologist)->post(route('laboratory.orders.results.save', $order), [
                'expected_order_version' => 2,
                'expected_result_version' => 0,
                'specimen_public_id' => $attempt->public_id,
                'results' => $this->normalResults(),
                'idempotency_key' => "lab-http-cross-draft-000{$suffix}",
            ])->assertRedirect()->assertSessionHasNoErrors();
            $draft = LaboratoryResultVersion::query()->where('laboratory_order_id', $order->id)->sole();
            $this->actingAs($verifier)->post(route('laboratory.orders.results.verify', $order), [
                'expected_order_version' => 2,
                'expected_result_version' => $draft->version,
                'critical_communication' => null,
                'idempotency_key' => "lab-http-cross-verify-000{$suffix}",
            ])->assertRedirect()->assertSessionHasNoErrors();
            $verified = LaboratoryResultVersion::query()->where('laboratory_order_id', $order->id)->orderByDesc('version')->firstOrFail();
            $this->actingAs($physician)->post(route('laboratory.orders.results.acknowledge', $order), [
                'expected_order_version' => 3,
                'expected_result_version' => $verified->version,
                'idempotency_key' => "lab-http-cross-ack-000{$suffix}",
            ])->assertRedirect()->assertSessionHasNoErrors();

            $this->actingAs($physician)->get(route($showRoute, $encounter))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('laboratory.encounter.care_setting', $careSetting)
                    ->where('laboratory.orders.0.public_id', $order->public_id)
                    ->where('laboratory.orders.0.result.state', 'VERIFIED')
                    ->where('laboratory.orders.0.result.acknowledgement.is_current', true));
        }
    }

    /** @return list<array<string, mixed>> */
    private function results(string $hgb, string $platelet): array
    {
        return [
            ['code' => 'HGB', 'value' => $hgb, 'note' => null, 'interpretation' => 'ABNORMAL'],
            ['code' => 'PLT', 'value' => $platelet, 'note' => 'Nilai kritis manual.', 'interpretation' => 'CRITICAL'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function normalResults(): array
    {
        return [
            ['code' => 'HGB', 'value' => '13.2', 'note' => null, 'interpretation' => 'NORMAL'],
            ['code' => 'PLT', 'value' => '220', 'note' => null, 'interpretation' => 'NORMAL'],
        ];
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $user->fresh();
    }
}
