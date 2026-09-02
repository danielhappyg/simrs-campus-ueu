<?php

namespace Tests\Feature\Emergency;

use App\Models\Clinic;
use App\Models\ClinicSchedule;
use App\Models\Doctor;
use App\Models\EmergencyTriageVocabulary;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Authorization\RoleCapabilityMatrix;
use Database\Seeders\EmergencyTriageVocabularySeeder;
use Database\Seeders\OutpatientMastersSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ContinuousEmergencyTeachingJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(OutpatientMastersSeeder::class);
        $this->seed(EmergencyTriageVocabularySeeder::class);
    }

    public function test_distinct_roles_complete_the_structured_emergency_route_journey(): void
    {
        $registrar = $this->actor(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $nurse = $this->actor(RoleCapabilityMatrix::ROLE_NURSE);
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        ['clinic' => $clinic, 'doctor' => $doctor, 'schedule' => $schedule] = $this->igdMasterChain();

        $this->actingAs($registrar)
            ->post(route('pendaftaran.igd.store'), $this->registrationPayload($clinic, $doctor, $schedule))
            ->assertRedirect(route('pendaftaran.igd.index'));
        $encounter = Encounter::query()->where('care_setting', Encounter::CARE_SETTING_EMERGENCY)->sole();

        $this->actingAs($nurse)->get(route('pemeriksaan.triage.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pemeriksaan/triage/index')
                ->where('showPathPrefix', '/pemeriksaan/triage')
                ->has('encounters', 1)
                ->where('encounters.0.public_id', $encounter->public_id)
                ->where('encounters.0.triage', null));
        $this->actingAs($nurse)->get(route('pemeriksaan.igd.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pemeriksaan/igd/index')
                ->has('encounters', 1));

        $encounter->update(['status' => Encounter::STATUS_IN_EXAMINATION]);
        $missingMaster = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $this->actingAs($physician)->post(route('laboratory.emergency.orders.store', $encounter), [
            'definition_version' => 'CROSS_SETTING_LABORATORY_SPECIMEN_RESULT_V1',
            'examination_public_id' => $missingMaster,
            'priority' => 'URGENT',
            'clinical_question' => 'Evaluasi awal IGD.',
            'idempotency_key' => 'http-lab-before-triage-0001',
        ])->assertSessionHasErrors('laboratory');
        $this->actingAs($physician)->post(route('radiology.emergency.orders.store', $encounter), [
            'definition_version' => 'CROSS_SETTING_RADIOLOGY_ORDER_REPORT_V1',
            'examination_public_id' => $missingMaster,
            'clinical_question' => 'Evaluasi radiologi awal IGD.',
            'idempotency_key' => 'http-rad-before-triage-0001',
        ])->assertSessionHasErrors('radiology');
        $this->assertDatabaseCount('laboratory_orders', 0);
        $this->assertDatabaseCount('radiology_orders', 0);
        $this->assertSame(
            2,
            AuditEvent::query()->where('reason', 'initial_triage_required')->count(),
            AuditEvent::query()->get(['action', 'reason'])->toJson(),
        );
        $encounter->update(['status' => Encounter::STATUS_REGISTERED]);

        $vocabulary = EmergencyTriageVocabulary::query()->where('state', 'ACTIVE')->sole();
        $this->actingAs($nurse)
            ->post(route('emergency.triage.initial', $encounter), $this->triagePayload($vocabulary))
            ->assertSessionHasNoErrors();
        $this->assertSame(Encounter::STATUS_IN_EXAMINATION, $encounter->fresh()->status);
        $this->assertDatabaseHas('emergency_triage_assessments', [
            'encounter_id' => $encounter->id,
            'assessment_type' => 'INITIAL',
            'category_code' => 'KUNING',
            'assessor_user_id' => $nurse->id,
        ]);

        $this->actingAs($nurse)
            ->post(route('emergency.documents.draft', [$encounter, 'NURSING']), [
                'definition_version' => 'STRUCTURED_EMERGENCY_TRIAGE_DISPOSITION_V1',
                'expected_version' => 0,
                'fields' => $this->nursingFields(),
                'idempotency_key' => 'http-nursing-draft-0001',
            ])->assertSessionHasNoErrors();
        $this->actingAs($nurse)
            ->post(route('emergency.documents.finalize', [$encounter, 'NURSING']), [
                'definition_version' => 'STRUCTURED_EMERGENCY_TRIAGE_DISPOSITION_V1',
                'expected_version' => 1,
                'idempotency_key' => 'http-nursing-final-0001',
            ])->assertSessionHasNoErrors();
        $this->actingAs($physician)
            ->post(route('emergency.documents.draft', [$encounter, 'MEDICAL']), [
                'definition_version' => 'STRUCTURED_EMERGENCY_TRIAGE_DISPOSITION_V1',
                'expected_version' => 0,
                'fields' => $this->medicalFields(),
                'idempotency_key' => 'http-medical-draft-0001',
            ])->assertSessionHasNoErrors();
        $this->actingAs($physician)
            ->post(route('emergency.documents.finalize', [$encounter, 'MEDICAL']), [
                'definition_version' => 'STRUCTURED_EMERGENCY_TRIAGE_DISPOSITION_V1',
                'expected_version' => 1,
                'idempotency_key' => 'http-medical-final-0001',
            ])->assertSessionHasNoErrors();

        $this->actingAs($physician)
            ->post(route('emergency.disposition.sign', $encounter), [
                'expected_disposition_version' => null,
                'disposition_type' => 'PULANG',
                'payload' => [
                    'condition_at_discharge' => 'Stabil',
                    'instructions' => 'Istirahat dan minum cukup.',
                    'warning_signs' => 'Kembali bila keluhan memburuk.',
                    'follow_up_plan' => 'Kontrol sesuai jadwal.',
                ],
                'idempotency_key' => 'http-disposition-pulang-0001',
            ])->assertSessionHasNoErrors();

        $this->assertSame(Encounter::STATUS_READY_FOR_RM, $encounter->fresh()->status);
        $this->actingAs($physician)->get(route('pemeriksaan.igd.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pemeriksaan/igd/show')
                ->where('encounter.public_id', $encounter->public_id)
                ->where('triage.current.category.code', 'KUNING')
                ->where('documentation.current.nursing.state', 'FINAL')
                ->where('documentation.current.medical.state', 'FINAL')
                ->where('disposition.current.code', 'PULANG')
                ->where('follow_up.all_assignments_accepted', true));
        $this->actingAs($nurse)->get(route('pemeriksaan.triage.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pemeriksaan/triage/show')
                ->where('triage.current.category.code', 'KUNING'));

        $this->actingAs($nurse)
            ->post(route('pemeriksaan.igd.entries.store', $encounter), [
                'entry_type' => 'NURSING_INTAKE',
                'body' => 'Jalur lama tidak boleh menulis.',
            ])->assertStatus(410);
        $this->assertDatabaseCount('clinical_entries', 0);

        $this->actingAs($admin)->get(route('emergency.triage-vocabulary.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('manajemen-data/triage/index')
                ->where('permissions.can_manage', true)
                ->has('vocabularies', 1)
                ->has('vocabularies.0.categories', 4));
        $this->actingAs($nurse)->get(route('emergency.triage-vocabulary.index'))->assertForbidden();
    }

    /** @return array{clinic: Clinic, doctor: Doctor, schedule: ClinicSchedule} */
    private function igdMasterChain(): array
    {
        $clinic = Clinic::query()->where('code', 'IGD')->firstOrFail();
        $doctor = Doctor::query()->where('clinic_id', $clinic->id)->orderBy('id')->firstOrFail();
        $schedule = ClinicSchedule::query()->where('clinic_id', $clinic->id)->where('doctor_id', $doctor->id)->orderBy('id')->firstOrFail();

        return compact('clinic', 'doctor', 'schedule');
    }

    /** @return array<string, mixed> */
    private function registrationPayload(Clinic $clinic, Doctor $doctor, ClinicSchedule $schedule): array
    {
        return [
            'full_name' => 'Pasien Alur IGD', 'date_of_birth' => '1990-08-27', 'sex' => Patient::SEX_PEREMPUAN,
            'nik' => '3174012708909001', 'clinic_public_id' => $clinic->public_id, 'doctor_public_id' => $doctor->public_id,
            'schedule_public_id' => $schedule->public_id, 'visit_date' => now()->toDateString(),
            'admission_mode' => Encounter::ADMISSION_DATANG_SENDIRI, 'payer_type' => Encounter::PAYER_UMUM,
            'case_type' => Encounter::CASE_NON_BEDAH, 'accident_type' => Encounter::ACCIDENT_NONE,
            'chief_complaint' => 'Sesak sejak pagi.', 'is_synthetic' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function triagePayload(EmergencyTriageVocabulary $vocabulary): array
    {
        $normal = ['state' => 'ASSESSED_NO_CONCERN', 'note' => null];

        return [
            'vocabulary_public_id' => $vocabulary->public_id, 'vocabulary_version' => $vocabulary->version,
            'expected_assessment_version' => 0, 'category_code' => 'KUNING', 'observed_at' => now()->toIso8601String(),
            'late_entry_reason' => null, 'reassessment_reason' => null, 'presenting_concern' => 'Sesak sejak pagi.',
            'clinical_basis' => 'Kategori dipilih manual setelah penilaian ABCDE.', 'arrival_condition' => 'Sadar dan dapat berkomunikasi.',
            'abcde' => ['airway' => $normal, 'breathing' => $normal, 'circulation' => $normal, 'disability' => $normal, 'exposure' => $normal],
            'consciousness' => 'ALERT',
            'vitals' => ['respiratory_rate' => 22, 'pulse' => 96, 'systolic_pressure' => 120, 'diastolic_pressure' => 80, 'oxygen_saturation' => 95, 'temperature' => 36.7, 'pain_score' => 2, 'weight' => null],
            'unobtainable_fields' => [], 'unobtainable_reason' => null, 'trauma' => false, 'trauma_note' => null,
            'isolation_precaution' => false, 'isolation_note' => null, 'handoff_note' => 'Lanjutkan pemantauan.',
            'idempotency_key' => 'http-triage-initial-0001',
        ];
    }

    /** @return array<string, string> */
    private function nursingFields(): array
    {
        return ['arrival_condition' => 'Sadar', 'focused_assessment' => 'Asesmen fokus dilakukan', 'interventions' => 'Pemantauan berkala', 'response_evaluation' => 'Respons dicatat', 'safety_observation_needs' => 'Observasi rutin', 'handoff_note' => 'Serah terima tercatat'];
    }

    /** @return array<string, string> */
    private function medicalFields(): array
    {
        return ['anamnesis' => 'Anamnesis terstruktur', 'focused_physical_examination' => 'Pemeriksaan fisik fokus', 'clinical_impression' => 'Impresi klinis sementara', 'problem_list' => 'Daftar masalah', 'treatment_action_plan' => 'Rencana tindakan', 'diagnostic_order_rationale' => 'Belum ada pesanan diagnostik', 'disposition_readiness_note' => 'Siap dipertimbangkan untuk disposisi'];
    }

    private function actor(string $role): User
    {
        $actor = User::factory()->create(['status' => 'ACTIVE', 'is_system_administrator' => false]);
        $actor->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $actor;
    }
}
