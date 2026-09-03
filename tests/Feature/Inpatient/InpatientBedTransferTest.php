<?php

namespace Tests\Feature\Inpatient;

use App\Models\Encounter;
use App\Models\EncounterCancellation;
use App\Models\InpatientBed;
use App\Models\InpatientLocationEvent;
use App\Models\InpatientLocationOperationReceipt;
use App\Models\InpatientWard;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\PharmacyDepot;
use App\Models\PharmacyMedicine;
use App\Models\PharmacyPreparation;
use App\Models\PharmacyPrescription;
use App\Models\PharmacyPrescriptionItem;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Inpatient\CanonicalInpatientBedOperationLockCoordinator;
use App\Support\Inpatient\InpatientBedTransferActorPolicy;
use App\Support\Inpatient\InpatientBedTransferAuditUnavailable;
use App\Support\Inpatient\InpatientBedTransferDenied;
use App\Support\Inpatient\InpatientBedTransferService;
use App\Support\Inpatient\InpatientLocationHistoryProjection;
use App\Support\Inpatient\InpatientLocationMutationScope;
use App\Support\Inpatient\InpatientMasterMutationScope;
use App\Support\Inpatient\InpatientMasterService;
use App\Support\Pharmacy\PharmacyEncounterLifecycleGate;
use App\Support\Pharmacy\PharmacyMutationScope;
use App\Support\Simulation\SyntheticResetService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Mockery;
use Tests\TestCase;

class InpatientBedTransferTest extends TestCase
{
    use RefreshDatabase;

    private User $registrar;

    private InpatientWard $ward;

    private InpatientBed $source;

