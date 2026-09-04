<?php

namespace Tests\Feature\Inpatient;

use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientClinicalDocument;
use App\Models\InpatientDischargeSummary;
use App\Models\InpatientWard;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Inpatient\InpatientMasterService;
use Database\Seeders\InpatientMastersSeeder;
use Database\Seeders\OutpatientMastersSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Mockery\CompositeExpectation;
use Tests\TestCase;

class InpatientFlowTest extends TestCase
{
    use RefreshDatabase;

    private InpatientBed $managedBed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(OutpatientMastersSeeder::class);
        $masterActor = User::factory()->create(['is_system_administrator' => true]);
        $service = app(InpatientMasterService::class);
        $ward = $service->createWard($masterActor, 'RI-MELATI', 'Melati', InpatientMasterService::REASON_INITIAL_SETUP, 'test-flow-ward-0001', null)->master;
        if (! $ward instanceof InpatientWard) {
            throw new \LogicException('Expected inpatient ward result.');
        }
        $bed = $service->createBed($masterActor, $ward->public_id, 'A-01', 'Tempat Tidur A-01', 'Ruang Melati', 'Kelas 1', InpatientMasterService::REASON_INITIAL_SETUP, 'test-flow-bed-a01-0001', null)->master;
        if (! $bed instanceof InpatientBed) {
            throw new \LogicException('Expected inpatient bed result.');
        }
        $this->managedBed = $bed;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function registrationPayload(array $overrides = []): array
    {
        $ward = InpatientMastersSeeder::wardsCatalogue()[0];

        return array_merge([
            'full_name' => 'Pasien RI Sintetis',
            'date_of_birth' => '1990-05-15',
            'sex' => Patient::SEX_PEREMPUAN,
            'nik' => '3174011555900002',
            'ward_name' => $ward['name'],
            'ward_class' => $ward['class'],
            'bed_code' => $ward['beds'][0],
            'bed_public_id' => $this->managedBed->public_id,
            'payer_type' => Encounter::PAYER_UMUM,
            'continue_from' => Encounter::CONTINUE_LANGSUNG,
            'admission_authority_type' => Encounter::AUTHORITY_PLANNED_ORDER,
            'admission_authority_reference' => 'ORDER-RI-FLOW-0001',
            'chief_complaint' => 'Demam dan mual',
            'is_synthetic' => true,
        ], $overrides);
    }

