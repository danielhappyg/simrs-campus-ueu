<?php

namespace Tests\Feature\Emergency;

use App\Models\ClinicalEntry;
use App\Models\EmergencyDisposition;
use App\Models\EmergencyHandoffCompensation;
use App\Models\EmergencyInpatientHandoff;
use App\Models\Encounter;
use App\Models\EncounterCancellation;
use App\Models\InpatientBed;
use App\Models\InpatientBedVersion;
use App\Models\InpatientLocationEvent;
use App\Models\InpatientWard;
use App\Models\Patient;
use App\Models\PharmacyDepot;
use App\Models\PharmacyPrescription;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\CanonicalJson;
use App\Support\Emergency\EmergencyDenied;
use App\Support\Emergency\EmergencyDispositionService;
use App\Support\Emergency\EmergencyEvidenceFingerprint;
use App\Support\Emergency\EmergencyInpatientHandoffCompensationService;
use App\Support\Emergency\EmergencyInpatientHandoffService;
use App\Support\Emergency\EmergencyMutationScope;
use App\Support\Emergency\EmergencyProjection;
use App\Support\Inpatient\InpatientAdmissionService;
use App\Support\Inpatient\InpatientMasterMutationScope;
use App\Support\Pharmacy\PharmacyMutationScope;
use App\Support\Simulation\SyntheticResetService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class EmergencyInpatientHandoffTest extends TestCase
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

    public function test_registrar_handoff_is_atomic_replayable_and_refuses_a_second_active_admission_for_the_patient(): void
    {
        [$firstBed, $secondBed] = $this->beds();
        $source = $this->source();
        $disposition = $this->rawatInapDisposition($source);

        $registrarProjection = app(EmergencyProjection::class)->encounter($source, $this->registrar);
        $this->assertSame('RAWAT_INAP', $registrarProjection['disposition']['current']['code']);
        $this->assertTrue($registrarProjection['disposition']['permissions']['can_handoff']);
        $this->assertCount(2, $registrarProjection['disposition']['bed_options']);

        $service = app(EmergencyInpatientHandoffService::class);
        $first = $service->execute(
            $source->public_id,
            $this->registrar,
            $disposition->version,
            $firstBed->public_id,
            'emergency-handoff-0001',
        );
        $handoff = $first->record;
        $target = $handoff->targetEncounter()->firstOrFail();

        $this->assertFalse($first->replayed);
        $this->assertSame(Encounter::STATUS_READY_FOR_RM, $source->fresh()->status);
        $this->assertSame($source->patient_id, $target->patient_id);
        $this->assertSame(Encounter::CONTINUE_DARI_IGD, $target->continue_from);
        $this->assertSame($target->patient_id, $target->active_inpatient_patient_id);
        $this->assertSame($firstBed->id, $target->inpatient_bed_id);
        $this->assertDatabaseHas('inpatient_location_events', [
            'id' => $handoff->inpatient_location_event_id,
            'encounter_id' => $target->id,
            'event_type' => InpatientLocationEvent::TYPE_ADMISSION,
            'sequence' => 1,
        ]);

        $replay = $service->execute(
            $source->public_id,
            $this->registrar,
            $disposition->version,
            $firstBed->public_id,
            'emergency-handoff-0001',
        );
        $this->assertTrue($replay->replayed);
        $this->assertSame($handoff->public_id, $replay->record->public_id);
        $this->assertDatabaseCount('emergency_inpatient_handoffs', 1);

        $secondSource = $this->source($source->patient()->firstOrFail());
        $secondDisposition = $this->rawatInapDisposition($secondSource);
        try {
            $service->execute(
                $secondSource->public_id,
                $this->registrar,
                $secondDisposition->version,
                $secondBed->public_id,
                'emergency-handoff-0002',
            );
            $this->fail('One patient must not obtain a second active inpatient encounter.');
        } catch (EmergencyDenied $denial) {
            $this->assertSame('active_patient_admission_exists', $denial->reason);
        }

        $this->assertSame(1, Encounter::query()->where('active_inpatient_patient_id', $source->patient_id)->count());
        $this->assertDatabaseCount('emergency_inpatient_handoffs', 1);
    }

    public function test_pending_igd_rawat_inap_disposition_is_visible_on_inpatient_registration_until_handoff_completes(): void
    {
        [$bed] = $this->beds();
        $source = $this->source();
        $disposition = $this->rawatInapDisposition($source);

        $this->actingAs($this->registrar)
            ->get(route('pendaftaran.rawat-inap.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pendaftaran/rawat-inap')
                ->has('pendingEmergencyAdmissions', 1)
                ->where('pendingEmergencyAdmissions.0.source_encounter_public_id', $source->public_id)
                ->where('pendingEmergencyAdmissions.0.disposition_public_id', $disposition->public_id)
                ->where('pendingEmergencyAdmissions.0.disposition_version', 1)
                ->where('pendingEmergencyAdmissions.0.admission_reason', 'Pasien membutuhkan pemantauan lanjutan.')
                ->where('pendingEmergencyAdmissions.0.handoff_url', route('pemeriksaan.igd.show', $source).'?tab=disposition'));

        app(EmergencyInpatientHandoffService::class)->execute(
            $source->public_id,
            $this->registrar,
            $disposition->version,
            $bed->public_id,
            'emergency-handoff-registration-queue-0001',
        );

        $this->actingAs($this->registrar)
            ->get(route('pendaftaran.rawat-inap.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pendaftaran/rawat-inap')
                ->has('pendingEmergencyAdmissions', 0));
    }

    public function test_physician_intent_is_compensated_by_registrar_with_child_cancellation_bed_release_and_replacement_disposition(): void
    {
        [$bed] = $this->beds();
        $source = $this->source();
        $disposition = $this->rawatInapDisposition($source);
        $handoff = app(EmergencyInpatientHandoffService::class)->execute(
            $source->public_id,
            $this->registrar,
            $disposition->version,
            $bed->public_id,
            'emergency-handoff-compensate-0001',
        )->record;
        $target = $handoff->targetEncounter()->firstOrFail();

        $intent = app(EmergencyDispositionService::class)->createExecutedHandoffCorrectionIntent(
            $source->public_id,
            $this->physician,
            1,
            'PULANG',
            $this->pulangPayload(),
            'Evaluasi dokter memastikan pasien dapat pulang sebelum pelayanan rawat inap dimulai.',
            now()->addHour()->toIso8601String(),
            'emergency-intent-0001',
        )->record;
        $this->assertTrue(
            $intent->fresh()->expires_at->isFuture(),
            'Expected future intent expiry; now='.now()->toIso8601String().'; stored='.$intent->fresh()->expires_at->toIso8601String(),
        );

        $service = app(EmergencyInpatientHandoffCompensationService::class);
        $result = $service->execute(
            $source->public_id,
            $this->registrar,
            $intent->public_id,
            'emergency-compensation-0001',
        );
        $compensation = $result->record;

        $this->assertInstanceOf(EmergencyHandoffCompensation::class, $compensation);
        $this->assertFalse($result->replayed);
        $this->assertSame(Encounter::STATUS_CANCELLED, $target->fresh()->status);
        $this->assertNull($target->fresh()->active_inpatient_patient_id);
        $this->assertSame(Encounter::STATUS_READY_FOR_RM, $source->fresh()->status);
        $this->assertDatabaseHas('encounter_cancellations', [
            'encounter_id' => $target->id,
            'reason_code' => EncounterCancellation::REASON_PLAN_CHANGED_BEFORE_SERVICE,
        ]);
        $this->assertDatabaseHas('emergency_dispositions', [
            'id' => $compensation->replacement_disposition_id,
            'encounter_id' => $source->id,
            'version' => 2,
            'disposition_type' => 'PULANG',
        ]);
        $this->assertDatabaseHas('emergency_disposition_correction_intent_events', [
            'correction_intent_id' => $intent->id,
            'event_type' => 'EXECUTED',
        ]);
        $this->assertSame(0, Encounter::query()->where('active_inpatient_patient_id', $source->patient_id)->count());

        $replay = $service->execute(
            $source->public_id,
            $this->registrar,
            $intent->public_id,
            'emergency-compensation-0001',
        );
        $this->assertTrue($replay->replayed);
        $this->assertSame($compensation->public_id, $replay->record->public_id);
    }

    public function test_active_source_pharmacy_prescription_blocks_igd_to_inpatient_handoff(): void
    {
        [$bed] = $this->beds();
        $source = $this->source();
        $disposition = $this->rawatInapDisposition($source);
        $this->pharmacyPrescription($source, PharmacyPrescription::ORDERED, 'DEPO_IGD_HANDOFF');

        try {
            app(EmergencyInpatientHandoffService::class)->execute(
                $source->public_id,
                $this->registrar,
                $disposition->version,
                $bed->public_id,
                'emergency-handoff-active-pharmacy-0001',
            );
            $this->fail('Expected active pharmacy prescription denial.');
        } catch (EmergencyDenied $denial) {
            $this->assertSame('active_pharmacy_prescriptions', $denial->reason);
        }

        $this->assertSame(Encounter::STATUS_IN_EXAMINATION, $source->fresh()?->status);
        $this->assertDatabaseCount('emergency_inpatient_handoffs', 0);
        $this->assertDatabaseCount('inpatient_location_events', 0);
        $this->assertDatabaseMissing('encounters', [
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'patient_id' => $source->patient_id,
        ]);
    }

    public function test_receiving_inpatient_pharmacy_evidence_makes_handoff_compensation_ineligible(): void
    {
        [$bed] = $this->beds();
        $source = $this->source();
        $disposition = $this->rawatInapDisposition($source);
        $handoff = app(EmergencyInpatientHandoffService::class)->execute(
            $source->public_id,
            $this->registrar,
            $disposition->version,
            $bed->public_id,
            'emergency-handoff-pharmacy-child-0001',
        )->record;
        $target = $handoff->targetEncounter()->firstOrFail();
        $this->pharmacyPrescription($target, PharmacyPrescription::DRAFT, 'DEPO_RI_COMPENSATE');
        $intent = app(EmergencyDispositionService::class)->createExecutedHandoffCorrectionIntent(
            $source->public_id,
            $this->physician,
            1,
            'PULANG',
            $this->pulangPayload(),
            'Koreksi setelah episode penerima memiliki bukti resep.',
            now()->addHour()->toIso8601String(),
            'emergency-intent-pharmacy-child-0001',
        )->record;

        try {
            app(EmergencyInpatientHandoffCompensationService::class)->execute(
                $source->public_id,
                $this->registrar,
                $intent->public_id,
                'emergency-compensation-pharmacy-child-0001',
            );
            $this->fail('Receiving inpatient pharmacy evidence must block compensation.');
        } catch (EmergencyDenied $denial) {
            $this->assertSame('child_progressed', $denial->reason);
        }

        $this->assertSame(Encounter::STATUS_REGISTERED, $target->fresh()?->status);
        $this->assertSame($source->patient_id, $target->fresh()?->active_inpatient_patient_id);
        $this->assertDatabaseMissing('emergency_handoff_compensations', ['handoff_id' => $handoff->id]);
        $this->assertDatabaseMissing('encounter_cancellations', ['encounter_id' => $target->id]);
    }

    public function test_compensation_fails_closed_after_child_progress_and_source_projection_is_read_only(): void
    {
        [$bed] = $this->beds();
        $source = $this->source();
        $disposition = $this->rawatInapDisposition($source);
        /** @var EmergencyInpatientHandoff $handoff */
        $handoff = app(EmergencyInpatientHandoffService::class)->execute(
            $source->public_id,
            $this->registrar,
            $disposition->version,
            $bed->public_id,
            'emergency-handoff-progress-0001',
        )->record;
        $target = $handoff->targetEncounter()->firstOrFail();

        $projection = app(EmergencyProjection::class)->sourceForInpatient($target, $this->physician);
        $this->assertSame($source->public_id, $projection['source_encounter']['public_id']);
        $this->assertSame('RAWAT_INAP', $projection['dispositions'][0]['code']);
        $this->assertFalse($projection['laboratory']['permissions']['can_order']);
        $this->assertNull($projection['laboratory']['commands']['create_order_url']);
        $this->assertFalse($projection['radiology']['permissions']['can_order']);
        $this->assertNull($projection['radiology']['commands']['create_order_url']);
        $this->assertFalse($projection['follow_up']['permission']['can_propose']);
        $this->assertFalse($projection['follow_up']['permission']['can_accept']);
        $this->assertSame([], $projection['follow_up']['physician_options']);

        ClinicalEntry::query()->create([
            'encounter_id' => $target->id,
            'author_user_id' => $this->physician->id,
            'entry_type' => ClinicalEntry::TYPE_MEDICAL_ASSESSMENT,
            'body' => 'Pelayanan rawat inap sudah dimulai.',
            'recorded_at' => now(),
        ]);
        $intent = app(EmergencyDispositionService::class)->createExecutedHandoffCorrectionIntent(
            $source->public_id,
            $this->physician,
            1,
            'PULANG',
            $this->pulangPayload(),
            'Permintaan koreksi setelah episode anak memiliki bukti pelayanan.',
            now()->addHour()->toIso8601String(),
            'emergency-intent-progress-0001',
        )->record;
        $this->assertTrue(
            $intent->fresh()->expires_at->isFuture(),
            'Expected future intent expiry; now='.now()->toIso8601String().'; stored='.$intent->fresh()->expires_at->toIso8601String(),
        );

        try {
            app(EmergencyInpatientHandoffCompensationService::class)->execute(
                $source->public_id,
                $this->registrar,
                $intent->public_id,
                'emergency-compensation-progress-0001',
            );
            $this->fail('A progressed inpatient child must not be compensated.');
        } catch (EmergencyDenied $denial) {
            $this->assertSame('child_progressed', $denial->reason);
        }

        $this->assertSame(Encounter::STATUS_REGISTERED, $target->fresh()->status);
        $this->assertSame($source->patient_id, $target->fresh()->active_inpatient_patient_id);
        $this->assertDatabaseMissing('emergency_handoff_compensations', ['handoff_id' => $handoff->id]);
    }

    public function test_only_author_physician_can_revoke_the_exact_pending_intent_and_expired_intent_fails_closed(): void
    {
        [$bed] = $this->beds();
        $source = $this->source();
        $disposition = $this->rawatInapDisposition($source);
        app(EmergencyInpatientHandoffService::class)->execute(
            $source->public_id,
            $this->registrar,
            $disposition->version,
            $bed->public_id,
            'emergency-handoff-revoke-0001',
        );
        $dispositions = app(EmergencyDispositionService::class);
        $fingerprints = app(EmergencyEvidenceFingerprint::class);
        $intent = $dispositions->createExecutedHandoffCorrectionIntent(
            $source->public_id,
            $this->physician,
            1,
            'PULANG',
            $this->pulangPayload(),
            'Dokter menyiapkan koreksi yang masih dapat dicabut.',
            now()->addHour()->toIso8601String(),
            'emergency-intent-revoke-0001',
        )->record;
        $fingerprint = $fingerprints->correctionIntent($intent);

        $otherPhysician = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        try {
            $dispositions->revokeCorrectionIntent(
                $intent->public_id,
                $otherPhysician,
                $fingerprint,
                'Aktor lain mencoba mencabut maksud koreksi.',
                'emergency-intent-revoke-wrong-author-0001',
            );
            $this->fail('Only the author physician may revoke the correction intent.');
        } catch (EmergencyDenied $denial) {
            $this->assertSame('correction_intent_revoke_not_permitted', $denial->reason);
        }

        try {
            $dispositions->revokeCorrectionIntent(
                $intent->public_id,
                $this->physician,
                str_repeat('0', 64),
                'Dokter mencoba sidik yang sudah tidak cocok.',
                'emergency-intent-revoke-stale-0001',
            );
            $this->fail('A stale intent fingerprint must fail closed.');
        } catch (EmergencyDenied $denial) {
            $this->assertSame('stale_correction_intent', $denial->reason);
        }

        $revoked = $dispositions->revokeCorrectionIntent(
            $intent->public_id,
            $this->physician,
            $fingerprint,
            'Rencana koreksi dicabut setelah evaluasi ulang.',
            'emergency-intent-revoke-success-0001',
        )->record;
        $this->assertSame('REVOKED', $revoked->event_type);

        try {
            app(EmergencyInpatientHandoffCompensationService::class)->execute(
                $source->public_id,
                $this->registrar,
                $intent->public_id,
                'emergency-compensation-revoked-0001',
            );
            $this->fail('A revoked correction intent must not execute compensation.');
        } catch (EmergencyDenied $denial) {
            $this->assertSame('correction_intent_not_pending', $denial->reason);
        }

        $expiring = $dispositions->createExecutedHandoffCorrectionIntent(
            $source->public_id,
            $this->physician,
            1,
            'PULANG',
            $this->pulangPayload(),
            'Maksud koreksi kedua digunakan untuk uji kedaluwarsa.',
            now()->addHour()->toIso8601String(),
            'emergency-intent-expiring-0001',
        )->record;
        Carbon::setTestNow(now()->addHours(2));
        try {
            $dispositions->revokeCorrectionIntent(
                $expiring->public_id,
                $this->physician,
                $fingerprints->correctionIntent($expiring),
                'Percobaan pencabutan setelah masa berlaku berakhir.',
                'emergency-intent-expired-revoke-0001',
            );
            $this->fail('An expired correction intent must fail closed.');
        } catch (EmergencyDenied $denial) {
            $this->assertSame('correction_intent_expired', $denial->reason);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_synthetic_reset_removes_the_complete_emergency_handoff_graph_in_dependency_order(): void
    {
        [$bed] = $this->beds();
        $source = $this->source();
        $patientId = $source->patient_id;
        $disposition = $this->rawatInapDisposition($source);
        app(EmergencyInpatientHandoffService::class)->execute(
            $source->public_id,
            $this->registrar,
            $disposition->version,
            $bed->public_id,
            'emergency-handoff-reset-0001',
        );

        $this->assertDatabaseCount('emergency_inpatient_handoffs', 1);
        $this->assertDatabaseCount('inpatient_location_events', 1);
        $this->assertDatabaseCount('emergency_operation_receipts', 1);

        app(SyntheticResetService::class)->reset([
            'actor' => $this->registrar,
            'reason' => 'emergency_handoff_reset_test',
        ]);

        $this->assertDatabaseMissing('patients', ['id' => $patientId]);
        $this->assertDatabaseCount('emergency_inpatient_handoffs', 0);
        $this->assertDatabaseCount('emergency_dispositions', 0);
        $this->assertDatabaseCount('emergency_operation_receipts', 0);
        $this->assertDatabaseCount('inpatient_location_events', 0);
        $this->assertDatabaseCount('inpatient_patient_claim_mutexes', 0);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'emergency.workflow.mutate',
            'outcome' => 'SUCCESS',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'teaching.reset.completed',
            'outcome' => 'SUCCESS',
        ]);
    }

    public function test_database_refuses_a_cross_patient_emergency_handoff_graph(): void
    {
        [$bed] = $this->beds();
        $source = $this->source();
        $disposition = $this->rawatInapDisposition($source);
        $otherPatient = Patient::factory()->create([
            'is_synthetic' => true,
            'created_by_user_id' => $this->registrar->id,
        ]);
        $admission = app(InpatientAdmissionService::class)->admitDirect(
            $otherPatient,
            $this->registrar,
            $bed->public_id,
            Encounter::PAYER_UMUM,
            null,
            Encounter::CONTINUE_LANGSUNG,
            'Pendaftaran pasien berbeda.',
            admissionAuthorityType: Encounter::AUTHORITY_PLANNED_ORDER,
            admissionAuthorityReference: 'ORDER-EMERGENCY-GRAPH-0001',
        );

        try {
            $this->insertHandoffGraph($source, $disposition, $admission->encounter, $admission->location, $bed);
            $this->fail('Database handoff guard must reject a cross-patient graph.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('invalid emergency inpatient handoff graph', $exception->getMessage());
        }
    }

    public function test_database_refuses_a_handoff_bound_to_a_non_inpatient_disposition(): void
    {
        [$bed] = $this->beds();
        $source = $this->source();
        $disposition = $this->disposition($source, 'PULANG', $this->pulangPayload());
        $admission = app(InpatientAdmissionService::class)->admitDirect(
            $source->patient()->firstOrFail(),
            $this->registrar,
            $bed->public_id,
            Encounter::PAYER_UMUM,
            null,
            Encounter::CONTINUE_LANGSUNG,
            'Pendaftaran langsung untuk uji ikatan.',
            admissionAuthorityType: Encounter::AUTHORITY_PLANNED_ORDER,
            admissionAuthorityReference: 'ORDER-EMERGENCY-GRAPH-0002',
        );

        try {
            $this->insertHandoffGraph($source, $disposition, $admission->encounter, $admission->location, $bed);
            $this->fail('Database handoff guard must require a RAWAT_INAP disposition.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('invalid emergency inpatient handoff graph', $exception->getMessage());
        }
    }

    /** @return array{InpatientBed, InpatientBed} */
    private function beds(): array
    {
        return InpatientMasterMutationScope::run(function (): array {
            $ward = InpatientWard::query()->create([
                'code' => 'RI-IGD',
                'display_name' => 'Rawat Inap IGD',
                'state' => InpatientWard::STATE_ACTIVE,
                'version' => 1,
            ]);
            $first = InpatientBed::query()->create([
                'ward_id' => $ward->id,
                'code' => 'IGD-RI-01',
                'display_name' => 'Tempat Tidur 01',
                'room_label' => 'Ruang Transisi',
                'service_class' => 'Kelas 1',
                'state' => InpatientBed::STATE_ACTIVE,
                'version' => 1,
            ]);
            $second = InpatientBed::query()->create([
                'ward_id' => $ward->id,
                'code' => 'IGD-RI-02',
                'display_name' => 'Tempat Tidur 02',
                'room_label' => 'Ruang Transisi',
                'service_class' => 'Kelas 1',
                'state' => InpatientBed::STATE_ACTIVE,
                'version' => 1,
            ]);
            $this->createBedVersion($first);
            $this->createBedVersion($second);

            return [$first, $second];
        });
    }

    private function createBedVersion(InpatientBed $bed): void
    {
        InpatientBedVersion::query()->create([
            'bed_id' => $bed->id,
            'actor_user_id' => $this->registrar->id,
            'version' => $bed->version,
            'display_name' => $bed->display_name,
            'room_label' => $bed->room_label,
            'service_class' => $bed->service_class,
            'state' => $bed->state,
            'reason_code' => 'INITIAL_SETUP',
            'before_digest' => null,
            'after_digest' => hash('sha256', CanonicalJson::encode([
                'code' => $bed->code,
                'display_name' => $bed->display_name,
                'room_label' => $bed->room_label,
                'service_class' => $bed->service_class,
                'state' => $bed->state,
                'version' => $bed->version,
            ])),
            'request_correlation_id' => null,
        ]);
    }

    private function source(?Patient $patient = null): Encounter
    {
        $patient ??= Patient::factory()->create([
            'is_synthetic' => true,
            'created_by_user_id' => $this->registrar->id,
        ]);

        return Encounter::factory()->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $this->registrar->id,
            'care_setting' => Encounter::CARE_SETTING_EMERGENCY,
            'status' => Encounter::STATUS_IN_EXAMINATION,
            'clinic_name' => 'Instalasi Gawat Darurat',
            'payer_type' => Encounter::PAYER_UMUM,
            'insurance_number' => null,
            'queue_date' => now()->toDateString(),
            'queue_number' => fake()->unique()->numberBetween(20, 900),
            'registered_at' => now()->subMinutes(30),
        ]);
    }

    private function pharmacyPrescription(Encounter $encounter, string $status, string $depotCode): PharmacyPrescription
    {
        return PharmacyMutationScope::run(function () use ($encounter, $status, $depotCode): PharmacyPrescription {
            $depot = PharmacyDepot::query()->create([
                'depot_code' => $depotCode,
                'display_name' => 'Depo Bukti Serah Terima',
                'eligible_care_settings' => [$encounter->care_setting],
                'state' => PharmacyDepot::ACTIVE,
                'version' => 1,
                'current_content_digest' => str_repeat('a', 64),
            ]);

            return PharmacyPrescription::query()->create([
                'encounter_id' => $encounter->id,
                'patient_id' => $encounter->patient_id,
                'ordering_physician_user_id' => $this->physician->id,
                'depot_id' => $depot->id,
                'care_setting' => $encounter->care_setting,
                'encounter_number_snapshot' => $encounter->public_id,
                'location_snapshot' => $encounter->clinic_name,
                'depot_version' => 1,
                'depot_code_snapshot' => $depot->depot_code,
                'status' => $status,
                'version' => 1,
                'current_content_digest' => str_repeat('b', 64),
                'ordered_at' => $status === PharmacyPrescription::DRAFT ? null : now(),
            ]);
        });
    }

    private function rawatInapDisposition(Encounter $source): EmergencyDisposition
    {
        return $this->disposition($source, 'RAWAT_INAP', [
            'admission_reason' => 'Pasien membutuhkan pemantauan lanjutan.',
            'receiving_unit_handoff_note' => 'Serah terima ke bangsal penerima.',
        ]);
    }

    /** @param array<string, string> $payload */
    private function disposition(Encounter $source, string $type, array $payload): EmergencyDisposition
    {
        $fingerprints = app(EmergencyEvidenceFingerprint::class);
        $signedAt = now()->startOfSecond();
        $attributes = [
            'encounter_id' => $source->id,
            'physician_user_id' => $this->physician->id,
            'prior_disposition_id' => null,
            'version' => 1,
            'disposition_type' => $type,
            'payload' => $payload,
            'correction_reason' => null,
            'prior_disposition_digest' => null,
            'signed_at' => $signedAt,
            'created_at' => $signedAt,
        ];
        $attributes['content_digest'] = $fingerprints->dispositionPayload(
            $source->id,
            $this->physician->id,
            null,
            1,
            $type,
            $payload,
            null,
            null,
            $signedAt->toJSON(),
        );

        return EmergencyMutationScope::run(fn (): EmergencyDisposition => EmergencyDisposition::query()->create($attributes));
    }

    private function insertHandoffGraph(
        Encounter $source,
        EmergencyDisposition $disposition,
        Encounter $target,
        InpatientLocationEvent $location,
        InpatientBed $bed,
    ): void {
        $fingerprints = app(EmergencyEvidenceFingerprint::class);
        $now = now()->startOfSecond();
        DB::table((new EmergencyInpatientHandoff)->getTable())->insert([
            'public_id' => (string) Str::ulid(),
            'source_encounter_id' => $source->id,
            'disposition_id' => $disposition->id,
            'target_encounter_id' => $target->id,
            'inpatient_location_event_id' => $location->id,
            'inpatient_bed_id' => $bed->id,
            'actor_user_id' => $this->registrar->id,
            'inpatient_bed_version' => $bed->version,
            'bed_snapshot' => json_encode([
                'bed_public_id' => $bed->public_id,
                'bed_code' => $bed->code,
            ], JSON_THROW_ON_ERROR),
            'source_encounter_fingerprint' => $fingerprints->encounter($source),
            'disposition_fingerprint' => $fingerprints->disposition($disposition),
            'target_encounter_fingerprint' => $fingerprints->encounter($target),
            'location_event_fingerprint' => $fingerprints->location($location),
            'content_digest' => str_repeat('a', 64),
            'handed_off_at' => $now,
            'created_at' => $now,
        ]);
    }

    /** @return array<string, string> */
    private function pulangPayload(): array
    {
        return [
            'condition_at_discharge' => 'Stabil',
            'instructions' => 'Istirahat dan minum cukup.',
            'warning_signs' => 'Kembali bila keluhan memburuk.',
            'follow_up_plan' => 'Kontrol sesuai jadwal.',
        ];
    }

    private function actor(string $role): User
    {
        $actor = User::factory()->create([
            'status' => 'ACTIVE',
            'is_system_administrator' => false,
        ]);
        $actor->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $actor;
    }
}
