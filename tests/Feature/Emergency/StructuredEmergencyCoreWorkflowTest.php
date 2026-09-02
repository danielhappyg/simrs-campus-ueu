<?php

namespace Tests\Feature\Emergency;

use App\Models\ClinicalEntry;
use App\Models\EmergencyTriageVocabulary;
use App\Models\Encounter;
use App\Models\LaboratoryExaminationMaster;
use App\Models\Patient;
use App\Models\PharmacyDepot;
use App\Models\PharmacyPrescription;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Emergency\EmergencyCanonicalJson;
use App\Support\Emergency\EmergencyDenied;
use App\Support\Emergency\EmergencyDiagnosticFollowUpService;
use App\Support\Emergency\EmergencyDispositionService;
use App\Support\Emergency\EmergencyDocumentationService;
use App\Support\Emergency\EmergencyEvidenceFingerprint;
use App\Support\Emergency\EmergencyProjection;
use App\Support\Emergency\EmergencyTriageService;
use App\Support\Laboratory\LaboratoryMutationScope;
use App\Support\Laboratory\LaboratoryWorkflowService;
use App\Support\Pharmacy\PharmacyMutationScope;
use Database\Seeders\EmergencyTriageVocabularySeeder;
use Database\Seeders\LaboratoryMastersSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StructuredEmergencyCoreWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $nurse;

    private User $physician;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN, EmergencyTriageVocabularySeeder::ACTOR_EMAIL);
        $this->nurse = $this->actor(RoleCapabilityMatrix::ROLE_NURSE);
        $this->physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $this->seed(EmergencyTriageVocabularySeeder::class);
    }

    public function test_initial_triage_reassessment_and_documents_are_attributable_version_chains(): void
    {
        $encounter = $this->encounter();
        $vocabulary = EmergencyTriageVocabulary::query()->sole();
        $triage = app(EmergencyTriageService::class);
        $initial = $triage->finalizeInitial($encounter->public_id, $vocabulary->public_id, 1, $this->nurse, $this->triagePayload('KUNING'), 'triage-initial-0001');

        $this->assertFalse($initial->replayed);
        $this->assertSame(Encounter::STATUS_IN_EXAMINATION, $encounter->fresh()->status);
        $this->assertSame(1, $initial->record->assessment_number);
        $this->assertSame(0, $initial->record->respiratory_rate);
        $this->assertSame('KUNING', $initial->record->category_code);

        $reassessment = $triage->reassess($encounter->public_id, $this->nurse, 1, [...$this->triagePayload('MERAH'), 'reassessment_reason' => 'Kondisi pasien berubah saat observasi.'], 'triage-reassess-0001');
        $this->assertSame(2, $reassessment->record->assessment_number);
        $this->assertSame($initial->record->id, $reassessment->record->prior_assessment_id);
        $this->assertSame($initial->record->content_digest, $reassessment->record->prior_assessment_digest);

        $documents = app(EmergencyDocumentationService::class);
        $nursingDraft = $documents->saveDraft($encounter->public_id, 'NURSING', $this->nurse, 0, $this->nursingFields(), 'nursing-draft-0001');
        $nursingFinal = $documents->finalize($encounter->public_id, 'NURSING', $this->nurse, 1, 'nursing-final-0001');
        $medicalDraft = $documents->saveDraft($encounter->public_id, 'MEDICAL', $this->physician, 0, $this->medicalFields(), 'medical-draft-0001');
        $medicalFinal = $documents->finalize($encounter->public_id, 'MEDICAL', $this->physician, 1, 'medical-final-0001');

        $this->assertSame('DRAFT', $nursingDraft->record->state);
        $this->assertSame('FINAL', $nursingFinal->record->state);
        $this->assertSame($nursingDraft->record->fresh()->fields, $nursingDraft->record->fields);
        $this->assertSame(EmergencyCanonicalJson::digest([$this->nursingFields()]), EmergencyCanonicalJson::digest([$nursingDraft->record->fields]));
        $this->assertSame($nursingDraft->record->fields, $nursingFinal->record->fields);
        $this->assertSame('DRAFT', $medicalDraft->record->state);
        $this->assertSame('FINAL', $medicalFinal->record->state);
        $this->assertDatabaseCount('emergency_clinical_document_versions', 4);
    }

    public function test_same_key_replays_and_changed_payload_conflicts(): void
    {
        $encounter = $this->encounter();
        $vocabulary = EmergencyTriageVocabulary::query()->sole();
        $service = app(EmergencyTriageService::class);
        $payload = $this->triagePayload();
        $first = $service->finalizeInitial($encounter->public_id, $vocabulary->public_id, 1, $this->nurse, $payload, 'triage-replay-0001');
        $replay = $service->finalizeInitial($encounter->public_id, $vocabulary->public_id, 1, $this->nurse, $payload, 'triage-replay-0001');

        $this->assertTrue($replay->replayed);
        $this->assertSame($first->record->public_id, $replay->record->public_id);
        $this->assertDatabaseCount('emergency_triage_assessments', 1);

        try {
            $service->finalizeInitial($encounter->public_id, $vocabulary->public_id, 1, $this->nurse, $this->triagePayload('MERAH'), 'triage-replay-0001');
            $this->fail('Changed payload reuse must fail.');
        } catch (EmergencyDenied $denial) {
            $this->assertSame('idempotency_conflict', $denial->reason);
        }
    }

    public function test_observation_missingness_late_entry_and_stale_vocabulary_fail_closed(): void
    {
        $encounter = $this->encounter(['registered_at' => now()->subHour()]);
        $vocabulary = EmergencyTriageVocabulary::query()->sole();
        $payload = $this->triagePayload();
        $payload['observed_at'] = now()->subMinutes(20)->toIso8601String();
        $payload['vitals']['systolic_pressure'] = null;
        $payload['vitals']['diastolic_pressure'] = null;
        $payload['unobtainable_fields'] = ['systolic_pressure', 'diastolic_pressure'];
        $payload['unobtainable_reasons'] = ['systolic_pressure' => 'Manset tidak sesuai.', 'diastolic_pressure' => 'Manset tidak sesuai.'];

        try {
            app(EmergencyTriageService::class)->finalizeInitial($encounter->public_id, $vocabulary->public_id, 1, $this->nurse, $payload, 'triage-late-0001');
            $this->fail('Late entry reason must be required.');
        } catch (EmergencyDenied $denial) {
            $this->assertSame('late_entry_reason_required', $denial->reason);
        }
        $payload['late_entry_reason'] = 'Dokumentasi dilakukan setelah stabilisasi awal.';
        $result = app(EmergencyTriageService::class)->finalizeInitial($encounter->public_id, $vocabulary->public_id, 1, $this->nurse, $payload, 'triage-late-0002');
        $this->assertSame(['systolic_pressure', 'diastolic_pressure'], $result->record->unobtainable_fields);

        $second = $this->encounter();
        try {
            app(EmergencyTriageService::class)->finalizeInitial($second->public_id, $vocabulary->public_id, 999, $this->nurse, $this->triagePayload(), 'triage-stale-vocabulary-0001');
            $this->fail('Stale vocabulary must fail.');
        } catch (EmergencyDenied $denial) {
            $this->assertSame('stale_version', $denial->reason);
        }
    }

    public function test_exact_role_is_checked_before_resource_lookup(): void
    {
        try {
            app(EmergencyTriageService::class)->finalizeInitial('01ARZ3NDEKTSV4RRFFQ69G5FAV', EmergencyTriageVocabulary::query()->sole()->public_id, 1, $this->physician, $this->triagePayload(), 'triage-wrong-role-0001');
            $this->fail('Physician must not write triage.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('audit_events', ['action' => 'emergency.workflow.mutate', 'outcome' => 'DENIED', 'reason' => 'role_not_permitted']);
            $this->assertDatabaseMissing('audit_events', ['action' => 'emergency.workflow.mutate', 'reason' => 'resource_not_found']);
        }

        $mixed = $this->actor(RoleCapabilityMatrix::ROLE_NURSE);
        $mixed->roles()->attach(Role::query()->where('slug', RoleCapabilityMatrix::ROLE_PHYSICIAN)->sole());
        try {
            app(EmergencyTriageService::class)->finalizeInitial('01ARZ3NDEKTSV4RRFFQ69G5FAV', EmergencyTriageVocabulary::query()->sole()->public_id, 1, $mixed, $this->triagePayload(), 'triage-mixed-role-0001');
            $this->fail('Mixed role must not bypass triage policy.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('emergency_triage_assessments', 0);
        }
    }

    public function test_all_five_dispositions_are_signed_and_non_inpatient_branches_become_ready_for_rm(): void
    {
        $payloads = [
            'PULANG' => ['condition_at_discharge' => 'Stabil', 'instructions' => 'Istirahat dan minum cukup', 'warning_signs' => 'Kembali bila sesak', 'follow_up_plan' => 'Kontrol klinik'],
            'DIRUJUK' => ['destination' => 'RS Rujukan', 'clinical_reason' => 'Membutuhkan layanan lanjutan', 'transport_plan' => 'Ambulans terencana', 'handoff_note' => 'Rencana lokal; penerimaan eksternal belum diklaim'],
            'RAWAT_INAP' => ['admission_reason' => 'Perlu pemantauan lanjutan', 'receiving_unit_handoff_note' => 'Serah terima ke unit penerima'],
            'MENINGGAL_DI_IGD' => ['event_time' => now()->toIso8601String(), 'clinical_note' => 'Fakta episode dicatat dokter tanpa sertifikasi penyebab.'],
            'DOA' => ['arrival_declaration_time' => now()->toIso8601String(), 'clinical_note' => 'Fakta kedatangan dicatat dokter tanpa sertifikasi penyebab.'],
        ];

        foreach ($payloads as $type => $payload) {
            $encounter = $this->readyEncounter();
            $result = app(EmergencyDispositionService::class)->sign($encounter->public_id, $this->physician, $type, $payload, 'disposition-'.strtolower($type).'-0001');
            $this->assertSame($type, $result->record->disposition_type);
            $expected = $type === 'RAWAT_INAP' ? Encounter::STATUS_IN_EXAMINATION : Encounter::STATUS_READY_FOR_RM;
            $this->assertSame($expected, $encounter->fresh()->status);
        }
        $this->assertDatabaseCount('emergency_dispositions', 5);
    }

    public function test_active_pharmacy_prescription_blocks_emergency_disposition_completion(): void
    {
        $encounter = $this->readyEncounter();
        PharmacyMutationScope::run(function () use ($encounter): void {
            $depot = PharmacyDepot::query()->create([
                'depot_code' => 'DEPO_IGD_DISPOSITION',
                'display_name' => 'Depo IGD Disposisi',
                'eligible_care_settings' => [Encounter::CARE_SETTING_EMERGENCY],
                'state' => PharmacyDepot::ACTIVE,
                'version' => 1,
                'current_content_digest' => str_repeat('a', 64),
            ]);
            PharmacyPrescription::query()->create([
                'encounter_id' => $encounter->id,
                'patient_id' => $encounter->patient_id,
                'ordering_physician_user_id' => $this->physician->id,
                'depot_id' => $depot->id,
                'care_setting' => $encounter->care_setting,
                'encounter_number_snapshot' => $encounter->public_id,
                'location_snapshot' => $encounter->clinic_name,
                'depot_version' => 1,
                'depot_code_snapshot' => $depot->depot_code,
                'status' => PharmacyPrescription::VERIFIED,
                'version' => 1,
                'current_content_digest' => str_repeat('b', 64),
                'ordered_at' => now(),
            ]);
        });

        try {
            app(EmergencyDispositionService::class)->sign(
                $encounter->public_id,
                $this->physician,
                'PULANG',
                [
                    'condition_at_discharge' => 'Stabil',
                    'instructions' => 'Selesaikan resep aktif',
                    'warning_signs' => 'Kembali bila memburuk',
                    'follow_up_plan' => 'Kontrol klinik',
                ],
                'disposition-active-pharmacy-0001',
            );
            $this->fail('Expected active pharmacy prescription denial.');
        } catch (EmergencyDenied $denial) {
            $this->assertSame('active_pharmacy_prescriptions', $denial->reason);
        }

        $this->assertSame(Encounter::STATUS_IN_EXAMINATION, $encounter->fresh()?->status);
        $this->assertDatabaseCount('emergency_dispositions', 0);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'emergency.workflow.mutate',
            'resource_id' => $encounter->public_id,
            'outcome' => 'DENIED',
            'reason' => 'active_pharmacy_prescriptions',
        ]);
    }

    public function test_pre_handoff_correction_is_append_only_and_blocks_ordinary_writes(): void
    {
        $encounter = $this->readyEncounter();
        $dispositions = app(EmergencyDispositionService::class);
        $first = $dispositions->sign($encounter->public_id, $this->physician, 'RAWAT_INAP', ['admission_reason' => 'Observasi', 'receiving_unit_handoff_note' => 'Serah terima'], 'disposition-ri-0002')->record;
        $corrected = $dispositions->correctBeforeHandoff($encounter->public_id, $this->physician, 1, 'PULANG', ['condition_at_discharge' => 'Membaik', 'instructions' => 'Istirahat', 'warning_signs' => 'Kembali bila memburuk', 'follow_up_plan' => 'Kontrol'], 'Evaluasi ulang menunjukkan rawat inap tidak diperlukan.', 'disposition-correct-0001')->record;

        $this->assertSame(2, $corrected->version);
        $this->assertSame($first->id, $corrected->prior_disposition_id);
        $this->assertSame($first->content_digest, $corrected->prior_disposition_digest);
        $this->assertSame(Encounter::STATUS_READY_FOR_RM, $encounter->fresh()->status);
        $this->assertDatabaseHas('emergency_dispositions', ['id' => $first->id, 'disposition_type' => 'RAWAT_INAP']);

        try {
            app(EmergencyTriageService::class)->reassess($encounter->public_id, $this->nurse, 1, [...$this->triagePayload(), 'reassessment_reason' => 'Percobaan setelah disposisi'], 'triage-after-disposition-0001');
            $this->fail('Ordinary reassessment must be blocked after disposition.');
        } catch (EmergencyDenied $denial) {
            $this->assertSame('reassessment_not_permitted', $denial->reason);
        }
    }

    public function test_each_unresolved_diagnostic_requires_a_current_accepted_follow_up_assignment(): void
    {
        $encounter = $this->readyEncounter();
        $laboratoryAdmin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN, LaboratoryMastersSeeder::ACTOR_EMAIL);
        $laboratoryAdmin->update(['status' => 'DISABLED']);
        $this->seed(LaboratoryMastersSeeder::class);
        $order = app(LaboratoryWorkflowService::class)->createOrder($encounter->public_id, LaboratoryExaminationMaster::query()->firstOrFail()->public_id, $this->physician, 'ROUTINE', 'Evaluasi diagnostik untuk keputusan IGD.', 'emergency-lab-order-0001')->record;
        $service = app(EmergencyDiagnosticFollowUpService::class);
        $item = $service->unresolvedDiagnostics($encounter->fresh())[0];
        $covering = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);

        try {
            app(EmergencyDispositionService::class)->sign($encounter->public_id, $this->physician, 'PULANG', ['condition_at_discharge' => 'Stabil', 'instructions' => 'Istirahat', 'warning_signs' => 'Kembali bila memburuk', 'follow_up_plan' => 'Kontrol'], 'disposition-without-followup-0001');
            $this->fail('Disposition must require an accepted assignment for every unresolved order.');
        } catch (EmergencyDenied $denial) {
            $this->assertSame('diagnostic_follow_up_required', $denial->reason);
        }

        $proposal = $service->propose($encounter->public_id, 'LABORATORY', $order->public_id, $item['fingerprint'], $covering->public_id, $this->physician, 'Tindak lanjut setelah disposisi.', now()->addMinutes(5)->toIso8601String(), 'Hubungi pasien bila hasil memerlukan tindakan.', 'followup-propose-0001')->record;
        $this->assertFalse($proposal->acceptance()->exists());
        $proposalFingerprint = app(EmergencyEvidenceFingerprint::class)->followUpProposal($proposal);
        $acceptance = $service->accept($proposal->public_id, $covering, $proposalFingerprint, $item['fingerprint'], 'followup-accept-0001')->record;
        $this->assertSame($covering->id, $acceptance->accepted_by_user_id);
        $this->assertTrue($service->hasAcceptedCurrentAssignment($encounter->fresh(), $item));

        $disposition = app(EmergencyDispositionService::class)->sign($encounter->public_id, $this->physician, 'PULANG', ['condition_at_discharge' => 'Stabil', 'instructions' => 'Istirahat', 'warning_signs' => 'Kembali bila memburuk', 'follow_up_plan' => 'Kontrol'], 'disposition-with-followup-0001');
        $this->assertSame('PULANG', $disposition->record->disposition_type);
        $projection = app(EmergencyProjection::class)->encounter($encounter->fresh(), $this->physician);
        $this->assertCount(1, $projection['follow_up']['diagnostic_assignment_history']);
        $this->assertSame('ACCEPTED', $projection['follow_up']['diagnostic_assignment_history'][0]['current']['state']);
    }

    public function test_projection_contains_complete_timeline_documents_disposition_and_read_only_legacy_history(): void
    {
        $encounter = $this->readyEncounter();
        $legacy = ClinicalEntry::query()->create([
            'encounter_id' => $encounter->id,
            'author_user_id' => $this->physician->id,
            'entry_type' => ClinicalEntry::TYPE_MEDICAL_ASSESSMENT,
            'body' => 'Catatan asesmen lama hanya-baca.',
        ]);
        app(EmergencyDispositionService::class)->sign($encounter->public_id, $this->physician, 'PULANG', ['condition_at_discharge' => 'Stabil', 'instructions' => 'Istirahat', 'warning_signs' => 'Kembali bila memburuk', 'follow_up_plan' => 'Kontrol'], 'disposition-projection-0001');
        $projection = app(EmergencyProjection::class)->encounter($encounter->fresh(), $this->physician);

        $this->assertSame('STRUCTURED_EMERGENCY_TRIAGE_DISPOSITION_V1', $projection['triage']['definition_version']);
        $this->assertCount(1, $projection['triage']['assessments']);
        $this->assertSame('FINAL', $projection['documentation']['current']['nursing']['state']);
        $this->assertSame('FINAL', $projection['documentation']['current']['medical']['state']);
        $this->assertSame('PULANG', $projection['disposition']['current']['code']);
        $this->assertTrue($projection['follow_up']['all_assignments_accepted']);
        $this->assertCount(1, $projection['legacy_entries']);
        $this->assertSame($legacy->public_id, $projection['legacy_entries'][0]['public_id']);
        $this->assertSame($legacy->created_at?->toIso8601String(), $projection['legacy_entries'][0]['created_at']);
    }

    public function test_accepted_assignment_survives_later_diagnostic_state_changes(): void
    {
        $encounter = $this->readyEncounter();
        $laboratoryAdmin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN, LaboratoryMastersSeeder::ACTOR_EMAIL);
        $laboratoryAdmin->update(['status' => 'DISABLED']);
        $this->seed(LaboratoryMastersSeeder::class);
        $order = app(LaboratoryWorkflowService::class)->createOrder($encounter->public_id, LaboratoryExaminationMaster::query()->firstOrFail()->public_id, $this->physician, 'ROUTINE', 'Evaluasi diagnostik untuk keputusan IGD.', 'emergency-lab-order-stable-assignment')->record;
        $service = app(EmergencyDiagnosticFollowUpService::class);
        $item = $service->unresolvedDiagnostics($encounter)[0];
        $covering = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $proposal = $service->propose($encounter->public_id, 'LABORATORY', $order->public_id, $item['fingerprint'], $covering->public_id, $this->physician, 'Tindak lanjut setelah disposisi.', now()->addMinutes(5)->toIso8601String(), 'Hubungi pasien bila hasil memerlukan tindakan.', 'followup-stable-propose')->record;
        $service->accept($proposal->public_id, $covering, app(EmergencyEvidenceFingerprint::class)->followUpProposal($proposal), $item['fingerprint'], 'followup-stable-accept');

        LaboratoryMutationScope::run(function () use ($order): void {
            $order->update(['status' => 'SPECIMEN_ACCEPTED', 'version' => 2]);
        });
        $changed = $service->unresolvedDiagnostics($encounter->fresh())[0];

        $this->assertNotSame($item['fingerprint'], $changed['fingerprint']);
        $this->assertTrue($service->hasAcceptedCurrentAssignment($encounter->fresh(), $changed));
    }

    private function readyEncounter(): Encounter
    {
        $encounter = $this->encounter();
        $vocabulary = EmergencyTriageVocabulary::query()->sole();
        app(EmergencyTriageService::class)->finalizeInitial($encounter->public_id, $vocabulary->public_id, $vocabulary->version, $this->nurse, $this->triagePayload(), 'triage-ready-'.$encounter->public_id);
        $docs = app(EmergencyDocumentationService::class);
        $docs->saveDraft($encounter->public_id, 'NURSING', $this->nurse, 0, $this->nursingFields(), 'nursing-ready-draft-'.$encounter->public_id);
        $docs->finalize($encounter->public_id, 'NURSING', $this->nurse, 1, 'nursing-ready-final-'.$encounter->public_id);
        $docs->saveDraft($encounter->public_id, 'MEDICAL', $this->physician, 0, $this->medicalFields(), 'medical-ready-draft-'.$encounter->public_id);
        $docs->finalize($encounter->public_id, 'MEDICAL', $this->physician, 1, 'medical-ready-final-'.$encounter->public_id);

        return $encounter->fresh();
    }

    private function encounter(array $overrides = []): Encounter
    {
        $patient = Patient::factory()->create(['is_synthetic' => true, 'created_by_user_id' => $this->admin->id]);

        return Encounter::factory()->create([...['patient_id' => $patient->id, 'registered_by_user_id' => $this->admin->id, 'care_setting' => Encounter::CARE_SETTING_EMERGENCY, 'status' => Encounter::STATUS_REGISTERED, 'clinic_name' => 'Instalasi Gawat Darurat', 'registered_at' => now()], ...$overrides]);
    }

    private function actor(string $role, ?string $email = null): User
    {
        $actor = User::factory()->create(['email' => $email ?? fake()->unique()->safeEmail(), 'status' => 'ACTIVE', 'is_system_administrator' => false]);
        $actor->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $actor;
    }

    private function triagePayload(string $category = 'HIJAU'): array
    {
        return ['category_code' => $category, 'observed_at' => now()->toIso8601String(), 'presenting_concern' => 'Keluhan utama pasien', 'clinical_basis' => 'Kategori dipilih manual berdasarkan observasi ABCDE.', 'arrival_condition' => 'Pasien datang sadar dan dapat berkomunikasi.', 'abcde' => ['airway' => ['state' => 'ASSESSED_NO_CONCERN', 'note' => null], 'breathing' => ['state' => 'ASSESSED_NO_CONCERN', 'note' => null], 'circulation' => ['state' => 'ASSESSED_NO_CONCERN', 'note' => null], 'disability' => ['state' => 'ASSESSED_NO_CONCERN', 'note' => null], 'exposure' => ['state' => 'ASSESSED_NO_CONCERN', 'note' => null]], 'consciousness' => 'ALERT', 'vitals' => ['respiratory_rate' => 0, 'pulse' => 0, 'systolic_pressure' => 0, 'diastolic_pressure' => 0, 'oxygen_saturation' => 0, 'temperature' => 36.5, 'pain_score' => 0, 'weight' => null], 'unobtainable_fields' => [], 'unobtainable_reasons' => [], 'trauma' => false, 'isolation_precaution' => false, 'handoff_note' => 'Lanjutkan pemantauan manual.'];
    }

    private function nursingFields(): array
    {
        return ['arrival_condition' => 'Sadar', 'focused_assessment' => 'Asesmen fokus dilakukan', 'interventions' => 'Pemantauan manual', 'response_evaluation' => 'Respons dicatat', 'safety_observation_needs' => 'Observasi rutin', 'handoff_note' => 'Serah terima tercatat'];
    }

    private function medicalFields(): array
    {
        return ['anamnesis' => 'Anamnesis terstruktur', 'focused_physical_examination' => 'Pemeriksaan fisik fokus', 'clinical_impression' => 'Impresi klinis sementara', 'problem_list' => 'Daftar masalah', 'treatment_action_plan' => 'Rencana tindakan', 'diagnostic_order_rationale' => 'Belum ada pesanan diagnostik', 'disposition_readiness_note' => 'Siap dipertimbangkan untuk disposisi'];
    }
}
