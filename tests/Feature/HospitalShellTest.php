<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Teaching\Models\SimulationSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;

class HospitalShellTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    public function test_home_redirects_to_hospital_desk(): void
    {
        $this->seedReferenceOutpatient();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();

        $this->actingAs($registrar)
            ->get('/')
            ->assertRedirect('/desk');
    }

    public function test_registrar_sees_desk_dashboard_with_population_cues(): void
    {
        $this->seedReferenceOutpatient();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();

        $this->actingAs($registrar)
            ->get(route('desk'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('hospital/desk')
                ->where('summary.population', 13)
                ->where('canOpenPendaftaran', true)
                ->has('clinicQueue', 1)
                ->has('urls.pendaftaran'));
    }

    public function test_pendaftaran_desk_redirects_to_session_registration(): void
    {
        $this->seedReferenceOutpatient();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $session = SimulationSession::query()->where('code', 'SIM-RJ-UEU-001')->firstOrFail();

        $this->actingAs($registrar)
            ->get(route('desk.pendaftaran'))
            ->assertRedirect(route('sessions.registration', $session));
    }

    public function test_registration_workspace_exposes_population_and_baru_lama_kinds(): void
    {
        $this->seedReferenceOutpatient();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $session = SimulationSession::query()->where('code', 'SIM-RJ-UEU-001')->firstOrFail();

        $this->actingAs($registrar)
            ->get(route('sessions.registration', [$session, 'q' => 'Bagas']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('patient/registration')
                ->has('population', 13)
                ->has('candidates', 1)
                ->where('candidates.0.kind', 'LAMA')
                ->where('candidates.0.kindLabel', 'Pasien lama')
                ->has('clinicQueue'));
    }

    public function test_connected_module_desks_list_shared_encounters(): void
    {
        $this->seedReferenceOutpatient();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $coder = User::query()->where('email', 'koder.rmik@example.invalid')->firstOrFail();
        $pharmacist = User::query()->where('email', 'mahasiswa.farmasi@example.invalid')->firstOrFail();

        $this->actingAs($nurse)
            ->get(route('desk.pemeriksaan'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('hospital/module-desk')
                ->where('module.key', 'pemeriksaan')
                ->has('encounters', 1));

        $this->actingAs($coder)
            ->get(route('desk.rekam-medis'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('module.key', 'rekam-medis')
                ->has('encounters', 1));

        $this->actingAs($pharmacist)
            ->get(route('desk.apotek'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('module.key', 'apotek')
                ->has('encounters', 1));

        $this->actingAs($coder)
            ->get(route('desk.klaim'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('module.key', 'klaim')
                ->where('module.educationalNote', fn ($note) => is_string($note) && str_contains($note, 'edukatif')));
    }

    public function test_bpjs_module_stays_educational_only(): void
    {
        $this->seedReferenceOutpatient();
        $facilitator = User::query()->where('email', 'fasilitator.simulasi@example.invalid')->firstOrFail();

        $this->actingAs($facilitator)
            ->get(route('desk.bpjs'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('module.key', 'bpjs')
                ->where('module.educationalNote', fn ($note) => is_string($note)
                    && str_contains($note, 'pembelajaran')
                    && str_contains($note, 'BPJS')));
    }

    public function test_role_without_capability_cannot_open_module_desk(): void
    {
        $this->seedReferenceOutpatient();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();

        $this->actingAs($nurse)
            ->get(route('desk.pendaftaran'))
            ->assertForbidden();
    }
}
