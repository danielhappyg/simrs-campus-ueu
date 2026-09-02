<?php

namespace Tests\Feature\Clinical;

use App\Models\ClinicalEntry;
use App\Models\Encounter;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Clinical\LockedClinicalEntryWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\CompositeExpectation;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LockedClinicalEntryWriterTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_writer_preserves_existing_igd_and_ri_state_transitions(): void
    {
        $actor = User::factory()->create();
        $writer = app(LockedClinicalEntryWriter::class);

        $emergency = Encounter::factory()->create([
            'care_setting' => Encounter::CARE_SETTING_EMERGENCY,
            'status' => Encounter::STATUS_REGISTERED,
        ]);
        $inpatient = Encounter::factory()->create([
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'status' => Encounter::STATUS_REGISTERED,
        ]);

        $writer->write(
            $emergency,
            $actor,
            Encounter::CARE_SETTING_EMERGENCY,
            ClinicalEntry::TYPE_NURSING_INTAKE,
            'Asesmen awal IGD sintetis.',
        );
        $writer->write(
            $inpatient,
            $actor,
            Encounter::CARE_SETTING_INPATIENT,
            ClinicalEntry::TYPE_MEDICAL_ASSESSMENT,
            'Asesmen medis RI sintetis.',
        );

        $this->assertSame(Encounter::STATUS_IN_EXAMINATION, $emergency->fresh()->status);
        $this->assertSame(Encounter::STATUS_READY_FOR_RM, $inpatient->fresh()->status);
        $this->assertDatabaseCount('clinical_entries', 2);
        $this->assertDatabaseCount('audit_events', 2);
    }

    public function test_writer_reloads_and_denies_a_stale_route_model_after_state_changes(): void
    {
        $actor = User::factory()->create();
        $encounter = Encounter::factory()->create([
            'care_setting' => Encounter::CARE_SETTING_EMERGENCY,
            'status' => Encounter::STATUS_REGISTERED,
        ]);
        $staleEncounter = Encounter::query()->findOrFail($encounter->id);
        Encounter::query()->whereKey($encounter->id)->update(['status' => Encounter::STATUS_CLOSED]);

        try {
            app(LockedClinicalEntryWriter::class)->write(
                $staleEncounter,
                $actor,
                Encounter::CARE_SETTING_EMERGENCY,
                ClinicalEntry::TYPE_NURSING_INTAKE,
                'Catatan dari permintaan stale.',
            );
            $this->fail('A stale route model must not bypass the current database state.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertSame('Kunjungan tidak dapat menerima catatan klinis.', $exception->getMessage());
        }

        $this->assertDatabaseCount('clinical_entries', 0);
        $this->assertDatabaseCount('audit_events', 0);
        $this->assertSame(Encounter::STATUS_CLOSED, $encounter->fresh()->status);
    }

    public function test_writer_fails_closed_for_an_unregistered_status(): void
    {
        $actor = User::factory()->create();
        $encounter = Encounter::factory()->create([
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'status' => 'UNRECOGNIZED_STATE',
        ]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Kunjungan tidak dapat menerima catatan klinis.');

        app(LockedClinicalEntryWriter::class)->write(
            $encounter,
            $actor,
            Encounter::CARE_SETTING_INPATIENT,
            ClinicalEntry::TYPE_NURSING_INTAKE,
            'Catatan yang tidak boleh disimpan.',
        );
    }

    public function test_writer_audits_a_locked_cancelled_encounter_denial_without_clinical_mutation(): void
    {
        $actor = User::factory()->create();
        $encounter = Encounter::factory()->create([
            'care_setting' => Encounter::CARE_SETTING_EMERGENCY,
            'status' => Encounter::STATUS_CANCELLED,
        ]);

        try {
            app(LockedClinicalEntryWriter::class)->write(
                $encounter,
                $actor,
                Encounter::CARE_SETTING_EMERGENCY,
                ClinicalEntry::TYPE_NURSING_INTAKE,
                'Catatan yang tidak boleh disimpan.',
            );
            $this->fail('A cancelled encounter must reject clinical entry writes.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $event = AuditEvent::query()->sole();
        $this->assertSame('clinical.note.write', $event->action);
        $this->assertSame('DENIED', $event->outcome);
        $this->assertSame('encounter_cancelled', $event->reason);
        $this->assertSame($encounter->public_id, $event->resource_id);
        $this->assertSame(['care_setting' => Encounter::CARE_SETTING_EMERGENCY], $event->metadata);
        $this->assertDatabaseCount('clinical_entries', 0);
    }

    public function test_writer_fails_closed_when_cancelled_denial_audit_cannot_persist(): void
    {
        $actor = User::factory()->create();
        $encounter = Encounter::factory()->create([
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'status' => Encounter::STATUS_CANCELLED,
        ]);
        $recorder = Mockery::mock(AuditRecorder::class);
        $expectation = $recorder->shouldReceive('record');
        $this->assertInstanceOf(CompositeExpectation::class, $expectation);
        $expectation->andReturn(null);
        $this->app->instance(AuditRecorder::class, $recorder);

        try {
            app(LockedClinicalEntryWriter::class)->write(
                $encounter,
                $actor,
                Encounter::CARE_SETTING_INPATIENT,
                ClinicalEntry::TYPE_MEDICAL_ASSESSMENT,
                'Catatan yang tidak boleh disimpan.',
            );
            $this->fail('Missing denial audit must fail closed.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('clinical_entries', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_audit_failure_rolls_back_entry_and_status_in_the_shared_writer(): void
    {
        $actor = User::factory()->create();
        $encounter = Encounter::factory()->create([
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'status' => Encounter::STATUS_REGISTERED,
        ]);
        $recorder = Mockery::mock(AuditRecorder::class);
        $expectation = $recorder->shouldReceive('record');
        $this->assertInstanceOf(CompositeExpectation::class, $expectation);
        $expectation->andReturn(null);
        $this->app->instance(AuditRecorder::class, $recorder);
        $writer = app(LockedClinicalEntryWriter::class);

        try {
            $writer->write(
                $encounter,
                $actor,
                Encounter::CARE_SETTING_INPATIENT,
                ClinicalEntry::TYPE_NURSING_INTAKE,
                'Catatan yang wajib dibatalkan bersama audit.',
            );
            $this->fail('Audit failure must abort the clinical mutation.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('clinical_entries', 0);
        $this->assertSame(Encounter::STATUS_REGISTERED, $encounter->fresh()->status);
    }
}