    public function test_registrar_can_admit_synthetic_inpatient_with_bed(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $ward = InpatientMastersSeeder::wardsCatalogue()[0];

        $response = $this->actingAs($registrar)
            ->post(route('pendaftaran.rawat-inap.store'), $this->registrationPayload());

        $response->assertRedirect(route('pendaftaran.rawat-inap.index'));

        $this->assertDatabaseHas('patients', [
            'full_name' => 'PASIEN RI SINTETIS',
            'nik' => '3174011555900002',
            'is_synthetic' => true,
        ]);

        $this->assertDatabaseHas('encounters', [
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'ward_name' => $ward['name'],
            'ward_class' => $ward['class'],
            'bed_code' => $ward['beds'][0],
            'continue_from' => Encounter::CONTINUE_LANGSUNG,
            'status' => Encounter::STATUS_REGISTERED,
            'payer_type' => Encounter::PAYER_UMUM,
            'queue_date' => now()->toDateString(),
            'queue_number' => 1,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'patient.register',
            'resource_type' => 'encounter',
            'outcome' => 'SUCCESS',
        ]);
        $this->assertDatabaseHas('daily_queue_counters', [
            'queue_date' => now()->toDateString(),
            'last_number' => 1,
        ]);
        $this->assertDatabaseHas('inpatient_bed_claim_mutexes', [
            'bed_code' => $ward['beds'][0],
        ]);

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rawat-inap.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pendaftaran/rawat-inap')
                ->has('todaysEncounters', 1)
                ->has('wards', 1)
                ->where('canOpen', true)
                ->where('todaysEncounters.0.patient.full_name', 'PASIEN RI SINTETIS')
                ->where('todaysEncounters.0.bed_code', $ward['beds'][0]));
    }

    public function test_inpatient_registration_rejects_non_numeric_nik(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);

        $this->actingAs($registrar)
            ->post(route('pendaftaran.rawat-inap.store'), $this->registrationPayload([
                'nik' => '31740115559000AB',
            ]))
            ->assertSessionHasErrors('nik');

        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('encounters', 0);
    }

    public function test_inpatient_registration_hides_examination_handoff_without_open_capability(): void
    {
        $listOnlyRole = Role::query()->create([
            'slug' => 'inpatient-list-only',
            'name' => 'Inpatient list only',
            'description' => 'Synthetic test role without encounter open',
        ]);
        $listOnlyRole->permissions()->sync(Permission::query()
            ->whereIn('name', [Capability::PATIENT_SEARCH, Capability::ENCOUNTER_LIST])
            ->pluck('id'));

        $user = User::factory()->create();
        $user->roles()->sync([$listOnlyRole->id]);

        $this->actingAs($user)
            ->get(route('pendaftaran.rawat-inap.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pendaftaran/rawat-inap')
                ->where('canRegister', false)
                ->where('canOpen', false));
    }

    public function test_inpatient_registration_rolls_back_when_audit_write_fails(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $recorder = Mockery::mock(AuditRecorder::class);
        $expectation = $recorder->shouldReceive('record');
        $this->assertInstanceOf(CompositeExpectation::class, $expectation);
        $expectation->andReturn(null);
        $this->app->instance(AuditRecorder::class, $recorder);

        $this->actingAs($registrar)
            ->post(route('pendaftaran.rawat-inap.store'), $this->registrationPayload())
            ->assertStatus(503);

        $this->assertDatabaseMissing('patients', ['full_name' => 'PASIEN RI SINTETIS']);
        $this->assertDatabaseCount('encounters', 0);
        $this->assertDatabaseMissing('audit_events', ['action' => 'patient.register']);
        $this->assertDatabaseCount('daily_queue_counters', 0);
        $this->assertDatabaseCount('inpatient_bed_claim_mutexes', 0);
    }

    public function test_second_admit_same_bed_blocked_while_first_open(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $ward = InpatientMastersSeeder::wardsCatalogue()[0];
        $bed = $ward['beds'][0];

        $this->actingAs($registrar)
            ->post(route('pendaftaran.rawat-inap.store'), $this->registrationPayload([
                'full_name' => 'Pasien RI Pertama',
                'nik' => '3174011555900003',
            ]));

        $this->actingAs($registrar)
            ->post(route('pendaftaran.rawat-inap.store'), $this->registrationPayload([
                'full_name' => 'Pasien RI Kedua',
                'nik' => '3174011555900004',
                'bed_code' => $bed,
            ]))
            ->assertRedirect(route('pendaftaran.rawat-inap.index'))
            ->assertSessionHasErrors('bed_code');

        $this->assertSame(1, Encounter::query()
            ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->where('bed_code', $bed)
            ->where('status', '!=', Encounter::STATUS_CLOSED)
            ->count());
        $this->assertDatabaseMissing('patients', ['full_name' => 'PASIEN RI KEDUA']);
        $this->assertDatabaseHas('daily_queue_counters', [
            'queue_date' => now()->toDateString(),
            'last_number' => 1,
        ]);
        $this->assertDatabaseHas('inpatient_bed_claim_mutexes', [
            'bed_code' => $bed,
        ]);
    }

    public function test_clinician_can_list_open_and_write_note(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);

        $this->actingAs($registrar)
            ->post(route('pendaftaran.rawat-inap.store'), $this->registrationPayload());

        $encounter = Encounter::query()
            ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->firstOrFail();

        $this->actingAs($nurse)
            ->get(route('pemeriksaan.rawat-inap.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pemeriksaan/rawat-jalan/index')
                ->where('variant', 'rawat-inap')
                ->has('encounters', 1));

        $this->actingAs($nurse)
            ->get(route('pemeriksaan.rawat-inap.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pemeriksaan/rawat-inap/show')
                ->where('variant', 'rawat-inap'));

        $this->actingAs($nurse)
            ->post(route('pemeriksaan.rawat-inap.documents.draft', [$encounter, InpatientClinicalDocument::TYPE_NURSING_DAILY]), [
                'definition_version' => InpatientClinicalDocument::DEFINITION_VERSION,
                'expected_version' => 0,
                'idempotency_key' => 'flow-nursing-draft-0001',
                'fields' => [
                    'nursing_observation' => 'Observasi awal rawat inap',
                    'nursing_intervention' => 'Pemantauan',
                    'nursing_evaluation' => 'Stabil',
                ],
            ])
            ->assertRedirect(route('pemeriksaan.rawat-inap.show', $encounter));
        $this->assertSame(Encounter::STATUS_REGISTERED, $encounter->fresh()->status);

        $this->actingAs($nurse)
            ->post(route('pemeriksaan.rawat-inap.documents.finalize', [$encounter, InpatientClinicalDocument::TYPE_NURSING_DAILY]), [
                'definition_version' => InpatientClinicalDocument::DEFINITION_VERSION,
                'expected_version' => 1,
                'idempotency_key' => 'flow-nursing-final-0001',
            ])->assertRedirect(route('pemeriksaan.rawat-inap.show', $encounter));

        $encounter->refresh();
        $this->assertSame(Encounter::STATUS_IN_EXAMINATION, $encounter->status);

        $this->assertDatabaseHas('inpatient_clinical_documents', [
            'encounter_id' => $encounter->id,
            'document_type' => InpatientClinicalDocument::TYPE_NURSING_DAILY,
            'document_state' => InpatientClinicalDocument::STATE_FINAL,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'clinical.inpatient.nursing.finalize',
            'resource_type' => 'inpatient_clinical_document',
            'outcome' => 'SUCCESS',
        ]);
    }

    public function test_inpatient_clinical_note_rolls_back_when_audit_write_fails(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);

        $this->actingAs($registrar)
            ->post(route('pendaftaran.rawat-inap.store'), $this->registrationPayload())
            ->assertRedirect(route('pendaftaran.rawat-inap.index'));

        $encounter = Encounter::query()
            ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->firstOrFail();

        $recorder = Mockery::mock(AuditRecorder::class);
        $expectation = $recorder->shouldReceive('record');
        $this->assertInstanceOf(CompositeExpectation::class, $expectation);
        $expectation->andReturn(null);
        $this->app->instance(AuditRecorder::class, $recorder);

        $this->actingAs($nurse)
            ->post(route('pemeriksaan.rawat-inap.documents.draft', [$encounter, InpatientClinicalDocument::TYPE_NURSING_DAILY]), [
                'definition_version' => InpatientClinicalDocument::DEFINITION_VERSION,
                'expected_version' => 0,
                'idempotency_key' => 'flow-audit-fail-0001',
                'fields' => ['additional_notes' => 'Catatan yang wajib dibatalkan'],
            ])
            ->assertStatus(500);

        $this->assertDatabaseCount('inpatient_clinical_documents', 0);
        $this->assertSame(Encounter::STATUS_REGISTERED, $encounter->fresh()->status);
        $this->assertDatabaseMissing('audit_events', ['action' => 'clinical.inpatient.nursing.draft.save']);
    }

    public function test_physician_uses_discharge_summary_http_contract_without_discharging_episode_or_releasing_bed(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $otherPhysician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);

        $this->actingAs($registrar)
            ->post(route('pendaftaran.rawat-inap.store'), $this->registrationPayload())
            ->assertRedirect(route('pendaftaran.rawat-inap.index'));
        $encounter = Encounter::query()->where('care_setting', Encounter::CARE_SETTING_INPATIENT)->firstOrFail();
        $bedId = $encounter->inpatient_bed_id;
        $fields = [
            'admission_reason' => 'Pneumonia komunitas.',
            'significant_findings' => 'Infiltrat paru kanan, saturasi membaik.',
            'care_and_treatment_summary' => 'Antibiotik dan terapi suportif.',
            'condition_at_discharge' => 'Stabil dan dapat beraktivitas ringan.',
            'follow_up_plan' => 'Kontrol poliklinik dalam tujuh hari.',
        ];

        $this->actingAs($nurse)
            ->post(route('pemeriksaan.rawat-inap.discharge-summary.draft', $encounter), [
                'definition_version' => InpatientDischargeSummary::DEFINITION_VERSION,
                'expected_version' => 0,
                'fields' => $fields,
                'idempotency_key' => 'http-discharge-denied-0001',
            ])
            ->assertForbidden();

        $this->actingAs($physician)
            ->from(route('pemeriksaan.rawat-inap.show', $encounter))
            ->post(route('pemeriksaan.rawat-inap.discharge-summary.draft', $encounter), [
                'definition_version' => InpatientDischargeSummary::DEFINITION_VERSION,
                'expected_version' => 0,
                'fields' => [...$fields, 'admission_reason' => null],
                'idempotency_key' => 'http-discharge-null-0001',
            ])
            ->assertRedirect(route('pemeriksaan.rawat-inap.show', $encounter))
            ->assertSessionHasErrors('fields.admission_reason');
        $this->assertDatabaseCount('inpatient_discharge_summaries', 0);

        $this->actingAs($physician)
            ->post(route('pemeriksaan.rawat-inap.discharge-summary.draft', $encounter), [
                'definition_version' => InpatientDischargeSummary::DEFINITION_VERSION,
                'expected_version' => 0,
                'fields' => $fields,
                'idempotency_key' => 'http-discharge-draft-0001',
            ])
            ->assertRedirect(route('pemeriksaan.rawat-inap.show', $encounter))
            ->assertSessionHas('success', 'Draf ringkasan pulang disimpan.');

        $staleMessage = 'Ringkasan pulang telah berubah. Muat ulang sebelum melanjutkan.';
        $this->actingAs($physician)
            ->from(route('pemeriksaan.rawat-inap.show', $encounter))
            ->withHeader('X-Inertia', 'true')
            ->post(route('pemeriksaan.rawat-inap.discharge-summary.draft', $encounter), [
                'definition_version' => InpatientDischargeSummary::DEFINITION_VERSION,
                'expected_version' => 0,
                'fields' => $fields,
                'idempotency_key' => 'http-discharge-stale-inertia-0001',
            ])
            ->assertRedirect(route('pemeriksaan.rawat-inap.show', $encounter))
            ->assertSessionHasErrors(['discharge_summary' => $staleMessage]);
        $this->withoutHeader('X-Inertia');
        $this->actingAs($physician)
            ->post(route('pemeriksaan.rawat-inap.discharge-summary.draft', $encounter), [
                'definition_version' => InpatientDischargeSummary::DEFINITION_VERSION,
                'expected_version' => 0,
                'fields' => $fields,
                'idempotency_key' => 'http-discharge-stale-http-0001',
            ])
            ->assertStatus(422);
        $this->assertSame(1, InpatientDischargeSummary::query()->firstOrFail()->version);

        $this->actingAs($physician)
            ->get(route('pemeriksaan.rawat-inap.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pemeriksaan/rawat-inap/show')
                ->where('discharge_summary.definition_version', InpatientDischargeSummary::DEFINITION_VERSION)
                ->where('discharge_summary.summary.state', InpatientDischargeSummary::STATE_DRAFT)
                ->where('discharge_summary.summary.version', 1)
                ->where('discharge_summary.summary.fields.admission_reason', $fields['admission_reason'])
                ->where('discharge_summary.summary.assigned_physician.public_id', $physician->public_id)
                ->where('discharge_summary.permission.can_save_draft', true)
                ->where('discharge_summary.permission.can_finalize', true)
                ->has('discharge_summary.versions', 1)
                ->where('discharge_summary.versions.0.version', 1)
                ->where('discharge_summary.versions.0.actor_name', $physician->name));

        $this->actingAs($otherPhysician)
            ->get(route('pemeriksaan.rawat-inap.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('discharge_summary.permission.can_save_draft', false)
                ->where('discharge_summary.permission.can_finalize', false)
                ->where('discharge_summary.actions.save_draft_url', null)
                ->where('discharge_summary.actions.finalize_url', null));

        $this->actingAs($physician)
            ->from(route('pemeriksaan.rawat-inap.show', $encounter))
            ->post(route('pemeriksaan.rawat-inap.discharge-summary.finalize', $encounter), [
                'definition_version' => InpatientDischargeSummary::DEFINITION_VERSION,
                'expected_version' => 1,
                'idempotency_key' => 'http-discharge-final-0001',
                'fields' => ['follow_up_plan' => 'Pengganti yang tidak boleh diterima.'],
            ])
            ->assertRedirect(route('pemeriksaan.rawat-inap.show', $encounter))
            ->assertSessionHasErrors('documentation');
        $this->assertSame(1, InpatientDischargeSummary::query()->firstOrFail()->version);

        $this->actingAs($physician)
            ->post(route('pemeriksaan.rawat-inap.discharge-summary.finalize', $encounter), [
                'definition_version' => InpatientDischargeSummary::DEFINITION_VERSION,
                'expected_version' => 1,
                'idempotency_key' => 'http-discharge-final-0001',
            ])
            ->assertRedirect(route('pemeriksaan.rawat-inap.show', $encounter))
            ->assertSessionHas('success', 'Ringkasan pulang dijadikan Final.');

        $summary = InpatientDischargeSummary::query()->with('versions')->firstOrFail();
        $this->assertSame(InpatientDischargeSummary::STATE_FINAL, $summary->summary_state);
        $this->assertSame(2, $summary->version);
        $this->assertSame([1, 2], $summary->versions->sortBy('version')->pluck('version')->values()->all());
        $this->assertSame($fields['follow_up_plan'], $summary->follow_up_plan);
        $this->assertSame(Encounter::STATUS_REGISTERED, $encounter->fresh()->status);
        $this->assertSame($bedId, $encounter->fresh()->inpatient_bed_id);
    }

    public function test_unauthorized_actor_is_denied_before_closed_ri_state_is_disclosed(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $encounter = Encounter::factory()->create([
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'status' => Encounter::STATUS_CLOSED,
        ]);

        $this->actingAs($registrar)
            ->post(route('pemeriksaan.rawat-inap.documents.draft', [$encounter, InpatientClinicalDocument::TYPE_NURSING_DAILY]), [
                'definition_version' => InpatientClinicalDocument::DEFINITION_VERSION,
                'expected_version' => 0,
                'idempotency_key' => 'closed-denied-0001',
                'fields' => [],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('clinical_entries', 0);
    }

    public function test_unauthorized_actor_is_denied_before_opposite_care_setting_is_disclosed(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $encounter = Encounter::factory()->create([
            'care_setting' => Encounter::CARE_SETTING_EMERGENCY,
            'status' => Encounter::STATUS_REGISTERED,
        ]);

        $this->actingAs($registrar)
            ->post(route('pemeriksaan.rawat-inap.documents.draft', [$encounter, InpatientClinicalDocument::TYPE_NURSING_DAILY]), [
                'definition_version' => InpatientClinicalDocument::DEFINITION_VERSION,
                'expected_version' => 0,
                'idempotency_key' => 'setting-denied-0001',
                'fields' => [],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('clinical_entries', 0);
        $this->assertSame(Encounter::STATUS_REGISTERED, $encounter->fresh()->status);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'authorization.denied',
            'resource_type' => 'http_route',
            'outcome' => 'DENIED',
            'reason' => 'authorization_check_failed',
        ]);
    }

    public function test_non_registrar_forbidden_on_inpatient_store(): void
    {
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);

        $this->actingAs($nurse)
            ->post(route('pendaftaran.rawat-inap.store'), $this->registrationPayload([
                'full_name' => 'Tidak Diizinkan RI',
            ]))
            ->assertForbidden();
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
