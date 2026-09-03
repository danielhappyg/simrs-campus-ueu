<?php

namespace Tests\Feature\Outpatient;

use App\Models\Clinic;
use App\Models\ClinicSchedule;
use App\Models\Doctor;
use App\Models\Encounter;
use App\Models\LabDiagnosticResult;
use App\Models\LabServiceRequest;
use App\Models\OutpatientClinicalDocument;
use App\Models\OutpatientRmCompletenessReview;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditActorAttribution;
use App\Support\Audit\AuditEvent;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Clinical\OutpatientLabLifecycle;
use App\Support\Clinical\OutpatientRmCompletenessService;
use Database\Seeders\OutpatientMastersSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContinuousOutpatientTeachingJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(OutpatientMastersSeeder::class);
    }

    public function test_distinct_actors_complete_one_synthetic_outpatient_journey_through_public_routes(): void
    {
        $registrar = $this->userWithRole('Registrar Sintetis', RoleCapabilityMatrix::ROLE_REGISTRAR);
        $nurse = $this->userWithRole('Perawat Sintetis', RoleCapabilityMatrix::ROLE_NURSE);
        $physician = $this->userWithRole('Dokter Sintetis', RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $rmik = $this->userWithRole('Petugas RMIK Sintetis', RoleCapabilityMatrix::ROLE_RMIK);
        [$clinic, $doctor, $schedule] = $this->masterChain();
        $visitDate = now()->toDateString();

        $this->actingAs($registrar)
            ->post(route('pendaftaran.rawat-jalan.store'), [
                'full_name' => 'Pasien Alur RJ Sintetis',
                'date_of_birth' => '1993-07-21',
                'sex' => Patient::SEX_PEREMPUAN,
                'nik' => '3174016107939001',
                'religion' => 'ISLAM',
                'marital_status' => Patient::MARITAL_KAWIN,
                'ethnicity' => 'JAWA',
                'language' => 'INDONESIA',
                'clinic_public_id' => $clinic->public_id,
                'doctor_public_id' => $doctor->public_id,
                'schedule_public_id' => $schedule->public_id,
                'visit_date' => $visitDate,
                'admission_mode' => Encounter::ADMISSION_DATANG_SENDIRI,
                'payer_type' => Encounter::PAYER_UMUM,
                'booking_code' => 'SYNTH-RJ-CONTINUOUS-001',
                'chief_complaint' => 'Cepat lelah pada kasus pengajaran sintetis.',
                'is_synthetic' => true,
            ])
            ->assertRedirect(route('pendaftaran.rawat-jalan.index'))
            ->assertSessionHas('last_encounter_public_id');

        $patient = Patient::query()->where('nik', '3174016107939001')->sole();
        $encounter = Encounter::query()->where('patient_id', $patient->id)->sole();
        $this->assertTrue($patient->is_synthetic);
        $this->assertSame($registrar->id, $patient->created_by_user_id);
        $this->assertSame($registrar->id, $encounter->registered_by_user_id);
        $this->assertSame(Encounter::CARE_SETTING_OUTPATIENT, $encounter->care_setting);
        $this->assertSame(Encounter::STATUS_REGISTERED, $encounter->status);
        $this->assertAttributedAudit('patient.register', $registrar, 'SUCCESS', null, $encounter->public_id);

        $nursingDraftUrl = route('pemeriksaan.rawat-jalan.documents.draft', [
            $encounter,
            OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT,
        ]);
        $nursingFinalUrl = route('pemeriksaan.rawat-jalan.documents.final', [
            $encounter,
            OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT,
        ]);
        $this->actingAs($nurse)->post($nursingDraftUrl, [
            'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
            'expected_version' => 0,
            'fields' => [
                'nursing_assessment' => 'Kesadaran baik, tanda vital stabil, risiko jatuh rendah.',
                'additional_notes' => 'Asesmen awal untuk pembelajaran.',
            ],
        ])->assertRedirect(route('pemeriksaan.rawat-jalan.show', $encounter));
        $this->actingAs($nurse)->post($nursingFinalUrl, [
            'expected_version' => 1,
        ])->assertRedirect(route('pemeriksaan.rawat-jalan.show', $encounter));

        $nursing = $this->document($encounter, OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT);
        $this->assertSame(OutpatientClinicalDocument::STATE_FINAL, $nursing->document_state);
        $this->assertSame(2, $nursing->version);
        $this->assertSame($nurse->id, $nursing->author_user_id);
        $this->assertSame($nurse->id, $nursing->finalized_by_user_id);
        $this->assertSame(Encounter::STATUS_IN_EXAMINATION, $encounter->fresh()->status);
        $this->assertSame([1, 2], $nursing->versions()->orderBy('version')->pluck('version')->all());
        $this->assertSame(
            [OutpatientClinicalDocument::STATE_DRAFT, OutpatientClinicalDocument::STATE_FINAL],
            $nursing->versions()->orderBy('version')->pluck('document_state')->all(),
        );
        $this->assertSame([$nurse->id, $nurse->id], $nursing->versions()->orderBy('version')->pluck('actor_user_id')->all());
        $this->assertAttributedAudit('clinical.nursing.draft.save', $nurse, 'SUCCESS', null, $nursing->public_id);
        $this->assertAttributedAudit('clinical.nursing.finalize', $nurse, 'SUCCESS', null, $nursing->public_id);

        $medicalDraftUrl = route('pemeriksaan.rawat-jalan.documents.draft', [
            $encounter,
            OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT,
        ]);
        $medicalFinalUrl = route('pemeriksaan.rawat-jalan.documents.final', [
            $encounter,
            OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT,
        ]);
        $medicalFields = [
            'anamnesis' => 'Cepat lelah selama tiga hari tanpa sesak.',
            'objective_examination' => 'Keadaan umum baik; konjungtiva sedikit pucat.',
            'clinical_assessment' => 'Suspek anemia ringan pada skenario sintetis.',
            'care_plan' => 'Periksa hemoglobin dan evaluasi setelah hasil final.',
        ];
        $this->actingAs($physician)->post($medicalDraftUrl, [
            'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
            'expected_version' => 0,
            'fields' => $medicalFields,
        ])->assertRedirect(route('pemeriksaan.rawat-jalan.show', $encounter));
        $this->actingAs($physician)->post($medicalFinalUrl, [
            'expected_version' => 1,
        ])->assertRedirect(route('pemeriksaan.rawat-jalan.show', $encounter));

        $medical = $this->document($encounter, OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT);
        $this->assertSame(OutpatientClinicalDocument::STATE_FINAL, $medical->document_state);
        $this->assertSame(2, $medical->version);
        $this->assertSame($physician->id, $medical->author_user_id);
        $this->assertSame($physician->id, $medical->finalized_by_user_id);
        $this->assertSame(Encounter::STATUS_READY_FOR_RM, $encounter->fresh()->status);
        $this->assertSame([1, 2], $medical->versions()->orderBy('version')->pluck('version')->all());
        $this->assertSame(
            [OutpatientClinicalDocument::STATE_DRAFT, OutpatientClinicalDocument::STATE_FINAL],
            $medical->versions()->orderBy('version')->pluck('document_state')->all(),
        );
        $this->assertSame([$physician->id, $physician->id], $medical->versions()->orderBy('version')->pluck('actor_user_id')->all());
        $this->assertAttributedAudit('clinical.medical.draft.save', $physician, 'SUCCESS', null, $medical->public_id);
        $this->assertAttributedAudit('clinical.medical.finalize', $physician, 'SUCCESS', null, $medical->public_id);

        $order = app(OutpatientLabLifecycle::class)->createLabOrder(
            $encounter,
            $physician,
            ['code' => 'HB', 'label' => 'Hemoglobin'],
            'Apakah hemoglobin mendukung anemia pada kasus sintetis?',
        );
        $this->assertSame(LabServiceRequest::STATUS_ACTIVE, $order->status);
        $this->assertNull($order->result);
        $this->assertSame($physician->id, $order->requested_by_user_id);
        $this->assertAttributedAudit('clinical.lab.order.create', $physician, 'SUCCESS', null, $order->public_id);

        $activeSnapshot = app(OutpatientRmCompletenessService::class)->snapshot($encounter->fresh());
        $this->assertContains('NO_ACTIVE_LAB_ORDERS', $activeSnapshot['blockers']);

        // A direct sign-off has no reviewed version to consume and must fail closed.
        $this->actingAs($rmik)
            ->post(route('rm.rawat-jalan.signoff', $encounter), [
                'expected_version' => 1,
                'source_fingerprint' => $activeSnapshot['source_fingerprint'],
            ])
            ->assertStatus(422);
        $this->assertDatabaseCount('outpatient_rm_completeness_reviews', 0);
        $this->assertSame(Encounter::STATUS_READY_FOR_RM, $encounter->fresh()->status);
        $this->assertSame(LabServiceRequest::STATUS_ACTIVE, $order->fresh()->status);
        $this->assertAttributedAudit('rmik.completeness.signoff', $rmik, 'DENIED', 'stale_version', $encounter->public_id);

        $this->actingAs($rmik)
            ->post(route('rm.rawat-jalan.reviews.store', $encounter), [
                'expected_version' => 0,
                'source_fingerprint' => $activeSnapshot['source_fingerprint'],
            ])
            ->assertRedirect(route('rm.rawat-jalan.show', $encounter));

        $draftReview = OutpatientRmCompletenessReview::query()
            ->where('encounter_id', $encounter->id)
            ->where('version', 1)
            ->sole();
        $reviewCountBeforeDeniedSignoff = OutpatientRmCompletenessReview::query()->count();
        $itemCountBeforeDeniedSignoff = $draftReview->items()->count();

        $this->actingAs($rmik)
            ->post(route('rm.rawat-jalan.signoff', $encounter), [
                'expected_version' => 1,
                'source_fingerprint' => $activeSnapshot['source_fingerprint'],
            ])
            ->assertStatus(422);

        $this->assertSame($reviewCountBeforeDeniedSignoff, OutpatientRmCompletenessReview::query()->count());
        $this->assertSame($itemCountBeforeDeniedSignoff, $draftReview->items()->count());
        $this->assertDatabaseMissing('outpatient_rm_completeness_reviews', [
            'encounter_id' => $encounter->id,
            'review_state' => OutpatientRmCompletenessReview::STATE_SIGNED_OFF,
        ]);
        $this->assertSame(Encounter::STATUS_READY_FOR_RM, $encounter->fresh()->status);
        $this->assertSame(LabServiceRequest::STATUS_ACTIVE, $order->fresh()->status);
        $this->assertNull($order->fresh()->result);
        $this->assertAttributedAudit('rmik.completeness.review.save', $rmik, 'SUCCESS', null, $draftReview->public_id);
        $this->assertAttributedAudit('rmik.completeness.signoff', $rmik, 'DENIED', 'active_lab_orders', $encounter->public_id);

        app(OutpatientLabLifecycle::class)->writeFinalLabResult(
            $order,
            $nurse,
            'Hemoglobin 12,4 g/dL; hasil final sintetis.',
        );

        $order->refresh()->load('result');
        $this->assertSame(LabServiceRequest::STATUS_COMPLETED, $order->status);
        $this->assertSame(LabDiagnosticResult::STATUS_FINAL, $order->result?->status);
        $this->assertSame($nurse->id, $order->result?->entered_by_user_id);
        $this->assertAttributedAudit('clinical.lab.result.write', $nurse, 'SUCCESS', null, $order->public_id);

        $freshSnapshot = app(OutpatientRmCompletenessService::class)->snapshot($encounter->fresh());
        $this->assertSame([], $freshSnapshot['blockers']);
        $this->assertNotSame($activeSnapshot['source_fingerprint'], $freshSnapshot['source_fingerprint']);

        $this->actingAs($rmik)
            ->post(route('rm.rawat-jalan.reviews.store', $encounter), [
                'expected_version' => 1,
                'source_fingerprint' => $freshSnapshot['source_fingerprint'],
            ])
            ->assertRedirect(route('rm.rawat-jalan.show', $encounter));
        $freshReview = OutpatientRmCompletenessReview::query()
            ->where('encounter_id', $encounter->id)
            ->where('version', 2)
            ->sole();

        $this->actingAs($rmik)
            ->post(route('rm.rawat-jalan.signoff', $encounter), [
                'expected_version' => 2,
                'source_fingerprint' => $freshSnapshot['source_fingerprint'],
            ])
            ->assertRedirect(route('rm.rawat-jalan.show', $encounter));

        $signedReview = OutpatientRmCompletenessReview::query()
            ->where('encounter_id', $encounter->id)
            ->where('version', 3)
            ->sole();
        $this->assertSame(OutpatientRmCompletenessReview::STATE_SIGNED_OFF, $signedReview->review_state);
        $this->assertSame($rmik->id, $signedReview->reviewed_by_user_id);
        $this->assertSame($rmik->id, $signedReview->signed_off_by_user_id);
        $this->assertSame(Encounter::STATUS_CLOSED, $encounter->fresh()->status);
        $this->assertAttributedAudit('rmik.completeness.review.save', $rmik, 'SUCCESS', null, $freshReview->public_id);
        $this->assertAttributedAudit('rmik.completeness.signoff', $rmik, 'SUCCESS', null, $signedReview->public_id);

        $this->actingAs($physician)
            ->get(route('pemeriksaan.rawat-jalan.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('encounter.status', Encounter::STATUS_CLOSED)
                ->missing('encounter.lab_orders')
                ->where('laboratory.orders.0.source', 'LEGACY_READ_ONLY')
                ->where('laboratory.orders.0.state', 'REPORTED_VERIFIED')
                ->where('laboratory.orders.0.result.author_name', $nurse->name)
                ->where('permissions.nursing.can_save_draft', false)
                ->where('permissions.nursing.can_finalize', false)
                ->where('permissions.medical.can_save_draft', false)
                ->where('permissions.medical.can_finalize', false)
                ->where('permissions.can_create_lab_order', false));
        $this->actingAs($rmik)
            ->get(route('rm.rawat-jalan.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('encounter.status', Encounter::STATUS_CLOSED)
                ->where('review.status', 'SIGNED_OFF')
                ->where('review.signed_off_by_name', $rmik->name)
                ->where('permissions.can_save_review', false)
                ->where('permissions.can_signoff', false));

        $medicalBeforeClosedWrite = $medical->fresh();
        $this->assertNotNull($medicalBeforeClosedWrite);
        $medicalStateBeforeClosedWrite = [
            'document_state' => $medicalBeforeClosedWrite->document_state,
            'version' => $medicalBeforeClosedWrite->version,
            'fields' => $medicalBeforeClosedWrite->fields,
            'finalized_by_user_id' => $medicalBeforeClosedWrite->finalized_by_user_id,
            'finalized_at' => $medicalBeforeClosedWrite->finalized_at?->toIso8601String(),
        ];
        $this->actingAs($physician)->post($medicalDraftUrl, [
            'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
            'expected_version' => 2,
            'fields' => [...$medicalFields, 'additional_notes' => 'Percobaan perubahan setelah ditutup.'],
        ])->assertStatus(422);
        $medicalAfterClosedWrite = $medical->fresh();
        $this->assertNotNull($medicalAfterClosedWrite);
        $this->assertSame($medicalStateBeforeClosedWrite, [
            'document_state' => $medicalAfterClosedWrite->document_state,
            'version' => $medicalAfterClosedWrite->version,
            'fields' => $medicalAfterClosedWrite->fields,
            'finalized_by_user_id' => $medicalAfterClosedWrite->finalized_by_user_id,
            'finalized_at' => $medicalAfterClosedWrite->finalized_at?->toIso8601String(),
        ]);
        $this->assertAttributedAudit('clinical.medical.draft.save', $physician, 'DENIED', 'encounter_closed', $encounter->public_id);

        $this->actingAs($registrar)
            ->get(route('pendaftaran.kunjungan.cetak', $encounter).'?docs=bukti,antrian')
            ->assertOk()
            ->assertSee('Bukti pendaftaran', false)
            ->assertDontSee('Dokumen pengajaran', false)
            ->assertDontSee('data sintetis', false)
            ->assertSee($patient->full_name, false)
            ->assertSee($patient->medical_record_number, false)
            ->assertSee($encounter->public_id, false);
        $this->actingAs($registrar)
            ->get(route('pendaftaran.rekap', [
                'origin' => 'ONLINE',
                'date_from' => $visitDate,
                'date_to' => $visitDate,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('totals.all', 1)
                ->where('totals.online', 1)
                ->where('rows.0.public_id', $encounter->public_id)
                ->where('rows.0.status', Encounter::STATUS_CLOSED)
                ->where('rows.0.patient.full_name', $patient->full_name)
                ->where('rows.0.booking_code', 'SYNTH-RJ-CONTINUOUS-001'));
        $this->assertAttributedAudit('encounter.print', $registrar, 'SUCCESS', null, $encounter->public_id);

        $this->assertSame(2, AuditEvent::query()->where('action', 'rmik.completeness.review.save')->count());
        $this->assertSame(3, AuditEvent::query()->where('action', 'rmik.completeness.signoff')->count());
        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'clinical.medical.draft.save')
            ->where('outcome', 'DENIED')
            ->where('reason', 'encounter_closed')
            ->count());
    }

    /** @return array{Clinic, Doctor, ClinicSchedule} */
    private function masterChain(): array
    {
        $clinic = Clinic::query()->orderBy('id')->firstOrFail();
        $doctor = Doctor::query()
            ->where('clinic_id', $clinic->id)
            ->orderBy('id')
            ->firstOrFail();
        $schedule = ClinicSchedule::query()
            ->where('clinic_id', $clinic->id)
            ->where('doctor_id', $doctor->id)
            ->orderBy('id')
            ->firstOrFail();

        return [$clinic, $doctor, $schedule];
    }

    private function userWithRole(string $name, string $roleSlug): User
    {
        $user = User::factory()->create(['name' => $name]);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }

    private function document(Encounter $encounter, string $documentType): OutpatientClinicalDocument
    {
        return OutpatientClinicalDocument::query()
            ->where('encounter_id', $encounter->id)
            ->where('document_type', $documentType)
            ->sole();
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
