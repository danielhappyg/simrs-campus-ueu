<?php

namespace Tests\Feature\Outpatient;

use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientBedVersion;
use App\Models\InpatientWard;
use App\Models\OutpatientClinicalDocument;
use App\Models\OutpatientClinicalDocumentVersion;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\CanonicalJson;
use App\Support\Clinical\OutpatientDispositionDenied;
use App\Support\Clinical\OutpatientDispositionService;
use App\Support\Clinical\OutpatientInpatientHandoffService;
use App\Support\Inpatient\InpatientAdmissionService;
use App\Support\Inpatient\InpatientMasterMutationScope;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

final class OutpatientDispositionAdmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_physician_signs_and_replays_document_bound_disposition_then_corrects_before_handoff(): void
    {
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        [$encounter, $version] = $this->encounterWithFinalMedicalDocument($physician);
        $service = app(OutpatientDispositionService::class);

        $first = $service->sign($encounter, $physician, 'RAWAT_INAP', [
            'admission_reason' => 'Requires inpatient monitoring.',
            'receiving_unit_handoff_note' => 'Continue serial observations.',
        ], 2, 'outpatient-sign-0001');
        $replay = $service->sign($encounter, $physician, 'RAWAT_INAP', [
            'admission_reason' => 'Requires inpatient monitoring.',
            'receiving_unit_handoff_note' => 'Continue serial observations.',
        ], 2, 'outpatient-sign-0001');

        $this->assertSame($first->id, $replay->id);
        $this->assertSame($version->id, $first->medical_document_version_id);
        $this->assertSame(Encounter::STATUS_IN_EXAMINATION, $encounter->fresh()->status);

        $corrected = $service->correct($encounter, $physician, 1, 'KONTROL_ULANG', [
            'follow_up_plan' => 'Review in the outpatient clinic in seven days.',
        ], 2, 'Admission no longer required.', 'outpatient-correct-0001');

        $this->assertSame(2, $corrected->version);
        $this->assertSame($first->id, $corrected->prior_disposition_id);
        $this->assertSame(Encounter::STATUS_READY_FOR_RM, $encounter->fresh()->status);
        $this->assertDatabaseCount('outpatient_dispositions', 2);
        $this->assertDatabaseHas('audit_events', ['action' => 'clinical.outpatient.disposition.correct', 'outcome' => 'SUCCESS']);
    }

    public function test_registrar_cannot_sign_and_denial_is_audited(): void
    {
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $registrar = $this->actor(RoleCapabilityMatrix::ROLE_REGISTRAR);
        [$encounter] = $this->encounterWithFinalMedicalDocument($physician);
        $service = app(OutpatientDispositionService::class);

        try {
            $service->sign($encounter, $registrar, 'SEMBUH', ['clinical_note' => 'Recovered.'], 2, 'outpatient-role-0001');
            $this->fail('Registrar must not sign a physician disposition.');
        } catch (OutpatientDispositionDenied $denial) {
            $this->assertSame('role_not_permitted', $denial->reason);
        }
        $this->assertDatabaseHas('audit_events', ['action' => 'clinical.outpatient.disposition.sign', 'outcome' => 'DENIED', 'reason' => 'role_not_permitted']);
    }

    public function test_stale_document_and_changed_idempotent_payload_are_rejected_without_extra_evidence(): void
    {
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        [$encounter] = $this->encounterWithFinalMedicalDocument($physician);
        $service = app(OutpatientDispositionService::class);
        try {
            $service->sign($encounter, $physician, 'SEMBUH', ['clinical_note' => 'Recovered.'], 1, 'outpatient-stale-doc-0001');
            $this->fail('A stale document version must be rejected.');
        } catch (OutpatientDispositionDenied $denial) {
            $this->assertSame('medical_document_not_current', $denial->reason);
        }
        $service->sign($encounter, $physician, 'SEMBUH', ['clinical_note' => 'Recovered.'], 2, 'outpatient-idempotency-0001');
        try {
            $service->sign($encounter, $physician, 'SEMBUH', ['clinical_note' => 'Different outcome.'], 2, 'outpatient-idempotency-0001');
            $this->fail('A changed idempotent payload must be rejected.');
        } catch (OutpatientDispositionDenied $denial) {
            $this->assertSame('idempotency_conflict', $denial->reason);
        }
        $this->assertDatabaseCount('outpatient_dispositions', 1);
    }

    public function test_registrar_handoff_creates_linked_inpatient_episode_and_replays_safely(): void
    {
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $registrar = $this->actor(RoleCapabilityMatrix::ROLE_REGISTRAR);
        [$source] = $this->encounterWithFinalMedicalDocument($physician);
        $disposition = app(OutpatientDispositionService::class)->sign($source, $physician, 'RAWAT_INAP', [
            'admission_reason' => 'Requires inpatient monitoring.',
            'receiving_unit_handoff_note' => 'Continue serial observations.',
        ], 2, 'outpatient-sign-handoff-0001');
        $bed = $this->bed($registrar);

        $first = app(OutpatientInpatientHandoffService::class)->execute($source, $registrar, $disposition->version, $bed->public_id, 'outpatient-handoff-0001');
        $replay = app(OutpatientInpatientHandoffService::class)->execute($source, $registrar, $disposition->version, $bed->public_id, 'outpatient-handoff-0001');

        $this->assertSame($first->id, $replay->id);
        $target = $first->targetEncounter()->firstOrFail();
        $this->assertSame($source->patient_id, $target->patient_id);
        $this->assertSame(Encounter::CONTINUE_DARI_RJ, $target->continue_from);
        $this->assertSame(Encounter::ADMISSION_OUTPATIENT, $target->admission_mode);
        $this->assertSame(Encounter::STATUS_READY_FOR_RM, $source->fresh()->status);
        $this->assertDatabaseCount('outpatient_inpatient_handoffs', 1);
        $this->assertDatabaseHas('audit_events', ['action' => 'clinical.outpatient.inpatient-handoff.execute', 'outcome' => 'SUCCESS']);

        try {
            app(OutpatientDispositionService::class)->correct($source, $physician, 1, 'SEMBUH', ['clinical_note' => 'Recovered.'], 2, 'Late correction.', 'outpatient-late-correct-0001');
            $this->fail('Executed handoff must make correction ineligible.');
        } catch (OutpatientDispositionDenied $denial) {
            $this->assertSame('handoff_already_completed', $denial->reason);
        }
        $this->assertDatabaseCount('outpatient_dispositions', 1);
    }

    public function test_occupied_bed_and_physician_handoff_are_rejected_without_partial_admission(): void
    {
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $registrar = $this->actor(RoleCapabilityMatrix::ROLE_REGISTRAR);
        [$source] = $this->encounterWithFinalMedicalDocument($physician);
        app(OutpatientDispositionService::class)->sign($source, $physician, 'RAWAT_INAP', [
            'admission_reason' => 'Monitoring required.', 'receiving_unit_handoff_note' => 'Continue observations.',
        ], 2, 'occupied-sign-0001');
        $bed = $this->bed($registrar);
        app(InpatientAdmissionService::class)->admitDirect(
            Patient::factory()->create(), $registrar, $bed->public_id, Encounter::PAYER_UMUM, null,
            Encounter::CONTINUE_LANGSUNG, 'Planned admission.',
            admissionAuthorityType: Encounter::AUTHORITY_PLANNED_ORDER,
            admissionAuthorityReference: 'ORDER-EXISTING-0001',
        );
        foreach ([[$physician, 'role_not_permitted'], [$registrar, 'bed_occupied']] as [$actor, $reason]) {
            try {
                app(OutpatientInpatientHandoffService::class)->execute($source, $actor, 1, $bed->public_id, 'occupied-handoff-'.$actor->public_id);
                $this->fail('Unauthorized or occupied-bed handoff must be rejected.');
            } catch (OutpatientDispositionDenied $denial) {
                $this->assertSame($reason, $denial->reason);
            }
            $this->assertDatabaseHas('audit_events', ['action' => 'clinical.outpatient.inpatient-handoff.execute', 'outcome' => 'DENIED', 'reason' => $reason]);
        }
        $this->assertSame(Encounter::STATUS_IN_EXAMINATION, $source->fresh()->status);
        $this->assertSame(1, Encounter::query()->where('care_setting', Encounter::CARE_SETTING_INPATIENT)->count());
        $this->assertDatabaseCount('outpatient_inpatient_handoffs', 0);
        $this->assertDatabaseCount('outpatient_admission_operation_receipts', 1);
    }

    public function test_withdrawn_request_and_closed_encounter_cannot_be_reactivated_by_stale_operations(): void
    {
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $registrar = $this->actor(RoleCapabilityMatrix::ROLE_REGISTRAR);
        [$source] = $this->encounterWithFinalMedicalDocument($physician);
        $service = app(OutpatientDispositionService::class);
        $service->sign($source, $physician, 'RAWAT_INAP', [
            'admission_reason' => 'Monitoring required.', 'receiving_unit_handoff_note' => 'Continue observations.',
        ], 2, 'withdraw-sign-0001');
        $service->correct($source, $physician, 1, 'KONTROL_ULANG', ['follow_up_plan' => 'Review in seven days.'], 2, 'Admission no longer required.', 'withdraw-correct-0001');
        $bed = $this->bed($registrar);
        try {
            app(OutpatientInpatientHandoffService::class)->execute($source, $registrar, 1, $bed->public_id, 'withdraw-handoff-0001');
            $this->fail('A withdrawn admission request cannot be handed off.');
        } catch (OutpatientDispositionDenied $denial) {
            $this->assertSame('source_not_eligible', $denial->reason);
        }
        try {
            $service->correct($source, $physician, 1, 'SEMBUH', ['clinical_note' => 'Recovered.'], 2, 'Stale correction.', 'withdraw-stale-0001');
            $this->fail('A stale disposition version cannot be corrected.');
        } catch (OutpatientDispositionDenied $denial) {
            $this->assertSame('stale_version', $denial->reason);
        }
        $source->update(['status' => Encounter::STATUS_CLOSED]);
        try {
            $service->correct($source, $physician, 2, 'SEMBUH', ['clinical_note' => 'Recovered.'], 2, 'Late correction.', 'withdraw-closed-0001');
            $this->fail('A closed encounter cannot be reopened by a correction.');
        } catch (OutpatientDispositionDenied $denial) {
            $this->assertSame('encounter_not_eligible', $denial->reason);
        }
        $this->assertSame(Encounter::STATUS_CLOSED, $source->fresh()->status);
        $this->assertDatabaseCount('outpatient_dispositions', 2);
        $this->assertDatabaseCount('outpatient_inpatient_handoffs', 0);
    }

    public function test_handoff_audit_failure_rolls_back_target_placement_and_source_status(): void
    {
        $physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $registrar = $this->actor(RoleCapabilityMatrix::ROLE_REGISTRAR);
        [$source] = $this->encounterWithFinalMedicalDocument($physician);
        app(OutpatientDispositionService::class)->sign($source, $physician, 'RAWAT_INAP', [
            'admission_reason' => 'Monitoring required.', 'receiving_unit_handoff_note' => 'Continue observations.',
        ], 2, 'audit-sign-0001');
        $bed = $this->bed($registrar);
        $audit = Mockery::mock(AuditRecorder::class)->makePartial();
        $audit->shouldReceive('record')->withArgs(fn (string $action, string $resource, mixed $id, mixed $actor, string $outcome): bool => $action === 'clinical.outpatient.inpatient-handoff.execute' && $outcome === 'SUCCESS')->andReturnNull();
        $this->app->instance(AuditRecorder::class, $audit);
        try {
            app(OutpatientInpatientHandoffService::class)->execute($source, $registrar, 1, $bed->public_id, 'audit-handoff-0001');
            $this->fail('Missing mandatory audit must roll back the handoff.');
        } catch (OutpatientDispositionDenied $denial) {
            $this->assertSame('audit_unavailable', $denial->reason);
        }
        $this->assertSame(Encounter::STATUS_IN_EXAMINATION, $source->fresh()->status);
        $this->assertSame(0, Encounter::query()->where('care_setting', Encounter::CARE_SETTING_INPATIENT)->count());
        $this->assertDatabaseCount('inpatient_location_events', 0);
        $this->assertDatabaseCount('outpatient_inpatient_handoffs', 0);
        $this->assertDatabaseCount('outpatient_admission_operation_receipts', 1);
    }

    /** @return array{Encounter,OutpatientClinicalDocumentVersion} */
    private function encounterWithFinalMedicalDocument(User $physician): array
    {
        $patient = Patient::factory()->create(['is_synthetic' => true, 'created_by_user_id' => $physician->id]);
        $encounter = Encounter::factory()->create(['patient_id' => $patient->id, 'registered_by_user_id' => $physician->id, 'care_setting' => Encounter::CARE_SETTING_OUTPATIENT, 'status' => Encounter::STATUS_IN_EXAMINATION]);
        $document = OutpatientClinicalDocument::query()->create(['encounter_id' => $encounter->id, 'author_user_id' => $physician->id, 'finalized_by_user_id' => $physician->id, 'document_type' => OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT, 'document_state' => OutpatientClinicalDocument::STATE_FINAL, 'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION, 'version' => 2, 'fields' => ['clinical_assessment' => 'Stable'], 'finalized_at' => now()]);
        $version = OutpatientClinicalDocumentVersion::query()->create(['outpatient_clinical_document_id' => $document->id, 'actor_user_id' => $physician->id, 'version' => 2, 'document_state' => OutpatientClinicalDocument::STATE_FINAL, 'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION, 'fields' => ['clinical_assessment' => 'Stable'], 'finalized_at' => now()]);

        return [$encounter, $version];
    }

    private function actor(string $role): User
    {
        $actor = User::factory()->create(['status' => 'ACTIVE', 'is_system_administrator' => false]);
        $actor->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $actor;
    }

    private function bed(User $actor): InpatientBed
    {
        return InpatientMasterMutationScope::run(function () use ($actor): InpatientBed {
            $ward = InpatientWard::query()->create(['code' => 'RI-RJ', 'display_name' => 'Rawat Inap RJ', 'state' => InpatientWard::STATE_ACTIVE, 'version' => 1]);
            $bed = InpatientBed::query()->create(['ward_id' => $ward->id, 'code' => 'RJ-RI-01', 'display_name' => 'Tempat Tidur 01', 'room_label' => 'Ruang Transisi', 'service_class' => 'Kelas 1', 'state' => InpatientBed::STATE_ACTIVE, 'version' => 1]);
            InpatientBedVersion::query()->create(['bed_id' => $bed->id, 'actor_user_id' => $actor->id, 'version' => 1, 'display_name' => $bed->display_name, 'room_label' => $bed->room_label, 'service_class' => $bed->service_class, 'state' => $bed->state, 'reason_code' => 'INITIAL_SETUP', 'before_digest' => null, 'after_digest' => hash('sha256', CanonicalJson::encode(['code' => $bed->code, 'display_name' => $bed->display_name, 'room_label' => $bed->room_label, 'service_class' => $bed->service_class, 'state' => $bed->state, 'version' => 1])), 'request_correlation_id' => null]);

            return $bed;
        });
    }
}
