<?php

namespace Tests\Feature;

use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Encounter\Models\EncounterTransition;
use App\Modules\Encounter\Services\EncounterTransitionService;
use App\Modules\Teaching\Enums\ApplicationRole;
use App\Modules\Teaching\Models\Assignment;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;

class EncounterStateMachineTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    public function test_state_service_allows_only_declared_transition_and_records_actor(): void
    {
        $this->seedReferenceOutpatient();
        $encounter = Encounter::query()->firstOrFail();
        $registrarAssignment = Assignment::query()
            ->where('application_role', ApplicationRole::Registrar)
            ->firstOrFail();

        $transitioned = app(EncounterTransitionService::class)->transition(
            $encounter,
            EncounterStatus::Arrived,
            $registrarAssignment,
            'test_arrival',
        );

        $this->assertSame(EncounterStatus::Arrived, $transitioned->status);
        $this->assertDatabaseHas('encounter_transitions', [
            'encounter_id' => $encounter->getKey(),
            'actor_assignment_id' => $registrarAssignment->getKey(),
            'from_status' => EncounterStatus::Planned->value,
            'to_status' => EncounterStatus::Arrived->value,
            'reason' => 'test_arrival',
        ]);
    }

    public function test_invalid_transition_fails_without_mutating_encounter(): void
    {
        $this->seedReferenceOutpatient();
        $encounter = Encounter::query()->firstOrFail();
        $registrarAssignment = Assignment::query()
            ->where('application_role', ApplicationRole::Registrar)
            ->firstOrFail();

        try {
            app(EncounterTransitionService::class)->transition(
                $encounter,
                EncounterStatus::Finalized,
                $registrarAssignment,
            );
            $this->fail('An invalid state jump should throw.');
        } catch (DomainException) {
            $this->assertSame(EncounterStatus::Planned, $encounter->refresh()->status);
            $this->assertSame(1, EncounterTransition::query()->count());
        }
    }

    public function test_transition_history_cannot_be_updated_or_deleted_through_model(): void
    {
        $this->seedReferenceOutpatient();
        $transition = EncounterTransition::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $transition->update(['reason' => 'attempted overwrite']);
    }

    public function test_encounter_status_cannot_be_changed_without_transition_service(): void
    {
        $this->seedReferenceOutpatient();
        $encounter = Encounter::query()->firstOrFail();

        try {
            $encounter->update(['status' => EncounterStatus::Finalized]);
            $this->fail('Direct encounter status mutation should throw.');
        } catch (DomainException) {
            $this->assertSame(EncounterStatus::Planned, $encounter->refresh()->status);
            $this->assertSame(1, EncounterTransition::query()->count());
        }
    }

    public function test_revoked_assignment_cannot_transition_encounter_through_service(): void
    {
        $this->seedReferenceOutpatient();
        $encounter = Encounter::query()->firstOrFail();
        $registrarAssignment = Assignment::query()
            ->where('application_role', ApplicationRole::Registrar)
            ->firstOrFail();
        $registrarAssignment->update([
            'revoked_at' => now(),
            'revocation_reason' => 'test revocation',
        ]);

        try {
            app(EncounterTransitionService::class)->transition(
                $encounter,
                EncounterStatus::Arrived,
                $registrarAssignment,
            );
            $this->fail('A revoked assignment should not transition an encounter.');
        } catch (DomainException) {
            $this->assertSame(EncounterStatus::Planned, $encounter->refresh()->status);
            $this->assertSame(1, EncounterTransition::query()->count());
        }
    }
}
