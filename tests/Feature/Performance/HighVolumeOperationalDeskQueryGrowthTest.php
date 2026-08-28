<?php

namespace Tests\Feature\Performance;

use App\Models\Encounter;
use App\Models\LabServiceRequest;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use Database\Seeders\OutpatientMastersSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\WilayahMinimalSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class HighVolumeOperationalDeskQueryGrowthTest extends TestCase
{
    use RefreshDatabase;

    private bool $measureSelectQueries = false;

    private int $selectQueryCount = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(OutpatientMastersSeeder::class);
        $this->seed(WilayahMinimalSeeder::class);

        DB::listen(function (QueryExecuted $query): void {
            if ($this->measureSelectQueries && str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $this->selectQueryCount++;
            }
        });
    }

    public function test_registration_desk_select_queries_do_not_grow_per_visible_encounter(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $this->createOutpatientEncounters(1, $registrar, Encounter::STATUS_REGISTERED, 'REG');
        $url = route('pendaftaran.rawat-jalan.index');

        $this->actingAs($registrar)->get($url)->assertOk();
        [$smallResponse, $smallQueries] = $this->measureRequest(fn (): TestResponse => $this->get($url));
        $smallResponse->assertInertia(fn (Assert $page) => $page
            ->component('pendaftaran/rawat-jalan')
            ->has('todaysEncounters', 1));

        $this->createOutpatientEncounters(24, $registrar, Encounter::STATUS_REGISTERED, 'REG-LARGE');
        [$largeResponse, $largeQueries] = $this->measureRequest(fn (): TestResponse => $this->get($url));
        $largeResponse->assertInertia(fn (Assert $page) => $page
            ->component('pendaftaran/rawat-jalan')
            ->has('todaysEncounters', 25));

        $this->assertBoundedSelectQueryGrowth('registration desk', $smallQueries, $largeQueries);
    }

    public function test_examination_worklist_select_queries_do_not_grow_per_visible_encounter(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $this->createOutpatientEncounters(1, $registrar, Encounter::STATUS_IN_EXAMINATION, 'EXAM');
        $url = route('pemeriksaan.rawat-jalan.index');

        $this->actingAs($physician)->get($url)->assertOk();
        [$smallResponse, $smallQueries] = $this->measureRequest(fn (): TestResponse => $this->get($url));
        $smallResponse->assertInertia(fn (Assert $page) => $page
            ->component('pemeriksaan/rawat-jalan/index')
            ->has('encounters', 1));

        $this->createOutpatientEncounters(24, $registrar, Encounter::STATUS_IN_EXAMINATION, 'EXAM-LARGE');
        [$largeResponse, $largeQueries] = $this->measureRequest(fn (): TestResponse => $this->get($url));
        $largeResponse->assertInertia(fn (Assert $page) => $page
            ->component('pemeriksaan/rawat-jalan/index')
            ->has('encounters', 25));

        $this->assertBoundedSelectQueryGrowth('examination worklist', $smallQueries, $largeQueries);
    }

    public function test_laboratory_worklist_select_queries_do_not_grow_per_visible_order(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);
        $this->createLabOrders(1, $registrar, $physician, 'LAB');
        $url = route('pemeriksaan.laboratorium.index');

        $this->actingAs($nurse)->get($url)->assertOk();
        [$smallResponse, $smallQueries] = $this->measureRequest(fn (): TestResponse => $this->get($url));
        $smallResponse->assertInertia(fn (Assert $page) => $page
            ->component('pemeriksaan/laboratorium/index')
            ->has('orders', 1));

        $this->createLabOrders(24, $registrar, $physician, 'LAB-LARGE');
        [$largeResponse, $largeQueries] = $this->measureRequest(fn (): TestResponse => $this->get($url));
        $largeResponse->assertInertia(fn (Assert $page) => $page
            ->component('pemeriksaan/laboratorium/index')
            ->has('orders', 25));

        $this->assertBoundedSelectQueryGrowth('laboratory worklist', $smallQueries, $largeQueries);
    }

    public function test_registration_recap_select_queries_do_not_grow_per_visible_encounter(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $this->createOutpatientEncounters(1, $registrar, Encounter::STATUS_REGISTERED, 'RECAP');
        $url = route('pendaftaran.rekap', [
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ]);

        $this->actingAs($registrar)->get($url)->assertOk();
        [$smallResponse, $smallQueries] = $this->measureRequest(fn (): TestResponse => $this->get($url));
        $smallResponse->assertInertia(fn (Assert $page) => $page
            ->component('pendaftaran/rekap')
            ->has('rows', 1));

        $this->createOutpatientEncounters(24, $registrar, Encounter::STATUS_REGISTERED, 'RECAP-LARGE');
        [$largeResponse, $largeQueries] = $this->measureRequest(fn (): TestResponse => $this->get($url));
        $largeResponse->assertInertia(fn (Assert $page) => $page
            ->component('pendaftaran/rekap')
            ->has('rows', 25));

        $this->assertBoundedSelectQueryGrowth('registration recap', $smallQueries, $largeQueries);
    }

    public function test_operational_desks_expose_navigation_after_the_first_page(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $physician = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);
        $this->createLabOrders(101, $registrar, $physician, 'PAGE');

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rawat-jalan.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('todaysEncounters', 50)
                ->where('todaysEncountersPagination.current_page', 1)
                ->where('todaysEncountersPagination.last_page', 3)
                ->where('todaysEncountersPagination.total', 101)
                ->where('todaysEncountersPagination.from', 1)
                ->where('todaysEncountersPagination.to', 50)
                ->where('todaysEncountersPagination.next_page_url', fn (mixed $url): bool => is_string($url)
                    && str_starts_with($url, '/')
                    && ! str_contains($url, '://')
                    && str_contains($url, 'encounter_page=2')));

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rawat-jalan.index', ['encounter_page' => 3]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('todaysEncounters', 1)
                ->where('todaysEncountersPagination.current_page', 3)
                ->where('todaysEncountersPagination.from', 101)
                ->where('todaysEncountersPagination.to', 101));

        $this->actingAs($physician)
            ->get(route('pemeriksaan.rawat-jalan.index', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('encounters', 1)
                ->where('pagination.current_page', 2)
                ->where('pagination.total', 101)
                ->where('pagination.from', 101)
                ->where('pagination.to', 101));

        $this->actingAs($nurse)
            ->get(route('pemeriksaan.laboratorium.index', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 1)
                ->where('pagination.current_page', 2)
                ->where('pagination.total', 101)
                ->where('pagination.from', 101)
                ->where('pagination.to', 101));

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rawat-jalan.index', ['encounter_page' => 999]))
            ->assertRedirect(route('pendaftaran.rawat-jalan.index', ['encounter_page' => 3], false));
        $this->actingAs($physician)
            ->get(route('pemeriksaan.rawat-jalan.index', ['page' => 999]))
            ->assertRedirect(route('pemeriksaan.rawat-jalan.index', ['page' => 2], false));
        $this->actingAs($nurse)
            ->get(route('pemeriksaan.laboratorium.index', ['page' => 999]))
            ->assertRedirect(route('pemeriksaan.laboratorium.index', ['page' => 2], false));
        $this->actingAs($registrar)
            ->get(route('pendaftaran.rekap', [
                'date_from' => now()->toDateString(),
                'date_to' => now()->toDateString(),
                'page' => 999,
            ]))
            ->assertRedirect(route('pendaftaran.rekap', [
                'date_from' => now()->toDateString(),
                'date_to' => now()->toDateString(),
                'page' => 1,
            ], false));

        $this->actingAs($registrar)
            ->withHeader('Host', 'attacker.example')
            ->get(route('pendaftaran.rawat-jalan.index', ['encounter_page' => 999], false))
            ->assertRedirect(route('pendaftaran.rawat-jalan.index', ['encounter_page' => 3], false));

        $this->actingAs($registrar)
            ->withHeader('Host', 'attacker.example')
            ->get(route('pendaftaran.rawat-jalan.index', [], false))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('todaysEncountersPagination.next_page_url', fn (mixed $url): bool => is_string($url)
                    && str_starts_with($url, '/')
                    && ! str_contains($url, 'attacker.example')
                    && ! str_contains($url, '://')));
    }

    /**
     * @return array{TestResponse<Response>, int}
     */
    private function measureRequest(callable $request): array
    {
        $this->selectQueryCount = 0;
        $this->measureSelectQueries = true;

        try {
            $response = $request();
        } finally {
            $this->measureSelectQueries = false;
        }

        $response->assertOk();

        return [$response, $this->selectQueryCount];
    }

    private function assertBoundedSelectQueryGrowth(string $desk, int $smallQueries, int $largeQueries): void
    {
        $this->assertGreaterThan(0, $smallQueries, "{$desk} query measurement captured no SELECT statements.");
        $this->assertLessThanOrEqual(
            $smallQueries + 1,
            $largeQueries,
            "{$desk} SELECT count grew from {$smallQueries} to {$largeQueries} when visible rows grew from 1 to 25.",
        );
    }

    private function createOutpatientEncounters(int $count, User $registrar, string $status, string $prefix): void
    {
        foreach (range(1, $count) as $index) {
            $patient = Patient::factory()->create([
                'created_by_user_id' => $registrar->id,
                'is_synthetic' => true,
                'medical_record_number' => sprintf('SYNTH-%s-%03d', $prefix, $index),
                'full_name' => sprintf('Pasien Sintetis %s %03d', $prefix, $index),
            ]);

            Encounter::factory()->create([
                'patient_id' => $patient->id,
                'registered_by_user_id' => $registrar->id,
                'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
                'status' => $status,
                'registered_at' => now(),
                'visit_date' => now()->toDateString(),
            ]);
        }
    }

    private function createLabOrders(int $count, User $registrar, User $physician, string $prefix): void
    {
        foreach (range(1, $count) as $index) {
            $patient = Patient::factory()->create([
                'created_by_user_id' => $registrar->id,
                'is_synthetic' => true,
                'medical_record_number' => sprintf('SYNTH-%s-%03d', $prefix, $index),
                'full_name' => sprintf('Pasien Sintetis %s %03d', $prefix, $index),
            ]);
            $encounter = Encounter::factory()->create([
                'patient_id' => $patient->id,
                'registered_by_user_id' => $registrar->id,
                'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
                'status' => Encounter::STATUS_IN_EXAMINATION,
                'registered_at' => now(),
                'visit_date' => now()->toDateString(),
            ]);

            LabServiceRequest::factory()->create([
                'encounter_id' => $encounter->id,
                'requested_by_user_id' => $physician->id,
                'test_code' => 'HB',
                'test_label' => 'Hemoglobin',
                'status' => LabServiceRequest::STATUS_ACTIVE,
                'requested_at' => now(),
            ]);
        }
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
