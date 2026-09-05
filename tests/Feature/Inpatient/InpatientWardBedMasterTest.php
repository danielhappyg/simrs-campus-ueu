<?php

namespace Tests\Feature\Inpatient;

use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientBedVersion;
use App\Models\InpatientMasterOperationReceipt;
use App\Models\InpatientWard;
use App\Models\InpatientWardVersion;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Inpatient\InpatientLocationMutationScope;
use App\Support\Inpatient\InpatientMasterActorPolicy;
use App\Support\Inpatient\InpatientMasterDirectWriteScope;
use App\Support\Inpatient\InpatientMasterService;
use App\Support\Inpatient\InpatientMasterSqlWriteGuard;
use App\Support\Inpatient\InpatientOccupancyProjection;
use App\Support\Simulation\SyntheticResetService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class InpatientWardBedMasterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->admin = $this->userWithRole(RoleCapabilityMatrix::ROLE_ADMIN);
    }

    public function test_admin_creates_versioned_masters_and_projection_exposes_only_authorized_actions(): void
    {
        $this->createWardAndBed();

        $ward = InpatientWard::query()->with('versions')->firstOrFail();
        $bed = InpatientBed::query()->with('versions')->firstOrFail();
        $this->assertSame('RI-MELATI', $ward->code);
        $this->assertSame(1, $ward->version);
        $this->assertCount(1, $ward->versions);
        $this->assertSame(1, $bed->version);
        $this->assertCount(1, $bed->versions);
        $this->assertSame($ward->versions->first()?->after_digest, $this->auditMetadata('master.inpatient.ward.create')['after_digest']);
        $this->assertSame($bed->versions->first()?->after_digest, $this->auditMetadata('master.inpatient.bed.create')['after_digest']);

        $this->actingAs($this->admin)
            ->get(route('manajemen-data.bangsal.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('manajemen-data/bangsal')
                ->where('permissions.can_manage_master', true)
                ->where('totals.active_wards', 1)
                ->where('totals.active_beds', 1)
                ->where('totals.available_beds', 1)
                ->where('wards.0.code', 'RI-MELATI')
                ->where('wards.0.beds.0.occupancy.state', 'AVAILABLE')
                ->where('wards.0.actions.update_url', route('manajemen-data.bangsal.wards.update', $ward))
                ->where('wards.0.beds.0.actions.retire_url', route('manajemen-data.bangsal.beds.retire', $bed)));

        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $this->actingAs($registrar)
            ->get(route('manajemen-data.bangsal.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.can_view_census', true)
                ->where('permissions.can_manage_master', false)
                ->where('commands.create_ward_url', null)
                ->where('wards.0.actions.update_url', null)
                ->where('wards.0.beds.0.actions.retire_url', null));
    }

    public function test_admin_can_see_empty_new_ward_and_reach_create_bed_action(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manajemen-data.bangsal.wards.store'), $this->wardPayload())
            ->assertSessionHasNoErrors();
        $ward = InpatientWard::query()->firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('manajemen-data.bangsal.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('wards', 1)
                ->where('wards.0.code', 'RI-MELATI')
                ->has('wards.0.beds', 0)
                ->where('wards.0.actions.create_bed_url', route('manajemen-data.bangsal.wards.beds.store', $ward)));
    }

    public function test_wrong_role_is_denied_before_unknown_master_lookup(): void
    {
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);

        $this->actingAs($nurse)
            ->patch(route('manajemen-data.bangsal.wards.update', '01ARZ3NDEKTSV4RRFFQ69G5FAV'), [
                'display_name' => 'Tidak boleh',
                'expected_version' => 1,
                'reason_code' => InpatientMasterService::REASON_DATA_CORRECTION,
                'idempotency_key' => 'deny-before-lookup-ward',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('inpatient_wards', 0);
    }

    public function test_service_denies_wrong_role_before_digest_receipt_or_master_lookup(): void
    {
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);

        try {
            app(InpatientMasterService::class)->createWard(
                $nurse,
                'RI-DENIED',
                'Tidak boleh',
                InpatientMasterService::REASON_INITIAL_SETUP,
                'service-denied-ward-0001',
                null,
            );
            $this->fail('Nurse unexpectedly mutated inpatient master through the service.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('inpatient_wards', 0);
            $this->assertDatabaseCount('inpatient_master_code_reservations', 0);
            $this->assertDatabaseCount('inpatient_master_operation_receipts', 0);
        }

    }

    public function test_master_policy_checks_capability_for_admin_and_system_admin_paths(): void
    {
        $policy = app(InpatientMasterActorPolicy::class);
        $this->assertTrue($policy->canManage($this->admin));

        $systemAdmin = User::factory()->create(['is_system_administrator' => true]);
        $this->assertTrue($policy->canManage($systemAdmin));

        $systemAdminWithDeniedCapability = new class extends User
        {
            public function canCapability(string $capability): bool
            {
                return false;
            }
        };
        $systemAdminWithDeniedCapability->forceFill(['is_system_administrator' => true]);
        $this->assertFalse($policy->canManage($systemAdminWithDeniedCapability));
    }

    public function test_correlation_id_is_identical_across_version_receipt_and_audit(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('manajemen-data.bangsal.wards.store'), $this->wardPayload())
            ->assertSessionHasNoErrors();
        $requestId = $response->headers->get('X-Request-Id');
        $this->assertIsString($requestId);
        $this->assertTrue(Str::isUlid($requestId));

        $this->assertSame($requestId, InpatientWardVersion::query()->firstOrFail()->request_correlation_id);
        $this->assertSame($requestId, InpatientMasterOperationReceipt::query()->firstOrFail()->request_correlation_id);
        $this->assertSame($requestId, AuditEvent::query()->where('action', 'master.inpatient.ward.create')->firstOrFail()->request_correlation_id);
    }

    public function test_update_replay_conflict_and_stale_version_preserve_immutable_history(): void
    {
        [$ward] = $this->createWardAndBed();
        $payload = [
            'display_name' => 'Bangsal Melati Utama',
            'expected_version' => 1,
            'reason_code' => InpatientMasterService::REASON_OPERATIONAL_CHANGE,
            'idempotency_key' => 'ward-update-replay-0001',
        ];

        $this->actingAs($this->admin)->patch(route('manajemen-data.bangsal.wards.update', $ward), $payload)->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->patch(route('manajemen-data.bangsal.wards.update', $ward), $payload)->assertSessionHasNoErrors();

        $this->assertSame(2, $ward->fresh()->version);
        $this->assertDatabaseCount('inpatient_ward_versions', 2);
        $this->assertSame(1, InpatientMasterOperationReceipt::query()->where('operation', 'WARD_UPDATE')->count());
        $this->assertSame(1, $this->auditCount('master.inpatient.ward.update'));

        $this->actingAs($this->admin)
            ->patch(route('manajemen-data.bangsal.wards.update', $ward), [...$payload, 'display_name' => 'Payload berbeda'])
            ->assertSessionHasErrors('master');
        $this->actingAs($this->admin)
            ->patch(route('manajemen-data.bangsal.wards.update', $ward), [...$payload, 'idempotency_key' => 'ward-update-stale-0002'])
            ->assertSessionHasErrors('master');

        $this->assertSame('Bangsal Melati Utama', $ward->fresh()->display_name);
        $this->assertDatabaseCount('inpatient_ward_versions', 2);
        $this->expectException(LogicException::class);
        $ward->fresh()->update(['code' => 'RI-BARU', 'version' => 3]);
    }

    public function test_live_census_derives_from_encounter_and_occupied_bed_cannot_retire(): void
    {
        [, $bed] = $this->createWardAndBed();
        $encounter = InpatientLocationMutationScope::run(fn (): Encounter => Encounter::factory()->create([
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'status' => Encounter::STATUS_REGISTERED,
            'ward_name' => 'Melati',
            'ward_class' => 'Kelas 1',
            'bed_code' => $bed->code,
            'inpatient_bed_id' => $bed->id,
        ]));

        $this->actingAs($this->admin)
            ->get(route('manajemen-data.bangsal.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('totals.occupied_beds', 1)
                ->where('totals.available_beds', 0)
                ->where('wards.0.beds.0.occupancy.state', 'OCCUPIED'));

        $this->actingAs($this->admin)
            ->post(route('manajemen-data.bangsal.beds.retire', $bed), $this->retirePayload('occupied-retire-0001'))
            ->assertSessionHasErrors('master');
        $this->assertSame(InpatientBed::STATE_ACTIVE, $bed->fresh()->state);

        $encounter->update(['status' => Encounter::STATUS_CANCELLED]);
        $this->actingAs($this->admin)
            ->get(route('manajemen-data.bangsal.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('totals.occupied_beds', 0)
                ->where('totals.available_beds', 1));

        $this->actingAs($this->admin)
            ->post(route('manajemen-data.bangsal.beds.retire', $bed), $this->retirePayload('available-retire-0002'))
            ->assertSessionHasNoErrors();
        $this->assertSame(InpatientBed::STATE_RETIRED, $bed->fresh()->state);
        $this->assertSame(2, $bed->fresh()->version);
    }

    public function test_id_only_legacy_claim_blocks_both_admission_and_retirement(): void
    {
        [$ward, $bed] = $this->createWardAndBed();
        $otherBed = $this->createBed(
            $ward,
            'A-02',
            'Tempat Tidur A-02',
            'Ruang Melati',
            'Kelas 1',
            'create-bed-melati-a02',
        );
        InpatientLocationMutationScope::run(fn (): Encounter => Encounter::factory()->create([
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'status' => Encounter::STATUS_REGISTERED,
            'ward_name' => $ward->display_name,
            'ward_class' => $bed->service_class,
            'inpatient_bed_id' => $bed->id,
            'bed_code' => $otherBed->code,
        ]));

        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $this->actingAs($registrar)
            ->post(route('pendaftaran.rawat-inap.store'), $this->registrationPayload($bed, [
                'full_name' => 'Pasien klaim ID ganda',
                'nik' => '3174011555900077',
            ]))
            ->assertSessionHasErrors('bed_code');

        $this->actingAs($this->admin)
            ->post(route('manajemen-data.bangsal.beds.retire', $bed), $this->retirePayload('id-only-claim-retire-0001'))
            ->assertSessionHasErrors('master');

        $this->assertSame(InpatientBed::STATE_ACTIVE, $bed->fresh()->state);
        $this->assertSame(1, Encounter::query()->where('care_setting', Encounter::CARE_SETTING_INPATIENT)->count());
    }

    public function test_registration_resolves_active_master_and_stores_immutable_snapshots(): void
    {
        [$ward, $bed] = $this->createWardAndBed();
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);

        $this->actingAs($registrar)
            ->post(route('pendaftaran.rawat-inap.store'), $this->registrationPayload($bed))
            ->assertRedirect(route('pendaftaran.rawat-inap.index'))
            ->assertSessionHasNoErrors();

        $encounter = Encounter::query()->where('care_setting', Encounter::CARE_SETTING_INPATIENT)->firstOrFail();
        $this->assertSame($bed->id, $encounter->inpatient_bed_id);
        $this->assertSame($ward->display_name, $encounter->ward_name);
        $this->assertSame($bed->service_class, $encounter->ward_class);
        $this->assertSame($bed->code, $encounter->bed_code);

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rawat-inap.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('wards', 1)
                ->has('wards.0.beds', 0));

        $this->actingAs($registrar)
            ->post(route('pendaftaran.rawat-inap.store'), $this->registrationPayload($bed, [
                'bed_public_id' => '',
                'full_name' => 'Pasien tanpa managed bed',
                'nik' => '3174011555900099',
            ]))
            ->assertSessionHasErrors('bed_public_id');
    }

    public function test_audit_failure_rolls_back_master_version_and_receipt(): void
    {
        $recorder = Mockery::mock(AuditRecorder::class);
        $recorder->shouldReceive('record')->once()->andReturn(null);
        $this->app->instance(AuditRecorder::class, $recorder);

        $this->actingAs($this->admin)
            ->post(route('manajemen-data.bangsal.wards.store'), $this->wardPayload())
            ->assertStatus(503);

        $this->assertDatabaseCount('inpatient_wards', 0);
        $this->assertDatabaseCount('inpatient_ward_versions', 0);
        $this->assertDatabaseCount('inpatient_master_operation_receipts', 0);
        $this->assertDatabaseCount('inpatient_master_code_reservations', 0);
    }

    public function test_empty_catalogue_fails_closed_for_get_and_crafted_legacy_post(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rawat-inap.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('wards', 0)
                ->has('wardOptions', 0));

        $this->actingAs($registrar)
            ->post(route('pendaftaran.rawat-inap.store'), [
                'full_name' => 'Pasien tanpa master',
                'date_of_birth' => '1990-05-15',
                'sex' => Patient::SEX_PEREMPUAN,
                'ward_name' => 'Melati',
                'ward_class' => 'Kelas 1',
                'bed_code' => 'A-01',
                'payer_type' => Encounter::PAYER_UMUM,
                'continue_from' => Encounter::CONTINUE_LANGSUNG,
                'is_synthetic' => true,
            ])
            ->assertSessionHasErrors('bed_public_id');

        $this->assertDatabaseMissing('patients', ['full_name' => 'Pasien tanpa master']);
    }

    public function test_registration_catalogue_distinguishes_duplicate_ward_names_and_keeps_full_ward_filterable(): void
    {
        $firstWard = $this->createWard('RI-MELATI-A', 'Melati', 'duplicate-ward-a-0001');
        $secondWard = $this->createWard('RI-MELATI-B', 'Melati', 'duplicate-ward-b-0001');
        $firstBed = $this->createBed($firstWard, 'MEL-A-01', 'Bed Melati A', 'Ruang A', 'Kelas 1', 'duplicate-bed-a-0001');
        $secondBed = $this->createBed($secondWard, 'MEL-B-01', 'Bed Melati B', 'Ruang B', 'VIP', 'duplicate-bed-b-0001');
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rawat-inap.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('wards', 2)
                ->where('wards.0.public_id', $firstWard->public_id)
                ->where('wards.0.code', 'RI-MELATI-A')
                ->where('wards.0.display_name', 'Melati')
                ->where('wards.0.beds.0', [
                    'public_id' => $firstBed->public_id,
                    'code' => 'MEL-A-01',
                    'display_name' => 'Bed Melati A',
                    'room_label' => 'Ruang A',
                    'service_class' => 'Kelas 1',
                ])
                ->where('wards.1.public_id', $secondWard->public_id)
                ->where('wards.1.beds.0.service_class', 'VIP')
                ->where('wardOptions', [['value' => 'Melati', 'label' => 'Melati']]));

        InpatientLocationMutationScope::run(fn (): Encounter => Encounter::factory()->create([
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'status' => Encounter::STATUS_REGISTERED,
            'ward_name' => 'Melati',
            'ward_class' => $firstBed->service_class,
            'bed_code' => $firstBed->code,
            'inpatient_bed_id' => $firstBed->id,
            'registered_at' => now(),
        ]));

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rawat-inap.index', ['ward' => 'Melati']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('wards', 2)
                ->has('wards.0.beds', 0)
                ->where('wards.1.beds.0.public_id', $secondBed->public_id)
                ->has('todaysEncounters', 1)
                ->where('filters.ward', 'Melati'));
    }

    public function test_registration_and_examination_filters_include_current_renamed_and_historical_wards(): void
    {
        $ward = $this->createWard('RI-HISTORY', 'Bangsal Lama', 'history-ward-0001');
        $this->createBed($ward, 'HIS-01', 'Bed History', 'Ruang History', 'Kelas 2', 'history-bed-0001');
        InpatientLocationMutationScope::run(fn (): Encounter => Encounter::factory()->create([
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'status' => Encounter::STATUS_CLOSED,
            'ward_name' => 'Bangsal Lama',
            'ward_class' => 'Kelas 2',
            'bed_code' => 'HIS-01',
        ]));
        app(InpatientMasterService::class)->updateWard(
            $this->admin,
            $ward->public_id,
            'Bangsal Baru',
            1,
            InpatientMasterService::REASON_OPERATIONAL_CHANGE,
            'history-rename-0001',
            null,
        );
        $expected = [
            ['value' => 'Bangsal Baru', 'label' => 'Bangsal Baru'],
            ['value' => 'Bangsal Lama', 'label' => 'Bangsal Lama'],
        ];
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rawat-inap.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('wards.0.display_name', 'Bangsal Baru')
                ->where('wardOptions', $expected));

        $this->actingAs($nurse)
            ->get(route('pemeriksaan.rawat-inap.index'))
            ->assertInertia(fn (Assert $page) => $page->where('clinics', $expected));
    }

    public function test_census_query_empty_ward_and_stable_filter_options_have_honest_totals(): void
    {
        $empty = $this->createWard('RI-EMPTY', 'Bangsal Kosong', 'empty-ward-0001');
        $melati = $this->createWard('RI-MELATI-X', 'Bangsal Melati', 'filter-ward-a-0001');
        $mawar = $this->createWard('RI-MAWAR-X', 'Bangsal Mawar', 'filter-ward-b-0001');
        $this->createBed($melati, 'FILTER-A-01', 'Bed Melati', 'Ruang 1', 'Kelas 1', 'filter-bed-a-0001');
        $this->createBed($mawar, 'FILTER-B-01', 'Bed Mawar', 'Ruang 2', 'VIP', 'filter-bed-b-0001');

        $this->actingAs($this->admin)
            ->get(route('manajemen-data.bangsal.index', ['q' => 'Bangsal Kosong']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('wards', 1)
                ->where('wards.0.public_id', $empty->public_id)
                ->has('wards.0.beds', 0)
                ->where('totals.active_wards', 1)
                ->where('totals.active_beds', 0)
                ->where('filter_options.wards', [
                    ['value' => 'RI-EMPTY', 'label' => 'Bangsal Kosong (RI-EMPTY)'],
                    ['value' => 'RI-MAWAR-X', 'label' => 'Bangsal Mawar (RI-MAWAR-X)'],
                    ['value' => 'RI-MELATI-X', 'label' => 'Bangsal Melati (RI-MELATI-X)'],
                ])
                ->where('filter_options.service_classes', [
                    ['value' => 'Kelas 1', 'label' => 'Kelas 1'],
                    ['value' => 'VIP', 'label' => 'VIP'],
                ]));

        $this->actingAs($this->admin)
            ->get(route('manajemen-data.bangsal.index', ['q' => 'tidak-ada']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('wards', 0)
                ->has('filter_options.wards', 3));

        $this->actingAs($this->admin)
            ->get(route('manajemen-data.bangsal.index', ['q' => 'Bangsal Melati', 'service_class' => 'Kelas 1']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('wards', 1)
                ->where('wards.0.code', 'RI-MELATI-X')
                ->where('wards.0.beds.0.code', 'FILTER-A-01')
                ->has('filter_options.wards', 3)
                ->has('filter_options.service_classes', 2));

        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $this->actingAs($registrar)
            ->get(route('manajemen-data.bangsal.index', ['q' => 'Bangsal Kosong']))
            ->assertInertia(fn (Assert $page) => $page->has('wards', 0));
    }

    public function test_census_read_failure_is_safe_and_does_not_leak_exception_details(): void
    {
        $projection = Mockery::mock(InpatientOccupancyProjection::class);
        $projection->shouldReceive('forActor')->once()->andThrow(new RuntimeException('do-not-leak-database-detail'));
        $this->app->instance(InpatientOccupancyProjection::class, $projection);

        $this->actingAs($this->admin)
            ->get(route('manajemen-data.bangsal.index'))
            ->assertOk()
            ->assertDontSee('do-not-leak-database-detail')
            ->assertInertia(fn (Assert $page) => $page
                ->has('wards', 0)
                ->where('commands.create_ward_url', null)
                ->where('read_error', 'The bed census could not be loaded. Please try again.'));
    }

    public function test_retired_master_state_does_not_mask_an_impossible_active_claim(): void
    {
        [, $bed] = $this->createWardAndBed();
        app(InpatientMasterService::class)->retireBed(
            $this->admin,
            $bed->public_id,
            1,
            InpatientMasterService::REASON_RETIREMENT,
            'retire-before-drift-0001',
            null,
        );
        InpatientLocationMutationScope::run(fn (): Encounter => Encounter::factory()->create([
            'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'status' => Encounter::STATUS_REGISTERED,
            'ward_name' => 'Melati',
            'ward_class' => 'Kelas 1',
            'bed_code' => $bed->code,
            'inpatient_bed_id' => $bed->id,
        ]));

        $this->actingAs($this->admin)
            ->get(route('manajemen-data.bangsal.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('wards.0.beds.0.state', InpatientBed::STATE_RETIRED)
                ->where('wards.0.beds.0.occupancy.state', 'OCCUPIED')
                ->where('totals.active_beds', 0)
                ->where('totals.occupied_beds', 1));
    }

    public function test_model_mutations_outside_service_scope_are_refused(): void
    {
        [$ward, $bed] = $this->createWardAndBed();
        $attempts = [
            fn () => InpatientWard::query()->create(['code' => 'DIRECT', 'display_name' => 'Direct', 'state' => 'ACTIVE', 'version' => 1]),
            fn () => InpatientBed::query()->create(['ward_id' => $ward->id, 'code' => 'DIRECT-BED', 'display_name' => 'Direct', 'room_label' => 'Direct', 'service_class' => 'Direct', 'state' => 'ACTIVE', 'version' => 1]),
            fn () => InpatientBedVersion::query()->create(['bed_id' => $bed->id, 'actor_user_id' => $this->admin->id, 'version' => 2, 'display_name' => 'Direct', 'room_label' => 'Direct', 'service_class' => 'Direct', 'state' => 'ACTIVE', 'reason_code' => InpatientMasterService::REASON_DATA_CORRECTION, 'before_digest' => str_repeat('a', 64), 'after_digest' => str_repeat('b', 64)]),
            fn () => InpatientMasterOperationReceipt::query()->create(['actor_user_id' => $this->admin->id, 'operation' => 'WARD_CREATE', 'idempotency_key' => 'direct-receipt', 'payload_digest' => str_repeat('a', 64), 'result_type' => 'WARD', 'result_public_id' => $ward->public_id, 'completed_at' => now()]),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('Direct inpatient master mutation unexpectedly succeeded.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('Inpatient master', $exception->getMessage());
            }
        }

        $directSqlAttempts = [
            fn () => DB::table('inpatient_wards')->where('id', $ward->id)->update(['display_name' => 'Bypass']),
            fn () => DB::table('inpatient_beds')->where('id', $bed->id)->delete(),
            fn () => DB::table('inpatient_master_code_reservations')->insert([
                'public_id' => (string) Str::ulid(),
                'actor_user_id' => $this->admin->id,
                'master_type' => 'WARD',
                'normalized_code' => 'RAW-BYPASS',
                'created_at' => now(),
            ]),
        ];
        foreach ($directSqlAttempts as $attempt) {
            try {
                $attempt();
                $this->fail('Direct SQL inpatient master mutation unexpectedly succeeded.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('Direct SQL writes', $exception->getMessage());
            }
        }

        $this->assertSame('Melati', $ward->fresh()->display_name);
        $this->assertNotNull($bed->fresh());
        $this->assertDatabaseMissing('inpatient_master_code_reservations', ['normalized_code' => 'RAW-BYPASS']);

        $this->assertSame(0, InpatientMasterDirectWriteScope::run(
            fn (): int => DB::table('inpatient_wards')->where('id', -1)->update(['display_name' => 'No row']),
        ));
        try {
            InpatientMasterDirectWriteScope::run(static fn (): never => throw new RuntimeException('scope-finally-check'));
        } catch (RuntimeException $exception) {
            $this->assertSame('scope-finally-check', $exception->getMessage());
        }
        try {
            DB::table('inpatient_wards')->where('id', $ward->id)->update(['display_name' => 'After scope']);
            $this->fail('Direct-write scope leaked after callback completion.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Direct SQL writes', $exception->getMessage());
        }

        $guard = app(InpatientMasterSqlWriteGuard::class);
        $guard->assertAllowed('EXPLAIN UPDATE inpatient_wards SET display_name = ? WHERE id = ?');
        try {
            $guard->assertAllowed('EXPLAIN (ANALYZE, BUFFERS) DELETE FROM inpatient_wards WHERE id = 0');
            $this->fail('EXPLAIN ANALYZE mutating a protected table unexpectedly passed the SQL guard.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Direct SQL writes', $exception->getMessage());
        }
    }

    public function test_idempotency_keys_are_case_canonical_and_conflicts_are_portable(): void
    {
        $payload = [...$this->wardPayload(), 'idempotency_key' => 'Create-Ward-Case-0001'];
        $this->actingAs($this->admin)->post(route('manajemen-data.bangsal.wards.store'), $payload)->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('manajemen-data.bangsal.wards.store'), [...$payload, 'idempotency_key' => 'create-ward-case-0001'])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('inpatient_wards', 1);
        $this->assertDatabaseCount('inpatient_master_operation_receipts', 1);
        $this->assertSame('create-ward-case-0001', InpatientMasterOperationReceipt::query()->sole()->idempotency_key);

        $this->actingAs($this->admin)
            ->post(route('manajemen-data.bangsal.wards.store'), [...$payload, 'display_name' => 'Payload lain', 'idempotency_key' => 'CREATE-WARD-CASE-0001'])
            ->assertSessionHasErrors('master');
        $this->assertSame('Melati', InpatientWard::query()->sole()->display_name);
    }

    public function test_reset_retains_code_tombstones_and_allows_only_new_codes(): void
    {
        $this->createWardAndBed();
        app(SyntheticResetService::class)->reset(['actor' => $this->admin, 'reason' => 'code_tombstone_test']);
        $this->assertDatabaseCount('inpatient_master_code_reservations', 2);

        $this->actingAs($this->admin)
            ->post(route('manajemen-data.bangsal.wards.store'), [...$this->wardPayload(), 'idempotency_key' => 'reuse-old-ward-code-0001'])
            ->assertSessionHasErrors('master');

        $this->actingAs($this->admin)
            ->post(route('manajemen-data.bangsal.wards.store'), [
                'code' => 'RI-BARU', 'display_name' => 'Bangsal Baru',
                'reason_code' => InpatientMasterService::REASON_INITIAL_SETUP,
                'idempotency_key' => 'create-new-ward-code-0001',
            ])
            ->assertSessionHasNoErrors();
        $newWard = InpatientWard::query()->sole();

        $this->actingAs($this->admin)
            ->post(route('manajemen-data.bangsal.wards.beds.store', $newWard), [...$this->bedPayload(), 'idempotency_key' => 'reuse-old-bed-code-0001'])
            ->assertSessionHasErrors('master');
        $this->actingAs($this->admin)
            ->post(route('manajemen-data.bangsal.wards.beds.store', $newWard), [
                ...$this->bedPayload(),
                'code' => 'BARU-01',
                'idempotency_key' => 'create-new-bed-code-0001',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('inpatient_wards', ['code' => 'RI-BARU']);
        $this->assertDatabaseHas('inpatient_beds', ['code' => 'BARU-01']);
        $this->assertDatabaseCount('inpatient_master_code_reservations', 4);
    }

    public function test_synthetic_reset_removes_master_chain_but_retained_audit_blocks_down(): void
    {
        $this->createWardAndBed();
        app(SyntheticResetService::class)->reset(['actor' => $this->admin, 'reason' => 'reset_inpatient_master_test']);

        $this->assertDatabaseCount('inpatient_master_operation_receipts', 0);
        $this->assertDatabaseCount('inpatient_bed_versions', 0);
        $this->assertDatabaseCount('inpatient_beds', 0);
        $this->assertDatabaseCount('inpatient_ward_versions', 0);
        $this->assertDatabaseCount('inpatient_wards', 0);

        $migration = require database_path('migrations/2026_08_30_000300_create_inpatient_ward_bed_masters.php');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('correlated audit evidence remains');
        $migration->down();
    }

    /** @return array{InpatientWard, InpatientBed} */
    private function createWardAndBed(): array
    {
        $this->actingAs($this->admin)
            ->post(route('manajemen-data.bangsal.wards.store'), $this->wardPayload())
            ->assertSessionHasNoErrors();
        $ward = InpatientWard::query()->firstOrFail();
        $this->actingAs($this->admin)
            ->post(route('manajemen-data.bangsal.wards.beds.store', $ward), $this->bedPayload())
            ->assertSessionHasNoErrors();

        return [$ward, InpatientBed::query()->firstOrFail()];
    }

    private function createWard(string $code, string $displayName, string $key): InpatientWard
    {
        return app(InpatientMasterService::class)->createWard(
            $this->admin,
            $code,
            $displayName,
            InpatientMasterService::REASON_INITIAL_SETUP,
            $key,
            null,
        )->master;
    }

    private function createBed(InpatientWard $ward, string $code, string $displayName, string $roomLabel, string $serviceClass, string $key): InpatientBed
    {
        return app(InpatientMasterService::class)->createBed(
            $this->admin,
            $ward->public_id,
            $code,
            $displayName,
            $roomLabel,
            $serviceClass,
            InpatientMasterService::REASON_INITIAL_SETUP,
            $key,
            null,
        )->master;
    }

    private function wardPayload(): array
    {
        return ['code' => 'ri-melati', 'display_name' => 'Melati', 'reason_code' => InpatientMasterService::REASON_INITIAL_SETUP, 'idempotency_key' => 'create-ward-melati-0001'];
    }

    private function bedPayload(): array
    {
        return ['code' => 'a-01', 'display_name' => 'Tempat Tidur A-01', 'room_label' => 'Ruang Melati', 'service_class' => 'Kelas 1', 'reason_code' => InpatientMasterService::REASON_INITIAL_SETUP, 'idempotency_key' => 'create-bed-melati-a01'];
    }

    private function retirePayload(string $key): array
    {
        return ['expected_version' => 1, 'reason_code' => InpatientMasterService::REASON_RETIREMENT, 'idempotency_key' => $key];
    }

    private function registrationPayload(InpatientBed $bed, array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Pasien RI Managed', 'date_of_birth' => '1990-05-15', 'sex' => Patient::SEX_PEREMPUAN,
            'nik' => '3174011555900088', 'bed_public_id' => $bed->public_id, 'ward_name' => 'Snapshot palsu',
            'ward_class' => 'Snapshot palsu', 'bed_code' => 'PALSU-01', 'payer_type' => Encounter::PAYER_UMUM,
            'continue_from' => Encounter::CONTINUE_LANGSUNG, 'chief_complaint' => 'Observasi sintetis', 'is_synthetic' => true,
            'admission_authority_type' => Encounter::AUTHORITY_PLANNED_ORDER,
            'admission_authority_reference' => 'ORDER-WARD-BED-MASTER-0001',
        ], $overrides);
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }

    private function auditCount(string $action): int
    {
        return AuditEvent::query()->where('action', $action)->count();
    }

    private function auditMetadata(string $action): array
    {
        return AuditEvent::query()->where('action', $action)->firstOrFail()->metadata;
    }
}
