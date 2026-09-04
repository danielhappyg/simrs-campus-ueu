<?php

namespace Tests\Feature\Inpatient;

use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientClinicalDocument;
use App\Models\InpatientWard;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditActorAttribution;
use App\Support\Audit\AuditEvent;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Inpatient\InpatientMasterService;
use Database\Seeders\InpatientMastersSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContinuousInpatientTeachingJourneyTest extends TestCase
{
    use RefreshDatabase;

    private InpatientBed $managedBed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $masterActor = User::factory()->create(['is_system_administrator' => true]);
        $service = app(InpatientMasterService::class);
        $ward = $service->createWard($masterActor, 'RI-MELATI', 'Melati', InpatientMasterService::REASON_INITIAL_SETUP, 'journey-ward-0001', null)->master;
        if (! $ward instanceof InpatientWard) {
            throw new \LogicException('Expected inpatient ward result.');
        }
        $bed = $service->createBed($masterActor, $ward->public_id, 'A-01', 'Tempat Tidur A-01', 'Ruang Melati', 'Kelas 1', InpatientMasterService::REASON_INITIAL_SETUP, 'journey-bed-a01-0001', null)->master;
        if (! $bed instanceof InpatientBed) {
            throw new \LogicException('Expected inpatient bed result.');
        }
        $this->managedBed = $bed;
    }

    public function test_distinct_actors_complete_the_current_synthetic_inpatient_scaffold_through_public_routes(): void
    {
        $registrar = $this->userWithRole('Registrar RI Sintetis', RoleCapabilityMatrix::ROLE_REGISTRAR);
        $nurse = $this->userWithRole('Perawat RI Sintetis', RoleCapabilityMatrix::ROLE_NURSE);
        $physician = $this->userWithRole('Dokter RI Sintetis', RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $ward = InpatientMastersSeeder::wardsCatalogue()[0];

        $this->actingAs($registrar)
            ->post(route('pendaftaran.rawat-inap.store'), $this->registrationPayload([
                'ward_name' => $ward['name'],
                'ward_class' => $ward['class'],
                'bed_code' => $ward['beds'][0],
            ]))
            ->assertRedirect(route('pendaftaran.rawat-inap.index'))
            ->assertSessionHas('last_encounter_public_id');

        $patient = Patient::query()->where('nik', '3174011505909001')->sole();
        $encounter = Encounter::query()->where('patient_id', $patient->id)->sole();

        $this->assertTrue($patient->is_synthetic);
        $this->assertSame($registrar->id, $patient->created_by_user_id);
        $this->assertSame($registrar->id, $encounter->registered_by_user_id);
        $this->assertSame(Encounter::CARE_SETTING_INPATIENT, $encounter->care_setting);
        $this->assertSame(Encounter::STATUS_REGISTERED, $encounter->status);
        $this->assertSame($ward['name'], $encounter->ward_name);
        $this->assertSame($ward['class'], $encounter->ward_class);
        $this->assertSame($ward['beds'][0], $encounter->bed_code);
        $this->assertAttributedAudit('patient.register', $registrar, 'SUCCESS', null, $encounter->public_id);

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rawat-inap.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pendaftaran/rawat-inap')
                ->has('todaysEncounters', 1)
                ->where('todaysEncounters.0.public_id', $encounter->public_id)
                ->where('todaysEncounters.0.status', Encounter::STATUS_REGISTERED)
                ->where('todaysEncounters.0.patient.full_name', $patient->full_name)
                ->where('todaysEncounters.0.ward_name', $ward['name'])
                ->where('todaysEncounters.0.ward_class', $ward['class'])
                ->where('todaysEncounters.0.bed_code', $ward['beds'][0]));

        $patientCountBeforeRejectedAdmission = Patient::query()->count();
        $encounterCountBeforeRejectedAdmission = Encounter::query()->count();
        $auditCountBeforeRejectedAdmission = AuditEvent::query()->count();

        $this->actingAs($registrar)
            ->post(route('pendaftaran.rawat-inap.store'), $this->registrationPayload([
                'full_name' => 'Pasien RI Tempat Tidur Ganda',
                'nik' => '3174011505909002',
                'ward_name' => $ward['name'],
                'ward_class' => $ward['class'],
                'bed_code' => $ward['beds'][0],
            ]))
            ->assertRedirect(route('pendaftaran.rawat-inap.index'))
            ->assertSessionHasErrors('bed_code');

        $this->assertSame($patientCountBeforeRejectedAdmission, Patient::query()->count());
        $this->assertSame($encounterCountBeforeRejectedAdmission, Encounter::query()->count());
        $this->assertSame($auditCountBeforeRejectedAdmission, AuditEvent::query()->count());
        $this->assertDatabaseMissing('patients', ['nik' => '3174011505909002']);
        $this->assertSame(1, Encounter::query()
            ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->where('bed_code', $ward['beds'][0])
            ->count());

        $this->actingAs($nurse)
            ->get(route('pemeriksaan.rawat-inap.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pemeriksaan/rawat-jalan/index')
                ->where('variant', 'rawat-inap')
                ->has('encounters', 1)
                ->where('encounters.0.public_id', $encounter->public_id)
                ->where('encounters.0.status', Encounter::STATUS_REGISTERED)
                ->where('encounters.0.patient.full_name', $patient->full_name)
                ->where('encounters.0.ward_name', $ward['name'])
                ->where('encounters.0.ward_class', $ward['class'])
                ->where('encounters.0.bed_code', $ward['beds'][0]));

        $this->actingAs($nurse)
            ->post(route('pemeriksaan.rawat-inap.documents.draft', [$encounter, InpatientClinicalDocument::TYPE_NURSING_DAILY]), [
                'definition_version' => InpatientClinicalDocument::DEFINITION_VERSION,
                'expected_version' => 0,
                'idempotency_key' => 'journey-nurse-draft-0001',
                'fields' => [
                    'nursing_observation' => 'Pasien sadar dan stabil.',
                    'nursing_intervention' => 'Pemantauan tanda klinis.',
                    'nursing_evaluation' => 'Respons baik.',
                ],
            ])
            ->assertRedirect(route('pemeriksaan.rawat-inap.show', $encounter));
        $this->assertSame(Encounter::STATUS_REGISTERED, $encounter->fresh()->status);

        $this->actingAs($nurse)
            ->post(route('pemeriksaan.rawat-inap.documents.finalize', [$encounter, InpatientClinicalDocument::TYPE_NURSING_DAILY]), [
                'definition_version' => InpatientClinicalDocument::DEFINITION_VERSION,
                'expected_version' => 1,
                'idempotency_key' => 'journey-nurse-final-0001',
            ])->assertRedirect(route('pemeriksaan.rawat-inap.show', $encounter));

        $nursingDocument = InpatientClinicalDocument::query()->where('document_type', InpatientClinicalDocument::TYPE_NURSING_DAILY)->sole();
        $this->assertSame($nurse->id, $nursingDocument->author_user_id);
        $this->assertSame(Encounter::STATUS_IN_EXAMINATION, $encounter->fresh()->status);
        $this->assertAttributedAudit('clinical.inpatient.nursing.finalize', $nurse, 'SUCCESS', null, $nursingDocument->public_id);

        $documentCountBeforeWrongRole = InpatientClinicalDocument::query()->count();
        $this->actingAs($nurse)
            ->post(route('pemeriksaan.rawat-inap.documents.draft', [$encounter, InpatientClinicalDocument::TYPE_MEDICAL_DAILY]), [
                'definition_version' => InpatientClinicalDocument::DEFINITION_VERSION,
                'expected_version' => 0,
                'idempotency_key' => 'journey-wrong-role-0001',
                'fields' => [],
            ])
            ->assertForbidden();

        $this->assertSame($documentCountBeforeWrongRole, InpatientClinicalDocument::query()->count());
        $this->assertSame(Encounter::STATUS_IN_EXAMINATION, $encounter->fresh()->status);
        $this->assertAttributedAudit(
            'authorization.denied',
            $nurse,
            'DENIED',
            'authorization_check_failed',
            'pemeriksaan.rawat-inap.documents.draft',
        );

        $this->actingAs($physician)
            ->post(route('pemeriksaan.rawat-inap.documents.draft', [$encounter, InpatientClinicalDocument::TYPE_MEDICAL_DAILY]), [
                'definition_version' => InpatientClinicalDocument::DEFINITION_VERSION,
                'expected_version' => 0,
                'idempotency_key' => 'journey-medical-draft-0001',
                'fields' => [
                    'subjective' => 'Keluhan membaik.', 'objective' => 'Kondisi stabil.',
                    'assessment' => 'Observasi lanjutan.', 'plan' => 'Lanjutkan pemantauan.',
                ],
            ])
            ->assertRedirect(route('pemeriksaan.rawat-inap.show', $encounter));

        $this->actingAs($physician)
            ->post(route('pemeriksaan.rawat-inap.documents.finalize', [$encounter, InpatientClinicalDocument::TYPE_MEDICAL_DAILY]), [
                'definition_version' => InpatientClinicalDocument::DEFINITION_VERSION,
                'expected_version' => 1,
                'idempotency_key' => 'journey-medical-final-0001',
            ])->assertRedirect(route('pemeriksaan.rawat-inap.show', $encounter));

        $medicalDocument = InpatientClinicalDocument::query()->where('document_type', InpatientClinicalDocument::TYPE_MEDICAL_DAILY)->sole();
        $this->assertSame($physician->id, $medicalDocument->author_user_id);
        $this->assertSame(Encounter::STATUS_IN_EXAMINATION, $encounter->fresh()->status);
        $this->assertAttributedAudit('clinical.inpatient.medical.finalize', $physician, 'SUCCESS', null, $medicalDocument->public_id);

        $this->actingAs($physician)
            ->get(route('pemeriksaan.rawat-inap.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('variant', 'rawat-inap')
                ->has('encounters', 1)
                ->where('encounters.0.public_id', $encounter->public_id)
                ->where('encounters.0.status', Encounter::STATUS_IN_EXAMINATION)
                ->where('encounters.0.patient.full_name', $patient->full_name));

        $this->actingAs($physician)
            ->get(route('pemeriksaan.rawat-inap.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('variant', 'rawat-inap')
                ->where('encounter.public_id', $encounter->public_id)
                ->where('encounter.status', Encounter::STATUS_IN_EXAMINATION)
                ->has('documentation.documents', 2)
                ->where('documentation.documents.0.author_name', $physician->name)
                ->where('documentation.documents.1.author_name', $nurse->name)
                ->has('documentation.versions', 4)
                ->has('legacyEntries', 0));

        $this->assertSame(7, AuditEvent::query()->where('outcome', 'SUCCESS')->count());
        $this->assertSame(1, AuditEvent::query()->where('outcome', 'DENIED')->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function registrationPayload(array $overrides = []): array
    {
        $ward = InpatientMastersSeeder::wardsCatalogue()[0];

        return array_merge([
            'full_name' => 'Pasien Alur RI Sintetis',
            'date_of_birth' => '1990-05-15',
            'sex' => Patient::SEX_PEREMPUAN,
            'nik' => '3174011505909001',
            'ward_name' => $ward['name'],
            'ward_class' => $ward['class'],
            'bed_code' => $ward['beds'][0],
            'bed_public_id' => $this->managedBed->public_id,
            'payer_type' => Encounter::PAYER_UMUM,
            'continue_from' => Encounter::CONTINUE_LANGSUNG,
            'admission_authority_type' => Encounter::AUTHORITY_PLANNED_ORDER,
            'admission_authority_reference' => 'ORDER-RI-JOURNEY-0001',
            'chief_complaint' => 'Demam dan mual pada skenario pengajaran sintetis.',
            'is_synthetic' => true,
        ], $overrides);
    }

    private function userWithRole(string $name, string $roleSlug): User
    {
        $user = User::factory()->create(['name' => $name]);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }

    private function assertAttributedAudit(
        string $action,
        User $actor,
        string $outcome,
        ?string $reason,
        string $resourceId,
    ): void {
        $this->assertDatabaseHas('audit_events', [
            'action' => $action,
            'resource_id' => $resourceId,
            'outcome' => $outcome,
            'reason' => $reason,
            'actor_user_id' => $actor->id,
            'actor_type' => AuditActorAttribution::TYPE_USER,
            'actor_reference' => $actor->public_id,
        ]);
    }
}
