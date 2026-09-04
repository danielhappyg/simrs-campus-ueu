<?php

namespace Tests\Feature\Database;

use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientBedVersion;
use App\Models\InpatientWard;
use App\Models\OutpatientAdmissionOperationReceipt;
use App\Models\OutpatientClinicalDocument;
use App\Models\OutpatientClinicalDocumentVersion;
use App\Models\OutpatientDisposition;
use App\Models\OutpatientInpatientHandoff;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\CanonicalJson;
use App\Support\Clinical\OutpatientDispositionService;
use App\Support\Clinical\OutpatientInpatientHandoffService;
use App\Support\Inpatient\InpatientAdmissionService;
use App\Support\Inpatient\InpatientMasterMutationScope;
use App\Support\Simulation\SyntheticResetService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OutpatientAdmissionEvidenceGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $registrar;

    private User $physician;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->registrar = $this->actor(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $this->physician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
    }

    public function test_raw_updates_and_deletes_of_signed_admission_evidence_are_rejected_transactionally(): void
    {
        [$source, $disposition, $handoff] = $this->signedAndHandedOffGraph();
        $receipt = OutpatientAdmissionOperationReceipt::query()->firstOrFail();

        foreach ([
            ['outpatient_dispositions', $disposition->id, 'created_at'],
            ['outpatient_inpatient_handoffs', $handoff->id, 'created_at'],
            ['outpatient_admission_operation_receipts', $receipt->id, 'completed_at'],
        ] as [$table, $id, $timestamp]) {
            try {
                DB::transaction(fn () => DB::table($table)->where('id', $id)->update([$timestamp => now()->addMinute()]));
                $this->fail("Raw update on {$table} must be rejected.");
            } catch (QueryException $exception) {
                $this->assertStringContainsString('outpatient admission evidence is immutable', $exception->getMessage());
            }

            try {
                DB::transaction(fn () => DB::table($table)->where('id', $id)->delete());
                $this->fail("Raw delete on {$table} must be rejected.");
            } catch (QueryException $exception) {
                $this->assertStringContainsString('outpatient admission evidence is immutable', $exception->getMessage());
            }
        }

        $this->assertDatabaseHas('outpatient_dispositions', ['id' => $disposition->id]);
        $this->assertDatabaseHas('outpatient_inpatient_handoffs', ['id' => $handoff->id]);
        $this->assertDatabaseHas('outpatient_admission_operation_receipts', ['id' => $receipt->id]);
        $this->assertSame(Encounter::STATUS_READY_FOR_RM, $source->fresh()->status);
    }

    public function test_database_rejects_forged_cross_patient_disposition_document_and_handoff_graphs(): void
    {
        [$bed] = $this->beds();
        $source = $this->source();
        $this->medicalDocument($source);
        $other = $this->source();
        $otherVersion = $this->medicalDocument($other);

        try {
            DB::transaction(fn () => DB::table('outpatient_dispositions')->insert([
                'public_id' => (string) Str::ulid(),
                'encounter_id' => $source->id,
                'physician_user_id' => $this->physician->id,
                'medical_document_version_id' => $otherVersion->id,
                'version' => 1,
                'disposition_type' => 'RAWAT_INAP',
                'payload' => json_encode(['admission_reason' => 'Tidak boleh terikat ke dokumen pasien lain.', 'receiving_unit_handoff_note' => 'Uji graf palsu.'], JSON_THROW_ON_ERROR),
                'content_digest' => str_repeat('a', 64),
                'signed_at' => now(),
                'created_at' => now(),
            ]));
            $this->fail('Disposition must bind to a current medical document from the same outpatient encounter.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('invalid outpatient admission graph', $exception->getMessage());
        }

        $disposition = $this->signRawatInap($source);
        $otherPatient = Patient::factory()->create(['is_synthetic' => true, 'created_by_user_id' => $this->registrar->id]);
        $admission = app(InpatientAdmissionService::class)->admitDirect(
            $otherPatient,
            $this->registrar,
            $bed->public_id,
            Encounter::PAYER_UMUM,
            null,
            Encounter::CONTINUE_LANGSUNG,
            'Admisi pasien lain untuk uji graf.',
            admissionAuthorityType: Encounter::AUTHORITY_PLANNED_ORDER,
            admissionAuthorityReference: 'ORDER-OADM-GRAPH-0001',
        );

        try {
            DB::transaction(fn () => DB::table('outpatient_inpatient_handoffs')->insert([
                'public_id' => (string) Str::ulid(),
                'source_encounter_id' => $source->id,
                'disposition_id' => $disposition->id,
                'target_encounter_id' => $admission->encounter->id,
                'registrar_user_id' => $this->registrar->id,
                'inpatient_location_event_id' => $admission->location->id,
                'bed_snapshot' => json_encode([], JSON_THROW_ON_ERROR),
                'content_digest' => str_repeat('b', 64),
                'completed_at' => now(),
                'created_at' => now(),
            ]));
            $this->fail('Handoff must bind source and target to the same patient.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('invalid outpatient admission graph', $exception->getMessage());
        }
    }

    public function test_valid_correction_then_synthetic_reset_removes_graph_and_preserves_audit_evidence(): void
    {
        [$bed] = $this->beds();
        $source = $this->source();
        $this->medicalDocument($source);
        $initial = $this->signRawatInap($source);
        $corrected = app(OutpatientDispositionService::class)->correct(
            $source,
            $this->physician,
            $initial->version,
            'RAWAT_INAP',
            ['admission_reason' => 'Observasi lanjutan setelah koreksi.', 'receiving_unit_handoff_note' => 'Catatan penerima telah diperbarui.'],
            1,
            'Koreksi alasan dan catatan unit penerima.',
            'oadm-correct-0001',
        );
        app(OutpatientInpatientHandoffService::class)->execute(
            $source,
            $this->registrar,
            $corrected->version,
            $bed->public_id,
            'oadm-handoff-reset-0001',
        );

        $this->assertDatabaseCount('outpatient_dispositions', 2);
        $this->assertDatabaseCount('outpatient_inpatient_handoffs', 1);
        $this->assertDatabaseCount('outpatient_admission_operation_receipts', 3);
        $this->assertDatabaseHas('audit_events', ['action' => 'clinical.outpatient.disposition.correct', 'outcome' => 'SUCCESS']);

        app(SyntheticResetService::class)->reset(['actor' => $this->registrar, 'reason' => 'outpatient_admission_guard_reset']);

        $this->assertDatabaseCount('outpatient_dispositions', 0);
        $this->assertDatabaseCount('outpatient_inpatient_handoffs', 0);
        $this->assertDatabaseCount('outpatient_admission_operation_receipts', 0);
        $this->assertDatabaseHas('audit_events', ['action' => 'clinical.outpatient.disposition.sign', 'outcome' => 'SUCCESS']);
        $this->assertDatabaseHas('audit_events', ['action' => 'clinical.outpatient.disposition.correct', 'outcome' => 'SUCCESS']);
        $this->assertDatabaseHas('audit_events', ['action' => 'teaching.reset.completed', 'outcome' => 'SUCCESS']);
    }

    /** @return array{Encounter, OutpatientDisposition, OutpatientInpatientHandoff} */
    private function signedAndHandedOffGraph(): array
    {
        [$bed] = $this->beds();
        $source = $this->source();
        $this->medicalDocument($source);
        $disposition = $this->signRawatInap($source);
        $handoff = app(OutpatientInpatientHandoffService::class)->execute($source, $this->registrar, $disposition->version, $bed->public_id, 'oadm-handoff-evidence-0001');

        return [$source, $disposition, $handoff];
    }

    private function signRawatInap(Encounter $source): OutpatientDisposition
    {
        return app(OutpatientDispositionService::class)->sign($source, $this->physician, 'RAWAT_INAP', ['admission_reason' => 'Memerlukan observasi rawat inap.', 'receiving_unit_handoff_note' => 'Serah terima ke bangsal penerima.'], 1, 'oadm-sign-'.$source->public_id);
    }

    private function source(): Encounter
    {
        $patient = Patient::factory()->create(['is_synthetic' => true, 'created_by_user_id' => $this->registrar->id]);

        return Encounter::factory()->create(['patient_id' => $patient->id, 'registered_by_user_id' => $this->registrar->id, 'care_setting' => Encounter::CARE_SETTING_OUTPATIENT, 'status' => Encounter::STATUS_IN_EXAMINATION, 'clinic_name' => 'Klinik Uji Bukti', 'payer_type' => Encounter::PAYER_UMUM, 'queue_date' => now()->toDateString(), 'queue_number' => fake()->unique()->numberBetween(1, 999), 'registered_at' => now()]);
    }

    private function medicalDocument(Encounter $encounter): OutpatientClinicalDocumentVersion
    {
        $document = OutpatientClinicalDocument::query()->create(['encounter_id' => $encounter->id, 'author_user_id' => $this->physician->id, 'finalized_by_user_id' => $this->physician->id, 'document_type' => OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT, 'document_state' => OutpatientClinicalDocument::STATE_FINAL, 'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION, 'version' => 1, 'fields' => ['anamnesis' => 'A', 'objective_examination' => 'B', 'clinical_assessment' => 'C', 'care_plan' => 'D'], 'finalized_at' => now()]);

        return OutpatientClinicalDocumentVersion::query()->create(['outpatient_clinical_document_id' => $document->id, 'actor_user_id' => $this->physician->id, 'version' => 1, 'document_state' => OutpatientClinicalDocument::STATE_FINAL, 'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION, 'fields' => $document->fields, 'finalized_at' => now()]);
    }

    /** @return array{InpatientBed} */
    private function beds(): array
    {
        return InpatientMasterMutationScope::run(function (): array {
            $ward = InpatientWard::query()->create(['code' => 'OADM', 'display_name' => 'Bangsal Bukti OADM', 'state' => InpatientWard::STATE_ACTIVE, 'version' => 1]);
            $bed = InpatientBed::query()->create(['ward_id' => $ward->id, 'code' => 'OADM-01', 'display_name' => 'Tempat Tidur Bukti', 'room_label' => 'Ruang Bukti', 'service_class' => 'Kelas 1', 'state' => InpatientBed::STATE_ACTIVE, 'version' => 1]);
            InpatientBedVersion::query()->create(['bed_id' => $bed->id, 'actor_user_id' => $this->registrar->id, 'version' => 1, 'display_name' => $bed->display_name, 'room_label' => $bed->room_label, 'service_class' => $bed->service_class, 'state' => $bed->state, 'reason_code' => 'INITIAL_SETUP', 'before_digest' => null, 'after_digest' => hash('sha256', CanonicalJson::encode(['code' => $bed->code, 'display_name' => $bed->display_name, 'room_label' => $bed->room_label, 'service_class' => $bed->service_class, 'state' => $bed->state, 'version' => 1])), 'request_correlation_id' => null]);

            return [$bed];
        });
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $user->fresh();
    }
}
