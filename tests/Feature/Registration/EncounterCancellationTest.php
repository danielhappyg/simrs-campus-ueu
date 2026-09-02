<?php

namespace Tests\Feature\Registration;

use App\Models\ClinicalEntry;
use App\Models\Encounter;
use App\Models\EncounterCancellation;
use App\Models\LabServiceRequest;
use App\Models\OutpatientClinicalDocument;
use App\Models\OutpatientRmCompletenessReview;
use App\Models\Patient;
use App\Models\PharmacyDepot;
use App\Models\PharmacyPrescription;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Inpatient\InpatientLocationMutationScope;
use App\Support\Pharmacy\PharmacyMutationScope;
use App\Support\Registration\EncounterCancellationDenied;
use App\Support\Registration\EncounterCancellationRaceReconciler;
use App\Support\Registration\InpatientBedClaimGuard;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Mockery;
use Mockery\CompositeExpectation;
use PDOException;
use Tests\TestCase;

class EncounterCancellationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_registrar_can_cancel_a_registered_encounter_once_with_atomic_safe_audit(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $encounter = $this->encounter($registrar, [
            'booking_code' => 'BOOK-SYNTH-01',
            'queue_number' => 17,
            'queue_date' => '2026-08-30',
        ]);

        $this->actingAs($registrar)
            ->post(route('pendaftaran.kunjungan.batalkan', $encounter), $this->payload([
                'note' => 'Permintaan pasien sintetis.',
            ]))
            ->assertRedirect();

        $encounter->refresh();
        $cancellation = EncounterCancellation::query()->sole();
        $event = AuditEvent::query()->where('action', 'encounter.cancel')->sole();

        $this->assertSame(Encounter::STATUS_CANCELLED, $encounter->status);
        $this->assertTrue($encounter->isCancelled());
        $this->assertTrue($encounter->isTerminal());
        $this->assertFalse($encounter->isActive());
        $this->assertSame('BOOK-SYNTH-01', $encounter->booking_code);
        $this->assertSame(17, $encounter->queue_number);
        $this->assertSame($cancellation->id, $encounter->cancellation?->id);
        $this->assertSame($registrar->id, $cancellation->cancelled_by_user_id);
        $this->assertSame(EncounterCancellation::REASON_WRONG_REGISTRATION, $cancellation->reason_code);
        $this->assertSame('SUCCESS', $event->outcome);
        $this->assertSame($cancellation->public_id, $event->metadata['cancellation_public_id']);
        $this->assertArrayNotHasKey('note', $event->metadata);
        $this->assertArrayNotHasKey('idempotency_key', $event->metadata);
        $this->assertArrayNotHasKey('payload_digest', $event->metadata);
    }

    public function test_same_actor_key_and_payload_replays_without_a_second_fact_or_success_audit(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $encounter = $this->encounter($registrar);
        $idempotencyKey = 'cancel-request-0001';
        $payload = $this->payload(['idempotency_key' => $idempotencyKey]);

        $this->actingAs($registrar)->post(route('pendaftaran.kunjungan.batalkan', $encounter), $payload)->assertRedirect();
        $this->actingAs($registrar)->post(route('pendaftaran.kunjungan.batalkan', $encounter), $payload)->assertRedirect();

        $this->assertDatabaseCount('encounter_cancellations', 1);
        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'encounter.cancel')
            ->where('outcome', 'SUCCESS')
            ->count());
    }

    public function test_reused_key_with_changed_payload_is_audited_and_denied(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $encounter = $this->encounter($registrar);

        $this->actingAs($registrar)
            ->post(route('pendaftaran.kunjungan.batalkan', $encounter), $this->payload())
            ->assertRedirect();

        $this->actingAs($registrar)
            ->post(route('pendaftaran.kunjungan.batalkan', $encounter), $this->payload([
                'reason_code' => EncounterCancellation::REASON_DUPLICATE_ENCOUNTER,
            ]))
            ->assertStatus(409);

        $this->assertDatabaseCount('encounter_cancellations', 1);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'encounter.cancel',
            'resource_id' => $encounter->public_id,
            'outcome' => 'DENIED',
            'reason' => 'idempotency_key_conflict',
        ]);
    }

    public function test_unique_race_reconciler_deterministically_replays_or_audits_a_conflict(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $encounter = $this->encounter($registrar);
        $idempotencyKey = 'cancel-race-request-0001';
        $payload = $this->payload(['idempotency_key' => $idempotencyKey]);
        $this->actingAs($registrar)
            ->post(route('pendaftaran.kunjungan.batalkan', $encounter), $payload)
            ->assertRedirect();

        $cancellation = EncounterCancellation::query()->sole();
        $race = new UniqueConstraintViolationException(
            'testing',
            'insert into encounter_cancellations (...) values (...)',
            [],
            new PDOException('deterministic duplicate-key race'),
        );
        $reconciler = app(EncounterCancellationRaceReconciler::class);

        $replay = $reconciler->resolve(
            encounter: $encounter,
            actor: $registrar,
            idempotencyKey: $idempotencyKey,
            digest: $cancellation->payload_digest,
            race: $race,
        );
        $this->assertTrue($replay->replayed);
        $this->assertSame($cancellation->id, $replay->cancellation->id);

        try {
            $reconciler->resolve(
                encounter: $encounter,
                actor: $registrar,
                idempotencyKey: $idempotencyKey,
                digest: str_repeat('f', 64),
                race: $race,
            );
            $this->fail('A raced receipt with a changed payload must be rejected.');
        } catch (EncounterCancellationDenied $denial) {
            $this->assertSame('idempotency_key_conflict', $denial->reason);
        }

        $this->assertDatabaseCount('encounter_cancellations', 1);
        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'encounter.cancel')
            ->where('outcome', 'SUCCESS')
            ->count());
        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'encounter.cancel')
            ->where('outcome', 'DENIED')
            ->where('reason', 'idempotency_key_conflict')
            ->count());
    }

    public function test_non_registered_and_dependent_encounters_are_denied_without_mutation(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $clinician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);

        $notRegistered = $this->encounter($registrar, ['status' => Encounter::STATUS_IN_EXAMINATION]);
        $this->actingAs($registrar)
            ->post(route('pendaftaran.kunjungan.batalkan', $notRegistered), $this->payload(['idempotency_key' => 'cancel-not-registered-01']))
            ->assertStatus(409);
        $this->assertSame(Encounter::STATUS_IN_EXAMINATION, $notRegistered->fresh()->status);

        $dependencies = [
            'clinical_activity_exists' => function (Encounter $encounter) use ($clinician): void {
                ClinicalEntry::factory()->create([
                    'encounter_id' => $encounter->id,
                    'author_user_id' => $clinician->id,
                ]);
            },
            'clinical_document_activity_exists' => function (Encounter $encounter) use ($clinician): void {
                OutpatientClinicalDocument::query()->create([
                    'encounter_id' => $encounter->id,
                    'author_user_id' => $clinician->id,
                    'document_type' => OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT,
                    'document_state' => OutpatientClinicalDocument::STATE_DRAFT,
                    'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
                    'version' => 1,
                    'fields' => [],
                ]);
            },
            'diagnostic_activity_exists' => function (Encounter $encounter) use ($clinician): void {
                LabServiceRequest::factory()->create([
                    'encounter_id' => $encounter->id,
                    'requested_by_user_id' => $clinician->id,
                ]);
            },
            'rm_activity_exists' => function (Encounter $encounter) use ($clinician): void {
                OutpatientRmCompletenessReview::query()->create([
                    'encounter_id' => $encounter->id,
                    'reviewed_by_user_id' => $clinician->id,
                    'definition_version' => OutpatientRmCompletenessReview::DEFINITION_VERSION,
                    'version' => 1,
                    'source_fingerprint' => str_repeat('a', 64),
                    'review_state' => OutpatientRmCompletenessReview::STATE_DRAFT,
                    'reviewed_at' => now(),
                ]);
            },
        ];

        foreach ($dependencies as $case => $createDependency) {
            $encounter = $this->encounter($registrar);
            $createDependency($encounter);
            $expectedReason = $case === 'clinical_document_activity_exists' ? 'clinical_activity_exists' : $case;

            $this->actingAs($registrar)
                ->post(route('pendaftaran.kunjungan.batalkan', $encounter), $this->payload([
                    'idempotency_key' => 'cancel-'.$case.'-01',
                ]))
                ->assertStatus(409);

            $this->assertSame(Encounter::STATUS_REGISTERED, $encounter->fresh()->status);
            $this->assertDatabaseHas('audit_events', [
                'action' => 'encounter.cancel',
                'resource_id' => $encounter->public_id,
                'outcome' => 'DENIED',
                'reason' => $expectedReason,
            ]);
        }

        $this->assertDatabaseCount('encounter_cancellations', 0);
    }

    public function test_any_pharmacy_prescription_evidence_blocks_preclinical_cancellation(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $encounter = $this->encounter($registrar);
        PharmacyMutationScope::run(function () use ($encounter, $physician): void {
            $depot = PharmacyDepot::query()->create([
                'depot_code' => 'DEPO_RJ_CANCEL',
                'display_name' => 'Depo Rawat Jalan Pembatalan',
                'eligible_care_settings' => [Encounter::CARE_SETTING_OUTPATIENT],
                'state' => PharmacyDepot::ACTIVE,
                'version' => 1,
                'current_content_digest' => str_repeat('a', 64),
            ]);
            PharmacyPrescription::query()->create([
                'encounter_id' => $encounter->id,
                'patient_id' => $encounter->patient_id,
                'ordering_physician_user_id' => $physician->id,
                'depot_id' => $depot->id,
                'care_setting' => $encounter->care_setting,
                'encounter_number_snapshot' => $encounter->public_id,
                'location_snapshot' => $encounter->clinic_name,
                'depot_version' => 1,
                'depot_code_snapshot' => $depot->depot_code,
                'status' => PharmacyPrescription::DRAFT,
                'version' => 1,
                'current_content_digest' => str_repeat('b', 64),
            ]);
        });

        $this->actingAs($registrar)
            ->post(route('pendaftaran.kunjungan.batalkan', $encounter), $this->payload([
                'idempotency_key' => 'cancel-pharmacy-evidence-01',
            ]))
            ->assertStatus(409);

        $this->assertSame(Encounter::STATUS_REGISTERED, $encounter->fresh()?->status);
        $this->assertDatabaseCount('encounter_cancellations', 0);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'encounter.cancel',
            'resource_id' => $encounter->public_id,
            'outcome' => 'DENIED',
            'reason' => 'pharmacy_evidence_exists',
        ]);
    }

    public function test_actor_without_cancel_capability_is_denied_before_state_disclosure(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);
        $encounter = $this->encounter($registrar, ['status' => Encounter::STATUS_CLOSED]);

        $existing = $this->actingAs($nurse)
            ->post(route('pendaftaran.kunjungan.batalkan', $encounter), $this->payload())
            ->assertForbidden();
        $unknown = $this->actingAs($nurse)
            ->post(route('pendaftaran.kunjungan.batalkan', '01ARZ3NDEKTSV4RRFFQ69G5FAV'), $this->payload())
            ->assertForbidden();

        $this->assertSame($existing->getStatusCode(), $unknown->getStatusCode());
        $this->assertDatabaseCount('encounter_cancellations', 0);
        $this->assertDatabaseMissing('audit_events', ['action' => 'encounter.cancel']);
        $this->assertSame(2, AuditEvent::query()
            ->where('action', 'authorization.denied')
            ->where('resource_id', 'pendaftaran.kunjungan.batalkan')
            ->where('outcome', 'DENIED')
            ->count());
        $this->assertDatabaseCount('audit_events', 2);
    }

    public function test_inertia_business_denial_returns_a_form_error_instead_of_an_error_modal_response(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $encounter = $this->encounter($registrar, ['status' => Encounter::STATUS_CLOSED]);

        $this->actingAs($registrar)
            ->from(route('pendaftaran.rekap'))
            ->withHeader('X-Inertia', 'true')
            ->post(route('pendaftaran.kunjungan.batalkan', $encounter), $this->payload())
            ->assertRedirect(route('pendaftaran.rekap'))
            ->assertSessionHasErrors([
                'cancellation' => 'Hanya kunjungan yang masih terdaftar dan belum menerima pelayanan yang dapat dibatalkan.',
            ]);

        $this->assertDatabaseCount('encounter_cancellations', 0);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'encounter.cancel',
            'resource_id' => $encounter->public_id,
            'outcome' => 'DENIED',
            'reason' => 'encounter_not_registered',
        ]);
    }

    public function test_inertia_audit_failure_returns_a_form_error_and_rolls_back_cancellation(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $encounter = $this->encounter($registrar);
        $recorder = Mockery::mock(AuditRecorder::class);
        $expectation = $recorder->shouldReceive('record');
        $this->assertInstanceOf(CompositeExpectation::class, $expectation);
        $expectation->andReturn(null);
        $this->app->instance(AuditRecorder::class, $recorder);

        $this->actingAs($registrar)
            ->from(route('pendaftaran.rekap'))
            ->withHeader('X-Inertia', 'true')
            ->post(route('pendaftaran.kunjungan.batalkan', $encounter), $this->payload())
            ->assertRedirect(route('pendaftaran.rekap'))
            ->assertSessionHasErrors([
                'cancellation' => 'Pembatalan tidak dapat disimpan karena audit gagal direkam.',
            ]);

        $this->assertSame(Encounter::STATUS_REGISTERED, $encounter->fresh()->status);
        $this->assertDatabaseCount('encounter_cancellations', 0);
    }

    public function test_audit_failure_rolls_back_fact_and_status(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $encounter = $this->encounter($registrar);
        $recorder = Mockery::mock(AuditRecorder::class);
        $expectation = $recorder->shouldReceive('record');
        $this->assertInstanceOf(CompositeExpectation::class, $expectation);
        $expectation->andReturn(null);
        $this->app->instance(AuditRecorder::class, $recorder);

        $this->actingAs($registrar)
            ->post(route('pendaftaran.kunjungan.batalkan', $encounter), $this->payload())
            ->assertStatus(503);

        $this->assertSame(Encounter::STATUS_REGISTERED, $encounter->fresh()->status);
        $this->assertDatabaseCount('encounter_cancellations', 0);
    }

    public function test_cancelled_inpatient_no_longer_occupies_its_bed_and_print_is_denied_with_audit(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $encounter = $this->encounter($registrar, [
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'ward_name' => 'Ruang Sintetis',
            'ward_class' => 'KELAS_1',
            'bed_code' => 'SYNTH-BED-01',
        ]);

        $this->assertTrue($encounter->occupiesInpatientBed());
        $this->actingAs($registrar)
            ->post(route('pendaftaran.kunjungan.batalkan', $encounter), $this->payload())
            ->assertRedirect();
        $this->assertFalse($encounter->fresh()->occupiesInpatientBed());

        DB::transaction(fn () => app(InpatientBedClaimGuard::class)->assertAvailable('SYNTH-BED-01'));

        $this->actingAs($registrar)
            ->get(route('pendaftaran.kunjungan.cetak', $encounter).'?docs=bukti')
            ->assertStatus(409);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'encounter.print',
            'resource_id' => $encounter->public_id,
            'outcome' => 'DENIED',
            'reason' => 'encounter_cancelled',
        ]);
    }

    public function test_cancellation_fact_cannot_be_updated_or_deleted_through_the_model(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $encounter = $this->encounter($registrar);
        $this->actingAs($registrar)
            ->post(route('pendaftaran.kunjungan.batalkan', $encounter), $this->payload())
            ->assertRedirect();

        $cancellation = EncounterCancellation::query()->sole();
        try {
            $cancellation->update(['note' => 'Tidak boleh berubah']);
            $this->fail('Expected immutable cancellation update to fail.');
        } catch (LogicException $exception) {
            $this->assertSame('Encounter cancellation facts are immutable.', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $cancellation->delete();
    }

    /** @param array<string, mixed> $overrides */
    private function encounter(User $registrar, array $overrides = []): Encounter
    {
        $patient = Patient::factory()->create(['created_by_user_id' => $registrar->id]);
        $nextQueueNumber = ((int) Encounter::query()->max('queue_number')) + 1;

        return InpatientLocationMutationScope::run(fn (): Encounter => Encounter::factory()->create(array_merge([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $registrar->id,
            'status' => Encounter::STATUS_REGISTERED,
            'queue_date' => now()->toDateString(),
            'queue_number' => $nextQueueNumber,
        ], $overrides)));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'reason_code' => EncounterCancellation::REASON_WRONG_REGISTRATION,
            'note' => null,
            'idempotency_key' => 'cancel-request-0001',
        ], $overrides);
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
