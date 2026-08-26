<?php

namespace Tests\Feature\Inpatient;

use App\Models\ClinicalEntry;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditActorAttribution;
use App\Support\Audit\AuditEvent;
use App\Support\Authorization\RoleCapabilityMatrix;
use Database\Seeders\InpatientMastersSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContinuousInpatientTeachingJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
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
            ->post(route('pemeriksaan.rawat-inap.entries.store', $encounter), [
                'entry_type' => ClinicalEntry::TYPE_NURSING_INTAKE,
                'body' => 'Asesmen awal keperawatan rawat inap untuk skenario sintetis.',
            ])
            ->assertRedirect(route('pemeriksaan.rawat-inap.show', $encounter));

        $nursingEntry = ClinicalEntry::query()
            ->where('encounter_id', $encounter->id)
            ->where('entry_type', ClinicalEntry::TYPE_NURSING_INTAKE)
            ->sole();
        $this->assertSame($nurse->id, $nursingEntry->author_user_id);
        $this->assertSame(Encounter::STATUS_IN_EXAMINATION, $encounter->fresh()->status);
        $this->assertAttributedAudit('clinical.note.write', $nurse, 'SUCCESS', null, $encounter->public_id);

        $entryCountBeforeWrongRole = ClinicalEntry::query()->count();
        $this->actingAs($nurse)
            ->post(route('pemeriksaan.rawat-inap.entries.store', $encounter), [
                'entry_type' => ClinicalEntry::TYPE_MEDICAL_ASSESSMENT,
                'body' => 'Percobaan catatan medis oleh peran yang tidak berwenang.',
            ])
            ->assertForbidden();

        $this->assertSame($entryCountBeforeWrongRole, ClinicalEntry::query()->count());
        $this->assertSame(Encounter::STATUS_IN_EXAMINATION, $encounter->fresh()->status);
        $this->assertAttributedAudit(
            'authorization.denied',
            $nurse,
            'DENIED',
            'authorization_check_failed',
            'pemeriksaan.rawat-inap.entries.store',
        );

        $this->actingAs($physician)
            ->post(route('pemeriksaan.rawat-inap.entries.store', $encounter), [
                'entry_type' => ClinicalEntry::TYPE_MEDICAL_ASSESSMENT,
                'body' => 'Asesmen medis awal rawat inap untuk skenario sintetis.',
            ])
            ->assertRedirect(route('pemeriksaan.rawat-inap.show', $encounter));

        $medicalEntry = ClinicalEntry::query()
            ->where('encounter_id', $encounter->id)
            ->where('entry_type', ClinicalEntry::TYPE_MEDICAL_ASSESSMENT)
            ->sole();
        $this->assertSame($physician->id, $medicalEntry->author_user_id);
        $this->assertSame(Encounter::STATUS_READY_FOR_RM, $encounter->fresh()->status);
        $this->assertAttributedAudit('clinical.note.write', $physician, 'SUCCESS', null, $encounter->public_id);

        $this->actingAs($physician)
            ->get(route('pemeriksaan.rawat-inap.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('variant', 'rawat-inap')
                ->has('encounters', 1)
                ->where('encounters.0.public_id', $encounter->public_id)
                ->where('encounters.0.status', Encounter::STATUS_READY_FOR_RM)
                ->where('encounters.0.patient.full_name', $patient->full_name));

        $this->actingAs($physician)
            ->get(route('pemeriksaan.rawat-inap.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('variant', 'rawat-inap')
                ->where('encounter.public_id', $encounter->public_id)
                ->where('encounter.status', Encounter::STATUS_READY_FOR_RM)
                ->has('encounter.entries', 2)
                ->where('encounter.entries.0.entry_type', ClinicalEntry::TYPE_NURSING_INTAKE)
                ->where('encounter.entries.0.author_name', $nurse->name)
                ->where('encounter.entries.1.entry_type', ClinicalEntry::TYPE_MEDICAL_ASSESSMENT)
                ->where('encounter.entries.1.author_name', $physician->name));

        $this->assertSame(3, AuditEvent::query()->where('outcome', 'SUCCESS')->count());
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
            'payer_type' => Encounter::PAYER_UMUM,
            'continue_from' => Encounter::CONTINUE_LANGSUNG,
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
