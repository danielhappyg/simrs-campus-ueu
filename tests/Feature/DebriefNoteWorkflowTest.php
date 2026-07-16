<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Encounter\Services\EncounterTransitionService;
use App\Modules\Teaching\Enums\ApplicationRole;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\DebriefNoteType;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Enums\Program;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\DebriefNote;
use App\Modules\Teaching\Models\DebriefNoteVersion;
use App\Modules\Teaching\Models\SimulationSession;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;

class DebriefNoteWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    public function test_facilitator_creates_an_idempotent_shared_note_that_participants_can_read_without_write_access(): void
    {
        $case = $this->finalizedCase();
        $requestKey = (string) Str::ulid();
        $body = 'Pertahankan dua identifier sintetis saat handoff dan jelaskan alasan koreksi sebelum penutupan.';
        $payload = [
            'request_key' => $requestKey,
            'note_type' => DebriefNoteType::FacilitatorSynthesis->value,
            'body' => $body,
            'simulation_attestation' => true,
        ];

        $this->actingAs($case['facilitator'])
            ->post(route('encounters.debrief.notes.store', $case['encounter']), $payload)
            ->assertRedirect(route('encounters.debrief.show', $case['encounter']));
        $this->actingAs($case['facilitator'])
            ->post(route('encounters.debrief.notes.store', $case['encounter']), $payload)
            ->assertRedirect(route('encounters.debrief.show', $case['encounter']));

        $this->assertDatabaseCount('debrief_notes', 1);
        $this->assertDatabaseCount('debrief_note_versions', 1);
        $note = DebriefNote::query()->with('versions')->sole();
        $version = $note->versions->sole();
        $this->assertSame(hash('sha256', $body), $version->content_hash);
        $this->assertNull($version->change_reason);

        $event = AuditEvent::query()->where('action', 'debrief.note_created')->sole();
        $this->assertSame($version->public_id, $event->resource_id);
        $this->assertStringNotContainsString($body, json_encode($event->metadata, JSON_THROW_ON_ERROR));

        $this->actingAs($case['nurse'])
            ->get(route('encounters.debrief.show', $case['encounter']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('encounter/debrief')
                ->where('teachingEvidence.authoring.canAuthorNotes', false)
                ->has('teachingEvidence.notes', 1)
                ->where('teachingEvidence.notes.0.type.code', DebriefNoteType::FacilitatorSynthesis->value)
                ->where('teachingEvidence.notes.0.versions.0.body', $body)
                ->where('teachingEvidence.notes.0.versions.0.author.name', $case['facilitator']->name)
                ->has('teachingEvidence.rubricReferences', 1)
                ->where('teachingEvidence.rubricReferences.0.status.code', 'PENDING_PROGRAM_REVIEW')
                ->where('teachingEvidence.rubricReferences.0.nonScoring', true),
            );
    }

    public function test_revision_appends_an_immutable_successor_with_reason_and_idempotency(): void
    {
        $case = $this->finalizedCase();
        $createKey = (string) Str::ulid();
        $firstBody = 'Diskusikan handoff registrasi dan asesmen awal.';
        $this->createNote($case, $createKey, $firstBody);
        $note = DebriefNote::query()->sole();
        $revisionKey = (string) Str::ulid();
        $secondBody = 'Diskusikan handoff registrasi, asesmen awal, dan dampak koreksi pada koding manusia.';
        $revision = [
            'request_key' => $revisionKey,
            'body' => $secondBody,
            'change_reason' => 'Menambahkan hubungan koreksi dengan keputusan koding.',
            'simulation_attestation' => true,
        ];

        $this->actingAs($case['facilitator'])
            ->post(route('debrief-notes.versions.store', $note), $revision)
            ->assertRedirect(route('encounters.debrief.show', $case['encounter']));
        $this->actingAs($case['facilitator'])
            ->post(route('debrief-notes.versions.store', $note), $revision)
            ->assertRedirect(route('encounters.debrief.show', $case['encounter']));

        $this->createNote($case, $createKey, $firstBody);

        $versions = DebriefNoteVersion::query()->orderBy('version_number')->get();
        $this->assertCount(2, $versions);
        $this->assertSame($firstBody, $versions[0]->body);
        $this->assertSame($secondBody, $versions[1]->body);
        $this->assertSame(2, $versions[1]->version_number);
        $this->assertSame($revision['change_reason'], $versions[1]->change_reason);

        $event = AuditEvent::query()->where('action', 'debrief.note_revised')->sole();
        $this->assertStringNotContainsString($secondBody, json_encode($event->metadata, JSON_THROW_ON_ERROR));
        $this->assertTrue((bool) data_get($event->metadata, 'change_reason_recorded'));

        try {
            $versions[0]->update(['body' => 'mutasi terlarang']);
            $this->fail('An existing debrief note version was updated.');
        } catch (DomainException $exception) {
            $this->assertSame('Debrief note versions are immutable.', $exception->getMessage());
        }

        try {
            $versions[0]->delete();
            $this->fail('An existing debrief note version was deleted.');
        } catch (DomainException $exception) {
            $this->assertSame('Debrief note versions cannot be deleted.', $exception->getMessage());
        }
    }

    public function test_learner_other_session_and_wrong_case_assignments_cannot_author_notes(): void
    {
        $case = $this->finalizedCase();
        $payload = $this->notePayload();

        $this->actingAs($case['nurse'])
            ->post(route('encounters.debrief.notes.store', $case['encounter']), $payload)
            ->assertForbidden();

        $otherUser = User::factory()->create();
        $otherSession = SimulationSession::query()->create([
            'scenario_id' => $case['encounter']->session->scenario_id,
            'code' => 'SIM-RJ-DEBRIEF-OTHER',
            'course_code' => 'SIMRS-RJ',
            'cohort_code' => 'OTHER-2026',
            'environment_mode' => EnvironmentMode::Simulation,
            'status' => SessionStatus::Active,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'facilitator_user_id' => $otherUser->getKey(),
        ]);
        Assignment::query()->create([
            'session_id' => $otherSession->getKey(),
            'user_id' => $otherUser->getKey(),
            'program' => Program::Facilitation,
            'application_role' => ApplicationRole::Facilitator,
            'capabilities' => [Capability::DebriefWrite->value],
            'active_from' => now()->subHour(),
            'active_until' => now()->addHour(),
        ]);

        $this->actingAs($otherUser)
            ->post(route('encounters.debrief.notes.store', $case['encounter']), $this->notePayload())
            ->assertForbidden();

        $wrongCaseUser = User::factory()->create();
        Assignment::query()->create([
            'session_id' => $case['encounter']->session_id,
            'user_id' => $wrongCaseUser->getKey(),
            'program' => Program::Facilitation,
            'application_role' => ApplicationRole::Facilitator,
            'capabilities' => [Capability::DebriefWrite->value],
            'patient_id' => $case['encounter']->patient_id,
            'encounter_id' => null,
            'active_from' => now()->subHour(),
            'active_until' => now()->addHour(),
        ]);

        $this->actingAs($wrongCaseUser)
            ->post(route('encounters.debrief.notes.store', $case['encounter']), $this->notePayload())
            ->assertForbidden();
        $this->assertDatabaseCount('debrief_notes', 0);
    }

    public function test_finalization_and_active_session_gates_are_server_enforced(): void
    {
        $this->seedReferenceOutpatient();
        $encounter = Encounter::query()->firstOrFail();
        $facilitator = User::query()->where('email', 'fasilitator.simulasi@example.invalid')->firstOrFail();

        $this->actingAs($facilitator)
            ->post(route('encounters.debrief.notes.store', $encounter), $this->notePayload())
            ->assertStatus(409);

        $assignment = Assignment::query()->where('user_id', $facilitator->getKey())->sole();
        $this->finalize($encounter, $assignment);
        $encounter->session->update(['status' => SessionStatus::Completed]);

        $this->actingAs($facilitator)
            ->post(route('encounters.debrief.notes.store', $encounter), $this->notePayload())
            ->assertStatus(409);
        $this->actingAs($facilitator)
            ->get(route('encounters.debrief.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('teachingEvidence.authoring.canAuthorNotes', false),
            );
    }

    public function test_attestation_change_reason_body_limits_and_request_key_reuse_are_rejected(): void
    {
        $case = $this->finalizedCase();
        $payload = $this->notePayload();

        $this->actingAs($case['facilitator'])
            ->post(route('encounters.debrief.notes.store', $case['encounter']), [
                ...$payload,
                'simulation_attestation' => false,
            ])->assertSessionHasErrors('simulation_attestation');
        $this->actingAs($case['facilitator'])
            ->post(route('encounters.debrief.notes.store', $case['encounter']), [
                ...$payload,
                'body' => str_repeat('x', 4001),
            ])->assertSessionHasErrors('body');

        $this->actingAs($case['facilitator'])
            ->post(route('encounters.debrief.notes.store', $case['encounter']), $payload)
            ->assertRedirect();
        $this->actingAs($case['facilitator'])
            ->post(route('encounters.debrief.notes.store', $case['encounter']), [
                ...$payload,
                'body' => 'Isi berbeda dengan request key yang sama.',
            ])->assertStatus(409);

        $note = DebriefNote::query()->sole();
        $this->actingAs($case['facilitator'])
            ->post(route('debrief-notes.versions.store', $note), [
                'request_key' => (string) Str::ulid(),
                'body' => 'Versi penerus yang valid panjangnya.',
                'change_reason' => '',
                'simulation_attestation' => true,
            ])->assertSessionHasErrors('change_reason');
        $this->assertDatabaseCount('debrief_note_versions', 1);
    }

    /** @return array{encounter: Encounter, facilitator: User, nurse: User} */
    private function finalizedCase(): array
    {
        $this->seedReferenceOutpatient();
        $encounter = Encounter::query()->firstOrFail();
        $facilitator = User::query()->where('email', 'fasilitator.simulasi@example.invalid')->firstOrFail();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $assignment = Assignment::query()->where('user_id', $facilitator->getKey())->sole();

        return [
            'encounter' => $this->finalize($encounter, $assignment),
            'facilitator' => $facilitator,
            'nurse' => $nurse,
        ];
    }

    /** @param array{encounter: Encounter, facilitator: User, nurse: User} $case */
    private function createNote(array $case, string $requestKey, string $body): void
    {
        $this->actingAs($case['facilitator'])
            ->post(route('encounters.debrief.notes.store', $case['encounter']), [
                'request_key' => $requestKey,
                'note_type' => DebriefNoteType::FacilitatorSynthesis->value,
                'body' => $body,
                'simulation_attestation' => true,
            ])->assertRedirect();
    }

    /** @return array<string, mixed> */
    private function notePayload(): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'note_type' => DebriefNoteType::FacilitatorSynthesis->value,
            'body' => 'Sintesis fasilitator untuk debrief simulasi bersama.',
            'simulation_attestation' => true,
        ];
    }

    private function finalize(Encounter $encounter, Assignment $facilitator): Encounter
    {
        $service = app(EncounterTransitionService::class);
        $path = [
            EncounterStatus::Arrived,
            EncounterStatus::InIntake,
            EncounterStatus::WaitingClinician,
            EncounterStatus::InConsultation,
            EncounterStatus::ClosurePending,
            EncounterStatus::ClinicallyClosed,
            EncounterStatus::RecordReview,
            EncounterStatus::Finalized,
        ];

        foreach ($path as $status) {
            $encounter = $service->transition($encounter, $status, $facilitator, 'debrief_note_test_progression');
        }

        return $encounter;
    }
}
