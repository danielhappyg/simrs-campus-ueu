<?php

namespace Tests\Feature\Registration;

use App\Models\Encounter;
use App\Models\EncounterCancellation;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Inpatient\InpatientLocationMutationScope;
use Database\Seeders\OutpatientMastersSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EncounterCancellationRegistrationProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(OutpatientMastersSeeder::class);
    }

    public function test_rj_igd_and_ri_registration_pages_project_cancellation_history(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $cancelledAt = now()->setMicrosecond(0);

        $outpatient = $this->cancelledEncounter(
            Encounter::CARE_SETTING_OUTPATIENT,
            $registrar,
            EncounterCancellation::REASON_WRONG_REGISTRATION,
            'Pendaftaran poli diperbaiki sebelum pelayanan.',
            $cancelledAt,
            ['clinic_name' => 'Poliklinik Umum', 'queue_number' => 11],
        );
        $emergency = $this->cancelledEncounter(
            Encounter::CARE_SETTING_EMERGENCY,
            $registrar,
            EncounterCancellation::REASON_DUPLICATE_ENCOUNTER,
            null,
            $cancelledAt,
            ['clinic_name' => 'Instalasi Gawat Darurat', 'queue_number' => 12],
        );
        $inpatient = $this->cancelledEncounter(
            Encounter::CARE_SETTING_INPATIENT,
            $registrar,
            EncounterCancellation::REASON_PLAN_CHANGED_BEFORE_SERVICE,
            'Rencana rawat inap berubah sebelum pelayanan.',
            $cancelledAt,
            [
                'clinic_name' => 'Bangsal Anggrek',
                'ward_name' => 'Bangsal Anggrek',
                'ward_class' => 'Kelas 1',
                'bed_code' => 'ANG-101-A',
                'continue_from' => Encounter::CONTINUE_LANGSUNG,
                'queue_number' => 13,
            ],
        );

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rawat-jalan.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pendaftaran/rawat-jalan')
                ->where('canCancel', true)
                ->where('todaysEncounters.0.public_id', $outpatient->public_id)
                ->where('todaysEncounters.0.status', Encounter::STATUS_CANCELLED)
                ->where('todaysEncounters.0.queue_number', 11)
                ->where('todaysEncounters.0.cancellation.reason_code', EncounterCancellation::REASON_WRONG_REGISTRATION)
                ->where('todaysEncounters.0.cancellation.note', 'Pendaftaran poli diperbaiki sebelum pelayanan.')
                ->where('todaysEncounters.0.cancellation.cancelled_at', $cancelledAt->toIso8601String())
                ->where('todaysEncounters.0.cancellation.cancelled_by', $registrar->name));

        $this->actingAs($registrar)
            ->get(route('pendaftaran.igd.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pendaftaran/rawat-jalan')
                ->where('variant', 'igd')
                ->where('canCancel', true)
                ->where('todaysEncounters.0.public_id', $emergency->public_id)
                ->where('todaysEncounters.0.status', Encounter::STATUS_CANCELLED)
                ->where('todaysEncounters.0.queue_number', 12)
                ->where('todaysEncounters.0.cancellation.reason_code', EncounterCancellation::REASON_DUPLICATE_ENCOUNTER)
                ->where('todaysEncounters.0.cancellation.note', null)
                ->where('todaysEncounters.0.cancellation.cancelled_by', $registrar->name));

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rawat-inap.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pendaftaran/rawat-inap')
                ->where('canCancel', true)
                ->where('todaysEncounters.0.public_id', $inpatient->public_id)
                ->where('todaysEncounters.0.status', Encounter::STATUS_CANCELLED)
                ->where('todaysEncounters.0.queue_number', 13)
                ->where('todaysEncounters.0.ward_name', 'Bangsal Anggrek')
                ->where('todaysEncounters.0.ward_class', 'Kelas 1')
                ->where('todaysEncounters.0.bed_code', 'ANG-101-A')
                ->where('todaysEncounters.0.cancellation.reason_code', EncounterCancellation::REASON_PLAN_CHANGED_BEFORE_SERVICE)
                ->where('todaysEncounters.0.cancellation.note', 'Rencana rawat inap berubah sebelum pelayanan.')
                ->where('todaysEncounters.0.cancellation.cancelled_by', $registrar->name));
    }

    public function test_pages_project_global_cancel_capability_without_granting_it_to_clinical_roles(): void
    {
        $nurse = $this->userWithRole(RoleCapabilityMatrix::ROLE_NURSE);

        $this->actingAs($nurse)
            ->get(route('pendaftaran.rawat-jalan.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pendaftaran/rawat-jalan')
                ->where('canCancel', false));

        $this->actingAs($nurse)
            ->get(route('pendaftaran.igd.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pendaftaran/rawat-jalan')
                ->where('variant', 'igd')
                ->where('canCancel', false));

        $this->actingAs($nurse)
            ->get(route('pendaftaran.rawat-inap.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pendaftaran/rawat-inap')
                ->where('canCancel', false));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function cancelledEncounter(
        string $careSetting,
        User $actor,
        string $reasonCode,
        ?string $note,
        \DateTimeInterface $cancelledAt,
        array $attributes = [],
    ): Encounter {
        $patient = Patient::factory()->create([
            'full_name' => 'Pasien '.$careSetting.' Sintetis',
            'is_synthetic' => true,
        ]);
        $encounter = InpatientLocationMutationScope::run(fn (): Encounter => Encounter::factory()->for($patient)->create(array_merge([
            'care_setting' => $careSetting,
            'status' => Encounter::STATUS_CANCELLED,
            'registered_at' => now(),
            'queue_date' => now()->toDateString(),
            'registered_by_user_id' => $actor->id,
        ], $attributes)));

        EncounterCancellation::query()->create([
            'encounter_id' => $encounter->id,
            'cancelled_by_user_id' => $actor->id,
            'reason_code' => $reasonCode,
            'note' => $note,
            'idempotency_key' => 'projection-'.$careSetting,
            'payload_digest' => hash('sha256', $careSetting.'|'.$reasonCode.'|'.($note ?? '')),
            'request_correlation_id' => null,
            'cancelled_at' => $cancelledAt,
        ]);

        return $encounter;
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