    private InpatientBed $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $admin = $this->userWithRole(RoleCapabilityMatrix::ROLE_ADMIN);
        $service = app(InpatientMasterService::class);
        $ward = $service->createWard(
            $admin, 'RI-TRANSFER', 'Bangsal Transfer', InpatientMasterService::REASON_INITIAL_SETUP,
            'transfer-ward-0001', null,
        )->master;
        if (! $ward instanceof InpatientWard) {
            throw new LogicException('Expected ward result.');
        }
        $this->ward = $ward;
        $this->source = $this->createBed($service, $admin, 'TR-01', 'Tempat Tidur TR-01', 'transfer-bed-0001');
        $this->target = $this->createBed($service, $admin, 'TR-02', 'Tempat Tidur TR-02', 'transfer-bed-0002');
    }

    public function test_registrar_transfers_legacy_placement_atomically_and_identical_retry_replays(): void
    {
        $encounter = $this->legacyEncounter($this->source);
        $payload = $this->payload($encounter, $this->source, $this->target, 'Alasan klinis operasional', 'transfer-command-0001');

        $this->actingAs($this->registrar)
            ->post(route('pendaftaran.rawat-inap.bed-transfer', $encounter->public_id), $payload)
            ->assertRedirect(route('pemeriksaan.rawat-inap.show', $encounter->public_id));

        $fresh = $encounter->fresh();
        $this->assertSame($this->target->id, $fresh?->inpatient_bed_id);
        $this->assertSame($this->target->code, $fresh?->bed_code);
        $this->assertSame($this->ward->display_name, $fresh?->clinic_name);
        $this->assertDatabaseCount('inpatient_location_events', 1);
        $this->assertDatabaseCount('inpatient_location_operation_receipts', 1);
        $event = InpatientLocationEvent::query()->sole();
        $this->assertSame(InpatientLocationEvent::TYPE_TRANSFER, $event->event_type);
        $this->assertSame(1, $event->sequence);
        $this->assertSame($this->source->public_id, $event->from_bed_public_id);
        $this->assertSame($this->target->public_id, $event->to_bed_public_id);
        $audit = AuditEvent::query()->where('action', 'inpatient.bed.transfer')->where('outcome', 'SUCCESS')->sole();
        $this->assertNull($audit->reason);
        $this->assertArrayNotHasKey('reason', $audit->metadata);
        $this->assertSame($event->public_id, $audit->metadata['event_public_id']);

        $this->actingAs($this->registrar)
            ->post(route('pendaftaran.rawat-inap.bed-transfer', $encounter->public_id), [
                ...$payload,
                'idempotency_key' => 'TRANSFER-COMMAND-0001',
            ])
            ->assertRedirect(route('pemeriksaan.rawat-inap.show', $encounter->public_id));

        $this->assertDatabaseCount('inpatient_location_events', 1);
        $this->assertDatabaseCount('inpatient_location_operation_receipts', 1);
        $this->assertSame(1, AuditEvent::query()->where('action', 'inpatient.bed.transfer')->where('outcome', 'SUCCESS')->count());
    }

    public function test_projection_is_honest_before_and_after_first_transfer(): void
    {
        $encounter = $this->legacyEncounter($this->source);
        $projection = app(InpatientLocationHistoryProjection::class)->forEncounter($encounter, $this->registrar);
        $this->assertSame('LEGACY_CURRENT_PLACEMENT', $projection['history_baseline']);
        $this->assertFalse($projection['history_complete']);
        $this->assertSame(0, $projection['current_sequence']);
        $this->assertTrue($projection['transfer']['allowed']);
        $this->assertSame($this->source->public_id, $projection['transfer']['expected_source_bed_public_id']);
        $this->assertSame([$this->target->public_id], array_column($projection['transfer']['target_beds'], 'bed_public_id'));

        app(InpatientBedTransferService::class)->transfer(
            $encounter->public_id, $this->registrar, 0, $this->source->public_id,
            $this->target->public_id, 'Pindah untuk kebutuhan layanan', 'projection-transfer-0001', null,
        );
        $after = app(InpatientLocationHistoryProjection::class)->forEncounter($encounter->fresh(), $this->registrar);
        $this->assertNull($after['history_baseline']);
        $this->assertFalse($after['history_complete']);
        $this->assertSame(1, $after['current_sequence']);
        $this->assertSame('BED_TRANSFER', $after['events'][0]['event_type']);
        $this->assertSame($this->source->public_id, $after['events'][0]['from_placement']['bed_public_id']);
        $this->assertSame($this->target->public_id, $after['events'][0]['to_placement']['bed_public_id']);
    }

    public function test_active_pharmacy_preparation_blocks_bed_transfer_without_moving_the_patient(): void
    {
        $encounter = $this->legacyEncounter($this->source);
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $technician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHARMACY_TECHNICIAN);
        [$prescription] = $this->pharmacyPrescription($encounter, $physician, PharmacyPrescription::PREPARED);
        PharmacyMutationScope::run(fn () => PharmacyPreparation::query()->create([
            'prescription_id' => $prescription->id,
            'technician_user_id' => $technician->id,
            'sequence' => 1,
            'state' => PharmacyPreparation::ACTIVE,
            'prescription_fingerprint' => str_repeat('d', 64),
            'stock_fingerprint' => str_repeat('e', 64),
            'content_digest' => str_repeat('f', 64),
            'prepared_at' => now(),
            'created_at' => now(),
        ]));

        try {
            app(InpatientBedTransferService::class)->transfer(
                $encounter->public_id,
                $this->registrar,
                0,
                $this->source->public_id,
                $this->target->public_id,
                'Transfer setelah penyiapan obat selesai',
                'pharmacy-preparation-transfer-0001',
            );
            $this->fail('Expected active pharmacy preparation denial.');
        } catch (InpatientBedTransferDenied $denial) {
            $this->assertSame('active_pharmacy_preparation', $denial->reason);
        }

        $this->assertSame($this->source->id, $encounter->fresh()?->inpatient_bed_id);
        $this->assertDatabaseCount('inpatient_location_events', 0);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'inpatient.bed.transfer',
            'resource_id' => $encounter->public_id,
            'outcome' => 'DENIED',
            'reason' => 'active_pharmacy_preparation',
        ]);
    }

    public function test_wrong_role_is_denied_before_lookup_with_safe_audit_only(): void
    {
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);
        $this->actingAs($nurse)->post(route('pendaftaran.rawat-inap.bed-transfer', Str::ulid()->toBase32()), [
            'unexpected' => 'must not reach shape validation',
        ])->assertForbidden();

        $this->assertDatabaseCount('inpatient_location_events', 0);
        $this->assertDatabaseCount('inpatient_location_operation_receipts', 0);
        $audit = AuditEvent::query()->where('action', 'inpatient.bed.transfer')->where('outcome', 'DENIED')->sole();
        $this->assertSame('unauthorized_actor', $audit->reason);
        $this->assertNull($audit->resource_id);
        $this->assertNull($audit->metadata);
    }

    public function test_actor_policy_requires_the_exact_registrar_role_and_capability_without_admin_implication(): void
    {
        $policy = app(InpatientBedTransferActorPolicy::class);
        $this->assertTrue($policy->can($this->registrar));
        $this->assertFalse($policy->can($this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE)));
        $this->assertFalse($policy->can($this->userWithRole(RoleCapabilityMatrix::ROLE_ADMIN)));

        $systemRegistrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $systemRegistrar->update(['is_system_administrator' => true]);
        $this->assertTrue($policy->can($systemRegistrar->fresh()));

        $adminRegistrar = User::factory()->create();
        $adminRegistrar->roles()->sync(Role::query()->whereIn('slug', [
            RoleCapabilityMatrix::ROLE_REGISTRAR,
            RoleCapabilityMatrix::ROLE_ADMIN,
        ])->pluck('id'));
        $this->assertFalse($policy->can($adminRegistrar));

        $permission = Permission::query()->where('name', Capability::INPATIENT_BED_TRANSFER)->sole();
        $capabilityOnlyRole = Role::query()->create([
            'slug' => 'transfer-capability-only',
            'name' => 'Transfer capability only',
            'description' => 'Test-only role without registrar identity.',
        ]);
        $capabilityOnlyRole->permissions()->sync([$permission->id]);
        $capabilityOnly = User::factory()->create();
        $capabilityOnly->roles()->sync([$capabilityOnlyRole->id]);
        $this->assertFalse($policy->can($capabilityOnly));

        Role::query()->where('slug', RoleCapabilityMatrix::ROLE_REGISTRAR)
            ->sole()->permissions()->detach($permission->id);
        $roleOnly = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $this->assertFalse($policy->can($roleOnly));
    }

    public function test_inertia_authorization_audit_failure_returns_controlled_form_error(): void
    {
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);
        $recorder = Mockery::mock(AuditRecorder::class);
        $recorder->shouldReceive('record')->once()->andReturn(null);
        $this->app->instance(AuditRecorder::class, $recorder);
        $encounterPublicId = Str::ulid()->toBase32();

        $this->actingAs($nurse)
            ->from('/pemeriksaan/rawat-inap')
            ->withHeader('X-Inertia', 'true')
            ->post(route('pendaftaran.rawat-inap.bed-transfer', $encounterPublicId), [])
            ->assertRedirect('/pemeriksaan/rawat-inap')
            ->assertSessionHasErrors([
                'transfer' => 'Penolakan transfer tidak dapat diaudit.',
            ]);

        $this->assertDatabaseCount('inpatient_location_events', 0);
        $this->assertDatabaseCount('inpatient_location_operation_receipts', 0);
    }

    public function test_authorized_invalid_request_shape_is_audited_without_writing_location_state(): void
    {
        $encounter = $this->legacyEncounter($this->source);

        $this->actingAs($this->registrar)
            ->from(route('pemeriksaan.rawat-inap.show', $encounter->public_id))
            ->post(route('pendaftaran.rawat-inap.bed-transfer', $encounter->public_id), [
                'unexpected' => 'must be rejected',
            ])
            ->assertRedirect(route('pemeriksaan.rawat-inap.show', $encounter->public_id))
            ->assertSessionHasErrors('transfer');

        $this->assertDatabaseCount('inpatient_location_events', 0);
        $this->assertDatabaseCount('inpatient_location_operation_receipts', 0);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'inpatient.bed.transfer',
            'resource_id' => $encounter->public_id,
            'outcome' => 'DENIED',
            'reason' => 'validation_failed',
        ]);
    }

    public function test_inertia_validation_audit_failure_returns_controlled_form_error(): void
    {
        $encounter = $this->legacyEncounter($this->source);
        $recorder = Mockery::mock(AuditRecorder::class);
        $recorder->shouldReceive('record')->once()->andReturn(null);
        $this->app->instance(AuditRecorder::class, $recorder);

        $this->actingAs($this->registrar)
            ->from(route('pemeriksaan.rawat-inap.show', $encounter->public_id))
            ->withHeader('X-Inertia', 'true')
            ->post(route('pendaftaran.rawat-inap.bed-transfer', $encounter->public_id), [
                'unexpected' => 'must be rejected',
            ])
            ->assertRedirect(route('pemeriksaan.rawat-inap.show', $encounter->public_id))
            ->assertSessionHasErrors([
                'transfer' => 'Penolakan transfer tidak dapat diaudit.',
            ]);

        $this->assertDatabaseCount('inpatient_location_events', 0);
        $this->assertDatabaseCount('inpatient_location_operation_receipts', 0);
    }

    public function test_stale_sequence_and_changed_payload_key_preserve_current_placement(): void
    {
        $encounter = $this->legacyEncounter($this->source);
        $service = app(InpatientBedTransferService::class);
        $service->transfer(
            $encounter->public_id, $this->registrar, 0, $this->source->public_id,
            $this->target->public_id, 'Perpindahan pertama sah', 'conflict-transfer-0001', null,
        );

        try {
            $service->transfer(
                $encounter->public_id, $this->registrar, 0, $this->source->public_id,
                $this->target->public_id, 'Payload berbeda untuk kunci sama', 'conflict-transfer-0001', null,
            );
            $this->fail('Changed payload unexpectedly replayed.');
        } catch (InpatientBedTransferDenied $denial) {
            $this->assertSame('idempotency_key_conflict', $denial->reason);
        }

        $this->assertSame($this->target->id, $encounter->fresh()?->inpatient_bed_id);
        $this->assertDatabaseCount('inpatient_location_events', 1);
        $this->assertDatabaseCount('inpatient_location_operation_receipts', 1);
    }

    public function test_future_registration_writes_admission_location_in_same_successful_transaction(): void
    {
        $response = $this->actingAs($this->registrar)->post(route('pendaftaran.rawat-inap.store'), [
            'full_name' => 'Pasien Admission Event', 'date_of_birth' => '1990-01-01',
            'sex' => Patient::SEX_PEREMPUAN, 'bed_public_id' => $this->source->public_id,
            'payer_type' => Encounter::PAYER_UMUM, 'continue_from' => Encounter::CONTINUE_LANGSUNG,
            'is_synthetic' => true,
        ]);
        $response->assertRedirect(route('pendaftaran.rawat-inap.index'));

        $event = InpatientLocationEvent::query()->sole();
        $this->assertSame(InpatientLocationEvent::TYPE_ADMISSION, $event->event_type);
        $this->assertSame(1, $event->sequence);
        $this->assertNull($event->from_bed_public_id);
        $this->assertNull($event->reason);
        $projection = app(InpatientLocationHistoryProjection::class)->forEncounter($event->encounter, $this->registrar);
        $this->assertTrue($projection['history_complete']);
        $this->assertNull($projection['history_baseline']);
    }

    public function test_occupied_target_is_denied_without_partial_location_change(): void
    {
        $encounter = $this->legacyEncounter($this->source, 1);
        $legacyTargetOccupant = $this->legacyEncounter($this->target, 2);
        InpatientLocationMutationScope::run(
            fn () => $legacyTargetOccupant->update(['inpatient_bed_id' => null]),
        );
        $projection = app(InpatientLocationHistoryProjection::class)->forEncounter($encounter, $this->registrar);
        $this->assertSame([], $projection['transfer']['target_beds']);

        try {
            app(InpatientBedTransferService::class)->transfer(
                $encounter->public_id,
                $this->registrar,
                0,
                $this->source->public_id,
                $this->target->public_id,
                'Target sedang ditempati pasien lain',
                'occupied-target-0001',
                null,
            );
            $this->fail('Occupied target unexpectedly accepted.');
        } catch (InpatientBedTransferDenied $denial) {
            $this->assertSame('target_occupied', $denial->reason);
        }

        $this->assertSame($this->source->id, $encounter->fresh()?->inpatient_bed_id);
        $this->assertDatabaseCount('inpatient_location_events', 0);
        $this->assertDatabaseCount('inpatient_location_operation_receipts', 0);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'inpatient.bed.transfer',
            'outcome' => 'DENIED',
            'reason' => 'target_occupied',
        ]);
    }

    public function test_inconsistent_non_null_bed_id_and_target_code_blocks_the_target_everywhere(): void
    {
        $encounter = $this->legacyEncounter($this->source, 1);
        $admin = $this->userWithRole(RoleCapabilityMatrix::ROLE_ADMIN);
        $otherBed = $this->createBed(
            app(InpatientMasterService::class),
            $admin,
            'TR-03',
            'Tempat Tidur TR-03',
            'transfer-bed-0003',
        );
        $inconsistentOccupant = $this->legacyEncounter($otherBed, 2);
        InpatientLocationMutationScope::run(
            fn () => $inconsistentOccupant->update(['bed_code' => $this->target->code]),
        );

        $projection = app(InpatientLocationHistoryProjection::class)->forEncounter($encounter, $this->registrar);
        $this->assertNotContains(
            $this->target->public_id,
            array_column($projection['transfer']['target_beds'], 'bed_public_id'),
        );

        try {
            app(InpatientBedTransferService::class)->transfer(
                $encounter->public_id,
                $this->registrar,
                0,
                $this->source->public_id,
                $this->target->public_id,
                'Target memiliki klaim penempatan tidak konsisten',
                'inconsistent-target-0001',
                null,
            );
            $this->fail('Inconsistent target claim unexpectedly accepted.');
        } catch (InpatientBedTransferDenied $denial) {
            $this->assertSame('target_occupied', $denial->reason);
        }

        $this->assertSame($this->source->id, $encounter->fresh()?->inpatient_bed_id);
        $this->assertDatabaseCount('inpatient_location_events', 0);
        $this->assertDatabaseCount('inpatient_location_operation_receipts', 0);
    }

    public function test_inertia_business_denial_returns_to_the_form_instead_of_an_error_page(): void
    {
        $encounter = $this->legacyEncounter($this->source, 1);
        $this->legacyEncounter($this->target, 2);

        $this->actingAs($this->registrar)
            ->from(route('pemeriksaan.rawat-inap.show', $encounter->public_id))
            ->withHeader('X-Inertia', 'true')
            ->post(
                route('pendaftaran.rawat-inap.bed-transfer', $encounter->public_id),
                $this->payload(
                    $encounter,
                    $this->source,
                    $this->target,
                    'Target sedang ditempati pasien lain',
                    'occupied-target-inertia-0001',
                ),
            )
            ->assertRedirect(route('pemeriksaan.rawat-inap.show', $encounter->public_id))
            ->assertSessionHasErrors([
                'transfer' => 'Tempat tidur tujuan sedang terisi.',
            ]);

        $this->assertSame($this->source->id, $encounter->fresh()?->inpatient_bed_id);
        $this->assertDatabaseCount('inpatient_location_events', 0);
    }

    public function test_audit_failure_rolls_back_event_placement_and_receipt(): void
    {
        $encounter = $this->legacyEncounter($this->source);
        $recorder = Mockery::mock(AuditRecorder::class);
        $recorder->shouldReceive('record')->once()->andReturn(null);
        $service = new InpatientBedTransferService(
            $recorder,
            app(InpatientBedTransferActorPolicy::class),
            app(CanonicalInpatientBedOperationLockCoordinator::class),
            app(PharmacyEncounterLifecycleGate::class),
        );

        $this->expectException(InpatientBedTransferAuditUnavailable::class);
        try {
            $service->transfer(
                $encounter->public_id,
                $this->registrar,
                0,
                $this->source->public_id,
                $this->target->public_id,
                'Transfer harus batal bila audit gagal',
                'audit-failure-0001',
                null,
            );
        } finally {
            $fresh = $encounter->fresh();
            $this->assertSame($this->source->id, $fresh?->inpatient_bed_id);
            $this->assertSame($this->source->code, $fresh?->bed_code);
            $this->assertDatabaseCount('inpatient_location_events', 0);
            $this->assertDatabaseCount('inpatient_location_operation_receipts', 0);
        }
    }

    public function test_database_write_failure_is_sanitized_and_never_exposes_the_transfer_reason(): void
    {
        $encounter = $this->legacyEncounter($this->source);
        $sensitiveReason = 'RAHASIA-KLINIS-JANGAN-LOG';
        InpatientMasterMutationScope::run(fn () => DB::table('inpatient_bed_versions')
            ->where('bed_id', $this->target->id)
            ->where('version', $this->target->version)
            ->update(['display_name' => 'Snapshot sengaja tidak cocok']));

        try {
            app(InpatientBedTransferService::class)->transfer(
                $encounter->public_id,
                $this->registrar,
                0,
                $this->source->public_id,
                $this->target->public_id,
                $sensitiveReason,
                'persistence-failure-0001',
                null,
            );
            $this->fail('Forced database failure unexpectedly committed.');
        } catch (InpatientBedTransferDenied $denial) {
            $this->assertSame('persistence_unavailable', $denial->reason);
            $this->assertSame(503, $denial->status);
            $this->assertStringNotContainsString($sensitiveReason, $denial->getMessage());
        }

        $this->assertSame($this->source->id, $encounter->fresh()?->inpatient_bed_id);
        $this->assertDatabaseCount('inpatient_location_events', 0);
        $this->assertDatabaseCount('inpatient_location_operation_receipts', 0);
        $audit = AuditEvent::query()
            ->where('action', 'inpatient.bed.transfer')
            ->where('outcome', 'DENIED')
            ->where('reason', 'persistence_unavailable')
            ->sole();
        $this->assertNull($audit->metadata);
        $this->assertStringNotContainsString($sensitiveReason, json_encode($audit->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_location_event_and_receipt_reject_eloquent_and_direct_sql_mutation(): void
    {
        $encounter = $this->legacyEncounter($this->source);
        app(InpatientBedTransferService::class)->transfer(
            $encounter->public_id,
            $this->registrar,
            0,
            $this->source->public_id,
            $this->target->public_id,
            'Buat bukti immutable',
            'immutable-transfer-0001',
            null,
        );
        $event = InpatientLocationEvent::query()->sole();
        $receipt = InpatientLocationOperationReceipt::query()->sole();

        foreach ([
            fn () => $event->update(['sequence' => 99]),
            fn () => $event->delete(),
            fn () => $receipt->update(['result_sequence' => 99]),
            fn () => $receipt->delete(),
            fn () => DB::table('inpatient_location_events')->where('id', $event->id)->update(['sequence' => 99]),
            fn () => DB::table('inpatient_location_operation_receipts')->where('id', $receipt->id)->delete(),
            fn () => DB::table('encounters')->where('id', $encounter->id)->update(['bed_code' => 'DIRECT-BYPASS']),
            fn () => DB::table('encounters')->upsert([
                ['id' => $encounter->id, 'bed_code' => 'UPSERT-BYPASS'],
            ], ['id'], ['bed_code']),
            fn () => DB::statement('UPDATE ONLY encounters SET bed_code = ? WHERE id = ?', [
                'UPDATE-ONLY-BYPASS',
                $encounter->id,
            ]),
            fn () => DB::statement('UPDATE main . encounters SET bed_code = ? WHERE id = ?', [
                'QUALIFIED-UPDATE-BYPASS',
                $encounter->id,
            ]),
            fn () => DB::statement('MERGE INTO encounters AS target USING encounters AS source ON target.id = source.id WHEN MATCHED THEN UPDATE SET bed_code = ?', [
                'MERGE-BYPASS',
            ]),
            fn () => DB::statement('MERGE INTO ONLY encounters AS target USING encounters AS source ON target.id = source.id WHEN MATCHED THEN UPDATE SET bed_code = ?', [
                'MERGE-ONLY-BYPASS',
            ]),
            fn () => DB::statement('REPLACE INTO encounters (id, bed_code) VALUES (?, ?)', [
                $encounter->id,
                'REPLACE-BYPASS',
            ]),
            fn () => DB::statement('REPLACE LOW_PRIORITY encounters (id, bed_code) VALUES (?, ?)', [
                $encounter->id,
                'REPLACE-OPTIONAL-INTO-BYPASS',
            ]),
            fn () => DB::statement('REPLACE INTO main . encounters (id, bed_code) VALUES (?, ?)', [
                $encounter->id,
                'QUALIFIED-REPLACE-BYPASS',
            ]),
            fn () => DB::statement('INSERT INTO main . encounters (id, bed_code) VALUES (?, ?) ON CONFLICT (id) DO UPDATE SET bed_code = excluded.bed_code', [
                $encounter->id,
                'QUALIFIED-UPSERT-BYPASS',
            ]),
            fn () => DB::statement('SELECT 1; UPDATE encounters SET bed_code = ? WHERE id = ?', [
                'READ-PREFIX-UPDATE-BYPASS',
                $encounter->id,
            ]),
            fn () => DB::statement('SELECT 1; DELETE FROM inpatient_location_events WHERE id = ?', [$event->id]),
            fn () => DB::statement('/*!50000 UPDATE encounters SET bed_code = ? WHERE id = ? */', [
                'EXECUTABLE-COMMENT-UPDATE',
                $encounter->id,
            ]),
            fn () => DB::statement('/*!50000 REPLACE INTO encounters (id, bed_code) VALUES (?, ?) */', [
                $encounter->id,
                'EXECUTABLE-COMMENT-REPLACE',
            ]),
            fn () => DB::statement('/*M!100100 INSERT INTO encounters (id, bed_code) VALUES (?, ?) ON DUPLICATE KEY UPDATE bed_code = VALUES(bed_code) */', [
                $encounter->id,
                'EXECUTABLE-COMMENT-UPSERT',
            ]),
            fn () => DB::statement('INSERT INTO encounters (id, care_setting, status, inpatient_bed_id, bed_code) VALUES (?, ?, ?, ?, ?)', [
                $encounter->id + 1000,
                Encounter::CARE_SETTING_INPATIENT,
                Encounter::STATUS_REGISTERED,
                $this->source->id,
                $this->source->code,
            ]),
            fn () => DB::statement('INSERT INTO encounters (id, inpatient_bed_id, bed_code) SELECT ?, inpatient_bed_id, bed_code FROM encounters WHERE id = ?', [
                $encounter->id + 1001,
                $encounter->id,
            ]),
            fn () => DB::statement('DELETE FROM encounters WHERE id = ?', [$encounter->id]),
            fn () => DB::statement('DELETE e FROM encounters AS e WHERE e.id = ?', [$encounter->id]),
            fn () => DB::statement('DELETE FROM e USING encounters AS e WHERE e.id = ?', [$encounter->id]),
            fn () => DB::statement('DELETE e.* FROM encounters AS e WHERE e.id = ?', [$encounter->id]),
            fn () => DB::statement('DELETE encounters.* FROM encounters WHERE id = ?', [$encounter->id]),
            fn () => DB::statement('DELETE e, x FROM encounters AS e JOIN other AS x ON x.id = e.id WHERE e.id = ?', [$encounter->id]),
            fn () => DB::statement('DELETE FROM e.* USING encounters AS e WHERE e.id = ?', [$encounter->id]),
            fn () => DB::statement('DELETE e FROM other AS x, encounters AS e WHERE e.id = x.encounter_id'),
            fn () => DB::statement('DELETE FROM e USING other AS x, encounters AS e WHERE e.id = x.encounter_id'),
            fn () => DB::statement('DELETE e FROM other AS x STRAIGHT_JOIN encounters AS e ON e.id = x.encounter_id'),
            fn () => DB::statement('DELETE e FROM (encounters AS e) WHERE e.id = ?', [$encounter->id]),
            fn () => DB::statement('INSERT INTO encounters VALUES (?)', [$encounter->id + 1002]),
            fn () => DB::statement('INSERT INTO encounters SELECT * FROM encounters WHERE id = ?', [$encounter->id]),
            fn () => DB::statement('REPLACE INTO encounters VALUES (?)', [$encounter->id + 1003]),
            fn () => DB::statement('TRUNCATE TABLE inpatient_location_events'),
            fn () => InpatientLocationOperationReceipt::query()->create([
                'encounter_id' => $encounter->id,
                'actor_user_id' => $this->registrar->id,
                'operation' => InpatientLocationOperationReceipt::OPERATION_TRANSFER,
                'idempotency_key' => 'direct-receipt-insert-0001',
                'payload_digest' => str_repeat('a', 64),
                'result_event_public_id' => $event->public_id,
                'result_sequence' => $event->sequence,
                'request_correlation_id' => null,
                'completed_at' => now(),
            ]),
        ] as $bypass) {
            try {
                $bypass();
                $this->fail('Inpatient location history bypass unexpectedly succeeded.');
            } catch (LogicException) {
                $this->assertSame(1, InpatientLocationEvent::query()->sole()->sequence);
                $this->assertSame(1, InpatientLocationOperationReceipt::query()->sole()->result_sequence);
                $this->assertSame($this->target->code, $encounter->fresh()?->bed_code);
            }
        }
    }

    public function test_bounded_synthetic_reset_removes_location_chain_but_preserves_audit_evidence(): void
    {
        $encounter = $this->legacyEncounter($this->source);
        app(InpatientBedTransferService::class)->transfer(
            $encounter->public_id,
            $this->registrar,
            0,
            $this->source->public_id,
            $this->target->public_id,
            'Transfer sebelum reset terkontrol',
            'reset-transfer-0001',
            null,
        );
        $audit = AuditEvent::query()
            ->where('action', 'inpatient.bed.transfer')
            ->where('outcome', 'SUCCESS')
            ->sole();

        app(SyntheticResetService::class)->reset([
            'actor' => $this->registrar,
            'reason' => 'inpatient_location_test_reset',
        ]);

        $this->assertDatabaseCount('inpatient_location_events', 0);
        $this->assertDatabaseCount('inpatient_location_operation_receipts', 0);
        $this->assertDatabaseHas('audit_events', [
            'id' => $audit->id,
            'action' => 'inpatient.bed.transfer',
            'outcome' => 'SUCCESS',
        ]);
    }

    public function test_cancellation_after_transfer_is_denied_and_preserves_the_current_location(): void
    {
        $encounter = $this->legacyEncounter($this->source);
        app(InpatientBedTransferService::class)->transfer(
            $encounter->public_id,
            $this->registrar,
            0,
            $this->source->public_id,
            $this->target->public_id,
            'Transfer sebelum pembatalan',
            'transfer-before-cancel-0001',
            null,
        );

        $this->actingAs($this->registrar)
            ->post(route('pendaftaran.kunjungan.batalkan', $encounter->public_id), [
                'reason_code' => EncounterCancellation::REASON_WRONG_REGISTRATION,
                'note' => null,
                'idempotency_key' => 'cancel-after-transfer-0001',
            ])
            ->assertStatus(409);

        $fresh = $encounter->fresh();
        $this->assertSame(Encounter::STATUS_REGISTERED, $fresh?->status);
        $this->assertSame($this->target->id, $fresh?->inpatient_bed_id);
        $this->assertDatabaseCount('encounter_cancellations', 0);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'encounter.cancel',
            'resource_id' => $encounter->public_id,
            'outcome' => 'DENIED',
            'reason' => 'location_activity_exists',
        ]);
    }

    private function legacyEncounter(InpatientBed $bed, int $queueNumber = 1): Encounter
    {
        $patient = Patient::factory()->create(['is_synthetic' => true]);

        return InpatientLocationMutationScope::run(fn (): Encounter => Encounter::query()->create([
            'patient_id' => $patient->id, 'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'status' => Encounter::STATUS_REGISTERED, 'clinic_name' => $this->ward->display_name,
            'ward_name' => $this->ward->display_name, 'ward_class' => $bed->service_class,
            'bed_code' => $bed->code, 'inpatient_bed_id' => $bed->id,
            'continue_from' => Encounter::CONTINUE_LANGSUNG, 'visit_date' => now()->toDateString(),
            'payer_type' => Encounter::PAYER_UMUM, 'queue_date' => now()->toDateString(),
            'queue_number' => $queueNumber, 'registered_at' => now(), 'registered_by_user_id' => $this->registrar->id,
        ]));
    }

    /** @return array<string, mixed> */
    private function payload(Encounter $encounter, InpatientBed $source, InpatientBed $target, string $reason, string $key): array
    {
        return [
            'expected_location_sequence' => 0,
            'expected_source_bed_public_id' => $source->public_id,
            'target_bed_public_id' => $target->public_id,
            'reason' => $reason,
            'idempotency_key' => $key,
        ];
    }

    private function createBed(InpatientMasterService $service, User $admin, string $code, string $name, string $key): InpatientBed
    {
        $bed = $service->createBed(
            $admin, $this->ward->public_id, $code, $name, 'Ruang Transfer', 'Kelas 1',
            InpatientMasterService::REASON_INITIAL_SETUP, $key, null,
        )->master;
        if (! $bed instanceof InpatientBed) {
            throw new LogicException('Expected bed result.');
        }

        return $bed;
    }

    /** @return array{PharmacyPrescription, PharmacyPrescriptionItem} */
    private function pharmacyPrescription(Encounter $encounter, User $physician, string $status): array
    {
        return PharmacyMutationScope::run(function () use ($encounter, $physician, $status): array {
            $depot = PharmacyDepot::query()->create([
                'depot_code' => 'DEPO_RI_TRANSFER',
                'display_name' => 'Depo Rawat Inap Transfer',
                'eligible_care_settings' => [Encounter::CARE_SETTING_INPATIENT],
                'state' => PharmacyDepot::ACTIVE,
                'version' => 1,
                'current_content_digest' => str_repeat('a', 64),
            ]);
            $medicine = PharmacyMedicine::query()->create([
                'medicine_code' => 'OBAT-TRANSFER',
                'generic_name' => 'Obat Transfer',
                'strength_text' => '500 mg',
                'dosage_form' => 'TABLET',
                'base_unit' => 'TABLET',
                'route_choices' => ['ORAL'],
                'acquisition_value' => 1000,
                'teaching_sale_value' => 1500,
                'state' => PharmacyMedicine::ACTIVE,
                'version' => 1,
                'current_content_digest' => str_repeat('b', 64),
            ]);
            $prescription = PharmacyPrescription::query()->create([
                'encounter_id' => $encounter->id,
                'patient_id' => $encounter->patient_id,
                'ordering_physician_user_id' => $physician->id,
                'depot_id' => $depot->id,
                'care_setting' => $encounter->care_setting,
                'encounter_number_snapshot' => $encounter->public_id,
                'location_snapshot' => $encounter->ward_name.' · '.$encounter->bed_code,
                'location_fingerprint' => str_repeat('c', 64),
                'depot_version' => 1,
                'depot_code_snapshot' => $depot->depot_code,
                'status' => $status,
                'version' => 1,
                'current_content_digest' => str_repeat('c', 64),
            ]);
            $item = PharmacyPrescriptionItem::query()->create([
                'prescription_id' => $prescription->id,
                'medicine_id' => $medicine->id,
                'line_number' => 1,
                'medicine_version' => 1,
                'medicine_version_public_id' => (string) Str::ulid(),
                'medicine_content_digest' => str_repeat('b', 64),
                'medicine_code' => $medicine->medicine_code,
                'medicine_name' => $medicine->generic_name,
                'strength_text' => $medicine->strength_text,
                'dosage_form' => $medicine->dosage_form,
                'base_unit' => $medicine->base_unit,
                'dose_text' => 'Satu tablet',
                'route' => 'ORAL',
                'frequency_text' => 'Dua kali sehari',
                'duration_text' => 'Tiga hari',
                'requested_quantity' => 6,
                'verified_quantity' => 6,
                'sale_value_snapshot' => 1500,
                'instruction' => 'Sesudah makan',
                'content_digest' => str_repeat('d', 64),
                'created_at' => now(),
            ]);

            return [$prescription, $item];
        });
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
