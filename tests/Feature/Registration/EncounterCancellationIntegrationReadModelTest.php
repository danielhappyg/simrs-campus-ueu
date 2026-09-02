<?php

namespace Tests\Feature\Registration;

use App\Models\ClinicalEntry;
use App\Models\Encounter;
use App\Models\InpatientClinicalDocument;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Authorization\RoleCapabilityMatrix;
use Database\Seeders\OutpatientMastersSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EncounterCancellationIntegrationReadModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(OutpatientMastersSeeder::class);
    }

    public function test_cancelled_encounters_are_absent_from_active_worklists_rm_and_dashboard_counts(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $clinical = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $rmik = $this->userWithRole(RoleCapabilityMatrix::ROLE_RMIK);

        $cancelled = [];
        foreach ([
            Encounter::CARE_SETTING_OUTPATIENT,
            Encounter::CARE_SETTING_EMERGENCY,
            Encounter::CARE_SETTING_INPATIENT,
        ] as $careSetting) {
            $patient = Patient::factory()->create([
                'created_by_user_id' => $registrar->id,
            ]);
            $cancelled[$careSetting] = Encounter::factory()->create([
                'patient_id' => $patient->id,
                'registered_by_user_id' => $registrar->id,
                'care_setting' => $careSetting,
                'status' => Encounter::STATUS_CANCELLED,
                'registered_at' => now(),
            ]);
        }

        $activePatient = Patient::factory()->create([
            'created_by_user_id' => $registrar->id,
        ]);
        Encounter::factory()->create([
            'patient_id' => $activePatient->id,
            'registered_by_user_id' => $registrar->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'status' => Encounter::STATUS_REGISTERED,
            'registered_at' => now(),
        ]);

        foreach ([
            'pemeriksaan.rawat-jalan.index',
            'pemeriksaan.igd.index',
            'pemeriksaan.rawat-inap.index',
            'pemeriksaan.triage.index',
        ] as $routeName) {
            $expectedCount = $routeName === 'pemeriksaan.rawat-jalan.index' ? 1 : 0;
            $this->actingAs($clinical)
                ->get(route($routeName))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->has('encounters', $expectedCount));
        }

        $this->actingAs($rmik)
            ->get(route('rm.rawat-jalan.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('encounters', 0));

        $this->actingAs($clinical)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('encounters.available', true)
                ->where('encounters.totals.rawat_jalan', 1)
                ->where('encounters.totals.igd', 0)
                ->where('encounters.totals.rawat_inap', 0)
                ->where('encounters.read_error', null));

        $this->actingAs($clinical)
            ->get(route('pemeriksaan.igd.show', $cancelled[Encounter::CARE_SETTING_EMERGENCY]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pemeriksaan/igd/show')
                ->where('triage.permission.can_write', false)
                ->where('triage.actions.finalize_initial_url', null)
                ->where('triage.actions.reassess_url', null)
                ->where('documentation.permissions.nursing.can_save_draft', false)
                ->where('documentation.permissions.nursing.can_finalize', false)
                ->where('documentation.actions.nursing.save_draft_url', null)
                ->where('documentation.actions.nursing.finalize_url', null)
                ->where('documentation.actions.medical.save_draft_url', null)
                ->where('documentation.actions.medical.finalize_url', null)
                ->where('disposition.actions.sign_url', null)
                ->where('disposition.actions.correct_url', null)
                ->where('disposition.actions.create_correction_intent_url', null)
                ->where('laboratory.commands.create_order_url', null)
                ->where('radiology.commands.create_order_url', null));

        $this->actingAs($clinical)
            ->get(route('pemeriksaan.rawat-inap.show', $cancelled[Encounter::CARE_SETTING_INPATIENT]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.nursing.can_save_draft', false)
                ->where('permissions.nursing.can_finalize', false)
                ->where('permissions.medical.can_save_draft', false)
                ->where('permissions.medical.can_finalize', false));
    }

    public function test_cancelled_igd_and_inpatient_encounters_reject_direct_clinical_entry_posts(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $clinical = $this->userWithRole(RoleCapabilityMatrix::ROLE_PHYSICIAN);

        foreach ([Encounter::CARE_SETTING_EMERGENCY, Encounter::CARE_SETTING_INPATIENT] as $careSetting) {
            $patient = Patient::factory()->create([
                'created_by_user_id' => $registrar->id,
            ]);
            $encounter = Encounter::factory()->create([
                'patient_id' => $patient->id,
                'registered_by_user_id' => $registrar->id,
                'care_setting' => $careSetting,
                'status' => Encounter::STATUS_CANCELLED,
            ]);

            if ($careSetting === Encounter::CARE_SETTING_EMERGENCY) {
                $this->actingAs($clinical)
                    ->post(route('pemeriksaan.igd.entries.store', $encounter), [
                        'entry_type' => ClinicalEntry::TYPE_MEDICAL_ASSESSMENT,
                        'body' => 'Tidak boleh tersimpan.',
                    ])->assertGone();
            } else {
                $this->actingAs($clinical)
                    ->post(route('pemeriksaan.rawat-inap.documents.draft', [$encounter, InpatientClinicalDocument::TYPE_MEDICAL_DAILY]), [
                        'definition_version' => InpatientClinicalDocument::DEFINITION_VERSION,
                        'expected_version' => 0,
                        'idempotency_key' => 'cancelled-ri-denial-0001',
                        'fields' => [],
                    ])->assertStatus(422);
            }
        }

        $this->assertDatabaseCount('clinical_entries', 0);
        $this->assertSame(0, AuditEvent::query()
            ->where('action', 'clinical.note.write')
            ->count());
        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'clinical.inpatient.medical.draft.save')
            ->where('outcome', 'DENIED')
            ->where('reason', 'encounter_cancelled')
            ->count());
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
