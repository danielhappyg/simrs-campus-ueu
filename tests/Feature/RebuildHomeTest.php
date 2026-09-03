<?php

namespace Tests\Feature;

use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientWard;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Inpatient\InpatientLocationMutationScope;
use App\Support\Inpatient\InpatientMasterActorPolicy;
use App\Support\Inpatient\InpatientMasterService;
use App\Support\Inpatient\InpatientOccupancyProjection;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class RebuildHomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_guests_are_redirected_from_home_to_login(): void
    {
        $this->get(route('home'))
            ->assertRedirect(route('login'));
    }

    public function test_roleless_user_sees_counts_without_unauthorized_actions_or_occupancy(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('rebuild/home')
                ->where('census.available', true)
                ->where('census.by_setting.rawat_jalan.total_active', 0)
                ->where('census.by_setting.igd.total_active', 0)
                ->where('census.by_setting.rawat_inap.total_active', 0)
                ->where('occupancy', null)
                ->has('queues', 0)
                ->has('actions', 0));
    }

    public function test_home_returns_only_todays_active_synthetic_census_by_care_setting_and_status(): void
    {
        $user = User::factory()->create();

        $this->createEncounter($user, Encounter::CARE_SETTING_OUTPATIENT);
        $this->createEncounter($user, Encounter::CARE_SETTING_OUTPATIENT, Encounter::STATUS_IN_EXAMINATION);
        $this->createEncounter($user, Encounter::CARE_SETTING_EMERGENCY, Encounter::STATUS_READY_FOR_RM);
        $this->createEncounter($user, Encounter::CARE_SETTING_INPATIENT);
        $this->createEncounter($user, Encounter::CARE_SETTING_OUTPATIENT, Encounter::STATUS_CLOSED);
        $this->createEncounter($user, Encounter::CARE_SETTING_EMERGENCY, Encounter::STATUS_CANCELLED);
        $this->createEncounter($user, Encounter::CARE_SETTING_INPATIENT, Encounter::STATUS_REGISTERED, now()->subDay());
        $this->createEncounter($user, Encounter::CARE_SETTING_OUTPATIENT, Encounter::STATUS_REGISTERED, now(), false);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('census.available', true)
                ->where('census.by_setting.rawat_jalan.registered', 1)
                ->where('census.by_setting.rawat_jalan.in_examination', 1)
                ->where('census.by_setting.rawat_jalan.ready_for_rm', 0)
                ->where('census.by_setting.rawat_jalan.total_active', 2)
                ->where('census.by_setting.igd.registered', 0)
                ->where('census.by_setting.igd.in_examination', 0)
                ->where('census.by_setting.igd.ready_for_rm', 1)
                ->where('census.by_setting.igd.total_active', 1)
                ->where('census.by_setting.rawat_inap.registered', 1)
                ->where('census.by_setting.rawat_inap.in_examination', 0)
                ->where('census.by_setting.rawat_inap.ready_for_rm', 0)
                ->where('census.by_setting.rawat_inap.total_active', 1)
                ->where('census.read_error', null));
    }

    public function test_registrar_receives_only_routes_the_role_can_open(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);

        $this->actingAs($registrar)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('actions', 7)
                ->where('actions.0.href', route('pendaftaran.rawat-jalan.index'))
                ->where('actions.1.href', route('pendaftaran.igd.index'))
                ->where('actions.2.href', route('pendaftaran.rawat-inap.index'))
                ->where('actions.3.href', route('pemeriksaan.rawat-jalan.index'))
                ->where('actions.4.href', route('pemeriksaan.igd.index'))
                ->where('actions.5.href', route('pemeriksaan.rawat-inap.index'))
                ->where('actions.6.href', route('manajemen-data.bangsal.index'))
                ->has('queues', 5)
                ->where('queues.0.id', 'queue.registered.rj')
                ->where('queues.0.href', route('pendaftaran.rawat-jalan.index'))
                ->where('queues.1.id', 'queue.in_exam.rj')
                ->where('queues.1.href', route('pemeriksaan.rawat-jalan.index'))
                ->where('queues.2.id', 'queue.in_exam.igd')
                ->where('queues.2.href', route('pemeriksaan.igd.index'))
                ->where('queues.3.id', 'queue.in_exam.ri')
                ->where('queues.3.href', route('pemeriksaan.rawat-inap.index'))
                ->where('queues.4.id', 'queue.occupancy')
                ->where('queues.4.href', route('manajemen-data.bangsal.index'))
                ->where('occupancy.available', true));
    }

    public function test_rmik_action_is_hidden_without_rmik_review_and_shown_for_rmik_role(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $rmik = $this->userWithRole(RoleCapabilityMatrix::ROLE_RMIK);

        $registrarResponse = $this->actingAs($registrar)->get(route('home'));
        $this->assertStringNotContainsString(
            route('rm.rawat-jalan.index'),
            (string) $registrarResponse->getContent(),
        );

        $this->actingAs($rmik)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('actions', 9)
                ->where('actions.3.href', route('pemeriksaan.rawat-jalan.index'))
                ->where('actions.4.href', route('pemeriksaan.igd.index'))
                ->where('actions.5.href', route('pemeriksaan.rawat-inap.index'))
                ->where('actions.6.href', route('rm.rawat-jalan.index'))
                ->where('actions.7.href', route('rm.rawat-inap.index'))
                ->where('actions.8.href', route('manajemen-data.bangsal.index'))
                ->has('queues', 7)
                ->where('queues.2.id', 'queue.ready_rm.rj')
                ->where('queues.2.href', route('rm.rawat-jalan.index'))
                ->where('queues.5.id', 'queue.ready_rm.ri')
                ->where('queues.5.href', route('rm.rawat-inap.index')));
    }

    public function test_permitted_home_uses_managed_occupancy_projection_totals_without_patient_details(): void
    {
        $admin = $this->userWithRole(RoleCapabilityMatrix::ROLE_ADMIN);
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        [$ward, $occupiedBed] = $this->createWardWithTwoBeds($admin);

        $this->createEncounter(
            $admin,
            Encounter::CARE_SETTING_INPATIENT,
            Encounter::STATUS_REGISTERED,
            now(),
            true,
            [
                'ward_name' => $ward->display_name,
                'ward_class' => $occupiedBed->service_class,
                'bed_code' => $occupiedBed->code,
                'inpatient_bed_id' => $occupiedBed->id,
            ],
        );

        $response = $this->actingAs($registrar)->get(route('home'));

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('occupancy.available', true)
                ->where('occupancy.totals.active_wards', 1)
                ->where('occupancy.totals.active_beds', 2)
                ->where('occupancy.totals.occupied_beds', 1)
                ->where('occupancy.totals.available_beds', 1)
                ->where('occupancy.read_error', null)
                ->where('queues.4.id', 'queue.occupancy')
                ->where('queues.4.count', 1));
        $this->assertStringNotContainsString('patient_name', (string) $response->getContent());
        $this->assertStringNotContainsString('medical_record_number', (string) $response->getContent());
    }

    public function test_permitted_home_exposes_explicit_occupancy_unavailable_state_on_projection_failure(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $projection = new class(app(InpatientMasterActorPolicy::class)) extends InpatientOccupancyProjection
        {
            /** @param array{q: string, ward_code: string, service_class: string, occupancy_state: string, master_state: string} $filters */
            public function forActor(User $actor, array $filters): array
            {
                throw new RuntimeException('read failed');
            }
        };
        $this->app->instance(InpatientOccupancyProjection::class, $projection);

        $this->actingAs($registrar)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('occupancy.available', false)
                ->where('occupancy.read_error', 'Status hunian rawat inap belum dapat dimuat. Silakan coba lagi.')
                ->where('queues.4.id', 'queue.occupancy')
                ->where('queues.4.count', null));
    }

    public function test_home_exposes_explicit_census_unavailable_state_on_read_failure(): void
    {
        $user = User::factory()->create();
        Schema::rename('encounters', 'encounters_unavailable_for_home_test');

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('census.available', false)
                ->where('census.by_setting.rawat_jalan.registered', null)
                ->where('census.by_setting.rawat_jalan.in_examination', null)
                ->where('census.by_setting.rawat_jalan.ready_for_rm', null)
                ->where('census.by_setting.rawat_jalan.total_active', null)
                ->where('census.by_setting.igd.total_active', null)
                ->where('census.by_setting.rawat_inap.total_active', null)
                ->where('census.read_error', 'Ringkasan kunjungan belum dapat dimuat. Silakan coba lagi.'));
    }

    /** @param array<string, mixed> $overrides */
    private function createEncounter(
        User $actor,
        string $careSetting,
        string $status = Encounter::STATUS_REGISTERED,
        mixed $registeredAt = null,
        bool $synthetic = true,
        array $overrides = [],
    ): Encounter {
        $patient = Patient::factory()->create([
            'created_by_user_id' => $actor->id,
            'is_synthetic' => $synthetic,
        ]);

        return InpatientLocationMutationScope::run(fn (): Encounter => Encounter::factory()->create(array_merge([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $actor->id,
            'care_setting' => $careSetting,
            'status' => $status,
            'registered_at' => $registeredAt ?? now(),
        ], $overrides)));
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }

    /** @return array{InpatientWard, InpatientBed} */
    private function createWardWithTwoBeds(User $admin): array
    {
        $service = app(InpatientMasterService::class);
        $ward = $service->createWard(
            $admin,
            'RI-HOME',
            'Bangsal Home',
            InpatientMasterService::REASON_INITIAL_SETUP,
            'home-create-ward-0001',
            null,
        )->master;
        if (! $ward instanceof InpatientWard) {
            throw new RuntimeException('Ward creation returned the wrong master type.');
        }

        $occupiedBed = $service->createBed(
            $admin,
            $ward->public_id,
            'HOME-01',
            'Tempat Tidur 01',
            'Ruang Home',
            'Kelas 1',
            InpatientMasterService::REASON_INITIAL_SETUP,
            'home-create-bed-0001',
            null,
        )->master;
        if (! $occupiedBed instanceof InpatientBed) {
            throw new RuntimeException('Bed creation returned the wrong master type.');
        }

        $service->createBed(
            $admin,
            $ward->public_id,
            'HOME-02',
            'Tempat Tidur 02',
            'Ruang Home',
            'Kelas 1',
            InpatientMasterService::REASON_INITIAL_SETUP,
            'home-create-bed-0002',
            null,
        );

        return [$ward, $occupiedBed];
    }
}
