<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Clinical\Enums\AllergyAssessmentState;
use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\Clinical\Enums\CurrentMedicationState;
use App\Modules\Clinical\Enums\IntakeSafetyDecision;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Enums\VisitTerminationOutcome;
use App\Modules\Teaching\Enums\ApplicationRole;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Enums\Program;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;

class EncounterRecordTimelineTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    public function test_exact_case_participant_can_open_the_record_timeline_before_finalization(): void
    {
        $this->seedReferenceOutpatient();
        $encounter = Encounter::query()->firstOrFail();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();

        $this->actingAs($nurse)
            ->get(route('encounters.timeline.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('encounter/timeline')
                ->where('encounter.status.code', EncounterStatus::Planned->value)
                ->where('assignment.program', Program::Nursing->label())
                ->where('session.status.code', SessionStatus::Active->value)
                ->where('release.readOnly', true),
            );
    }

    public function test_exact_case_participant_can_read_the_record_after_the_session_is_completed(): void
    {
        $this->seedReferenceOutpatient();
        $encounter = Encounter::query()->firstOrFail();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $encounter->session->update(['status' => SessionStatus::Completed]);

        $this->actingAs($nurse)
            ->get(route('encounters.timeline.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('encounter/timeline')
                ->where('session.status.code', SessionStatus::Completed->value)
                ->where('release.sessionCompleted', true),
            );
    }

    public function test_session_wide_facilitator_can_open_the_record_timeline(): void
    {
        $this->seedReferenceOutpatient();
        $encounter = Encounter::query()->firstOrFail();
        $facilitator = User::query()->where('email', 'fasilitator.simulasi@example.invalid')->firstOrFail();

        $this->actingAs($facilitator)
            ->get(route('encounters.timeline.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('encounter/timeline')
                ->where('assignment.program', Program::Facilitation->label()),
            );
    }

    public function test_wrong_context_missing_capability_and_configuration_only_assignments_are_denied(): void
    {
        $this->seedReferenceOutpatient();
        $encounter = Encounter::query()->firstOrFail();

        $otherSessionUser = User::factory()->create();
        $otherSession = SimulationSession::query()->create([
            'scenario_id' => $encounter->session->scenario_id,
            'code' => 'SIM-RJ-TIMELINE-OTHER',
            'course_code' => 'SIMRS-RJ',
            'cohort_code' => 'OTHER-2026',
            'environment_mode' => EnvironmentMode::Simulation,
            'status' => SessionStatus::Active,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'facilitator_user_id' => $otherSessionUser->getKey(),
        ]);
        $this->createAssignment(
            user: $otherSessionUser,
            session: $otherSession,
            capabilities: [Capability::SessionView->value],
        );

        $wrongCaseUser = User::factory()->create();
        $this->createAssignment(
            user: $wrongCaseUser,
            session: $encounter->session,
            capabilities: [Capability::SessionView->value],
            patientId: $encounter->patient_id,
        );

        $missingCapabilityUser = User::factory()->create();
        $this->createAssignment(
            user: $missingCapabilityUser,
            session: $encounter->session,
            capabilities: [Capability::PatientSearch->value],
            patientId: $encounter->patient_id,
            encounterId: $encounter->getKey(),
        );

        $administrator = User::factory()->create();
        $this->createAssignment(
            user: $administrator,
            session: $encounter->session,
            capabilities: [Capability::SystemConfigure->value],
            patientId: $encounter->patient_id,
            encounterId: $encounter->getKey(),
            program: Program::System,
            role: ApplicationRole::SystemAdministrator,
        );

        foreach ([$otherSessionUser, $wrongCaseUser, $missingCapabilityUser, $administrator] as $user) {
            $this->actingAs($user)
                ->get(route('encounters.timeline.show', $encounter))
                ->assertForbidden();
        }
    }

    public function test_record_timeline_projects_deterministic_source_provenance_without_raw_audit_internals(): void
    {
        $this->seedReferenceOutpatient();
        $encounter = Encounter::query()->firstOrFail();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $nursingAssignment = Assignment::query()->where('user_id', $nurse->getKey())->sole();

        $this->actingAs($registrar)
            ->post(route('appointments.check-in', $encounter->appointment))
            ->assertRedirect();
        $this->actingAs($nurse)
            ->post(route('encounters.nursing-intake.versions.store', $encounter), $this->nursingPayload())
            ->assertRedirect();

        AuditEvent::query()->create([
            'recorded_at' => now()->subMinute(),
            'actor_user_id' => $nurse->getKey(),
            'assignment_id' => $nursingAssignment->getKey(),
            'session_id' => $encounter->session_id,
            'patient_id' => $encounter->patient_id,
            'encounter_id' => $encounter->getKey(),
            'action' => 'record_quality.correction_requested',
            'resource_type' => 'record_correction_request',
            'resource_id' => (string) Str::ulid(),
            'outcome' => 'SUCCESS',
            'reason' => 'timeline-raw-secret-reason',
            'request_correlation_id' => (string) Str::ulid(),
            'ip_hash' => hash('sha256', 'timeline-raw-secret-ip'),
            'user_agent' => 'timeline-raw-secret-user-agent',
            'metadata' => ['raw_secret_token' => 'timeline-raw-secret-token'],
        ]);

        $response = $this->actingAs($nurse)
            ->get(route('encounters.timeline.show', $encounter));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('encounter/timeline')
            ->where('summary.truncated', false)
            ->where('events', fn (mixed $events): bool => $this->projectedEventsAreSafe($events, $nurse->name)),
        );
        $response
            ->assertDontSee('timeline-raw-secret-reason')
            ->assertDontSee('timeline-raw-secret-user-agent')
            ->assertDontSee('timeline-raw-secret-token');

        $viewAudit = AuditEvent::query()
            ->where('action', 'record.timeline_viewed')
            ->where('actor_user_id', $nurse->getKey())
            ->sole();
        $this->assertEqualsCanonicalizing([
            'displayed_event_count',
            'total_available_event_count',
            'truncated',
        ], array_keys($viewAudit->metadata ?? []));
    }

    public function test_record_timeline_shows_visit_termination_outcome_without_the_free_text_reason(): void
    {
        $this->seedReferenceOutpatient();
        $encounter = Encounter::query()->firstOrFail();
        $registrar = User::query()->where('email', 'mahasiswa.rmik@example.invalid')->firstOrFail();
        $nurse = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->firstOrFail();
        $reason = 'Pembatalan sintetis unik yang tidak boleh muncul pada proyeksi linimasa.';

        $this->actingAs($registrar)
            ->post(route('appointments.termination.store', $encounter->appointment), [
                'outcome' => VisitTerminationOutcome::Cancelled->value,
                'reason' => $reason,
            ])
            ->assertRedirect();

        $response = $this->actingAs($nurse)
            ->get(route('encounters.timeline.show', $encounter));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('events', fn (mixed $events): bool => $this->containsSafeCancellation($events)),
        );
        $response->assertDontSee($reason);
    }

    public function test_record_timeline_caps_the_allowlisted_projection_and_reports_truncation(): void
    {
        $this->seedReferenceOutpatient();
        $encounter = Encounter::query()->firstOrFail();
        $facilitator = User::query()->where('email', 'fasilitator.simulasi@example.invalid')->firstOrFail();
        $assignment = Assignment::query()->where('user_id', $facilitator->getKey())->sole();

        foreach (range(1, 301) as $offset) {
            AuditEvent::query()->create([
                'recorded_at' => now()->subSeconds(302 - $offset),
                'actor_user_id' => $facilitator->getKey(),
                'assignment_id' => $assignment->getKey(),
                'session_id' => $encounter->session_id,
                'patient_id' => $encounter->patient_id,
                'encounter_id' => $encounter->getKey(),
                'action' => 'encounter.transitioned',
                'resource_type' => 'encounter',
                'resource_id' => $encounter->public_id,
                'outcome' => 'SUCCESS',
                'metadata' => [
                    'from_status' => EncounterStatus::Planned->value,
                    'to_status' => EncounterStatus::Arrived->value,
                ],
            ]);
        }

        $this->actingAs($facilitator)
            ->get(route('encounters.timeline.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events', 300)
                ->where('summary.displayedEventCount', 300)
                ->where('summary.totalAvailableEventCount', 301)
                ->where('summary.truncated', true),
            );
    }

    /** @return array<string, mixed> */
    private function nursingPayload(): array
    {
        return [
            'request_key' => (string) Str::ulid(),
            'intent' => ClinicalSaveIntent::Submit->value,
            'clinical_occurrence_at' => now()->subHours(2)->toIso8601String(),
            'history_source' => 'Pasien sintetis',
            'chief_complaint' => 'Pusing pada skenario simulasi.',
            'onset_duration' => 'Dua hari',
            'consciousness' => 'Sadar penuh.',
            'allergy_state' => AllergyAssessmentState::NoKnownAllergyReported->value,
            'current_medication_state' => CurrentMedicationState::NoneReported->value,
            'vitals' => [
                'temperature' => 36.8,
                'heart_rate' => 82,
                'respiratory_rate' => 18,
                'systolic_blood_pressure' => 118,
                'diastolic_blood_pressure' => 76,
                'oxygen_saturation' => 98,
            ],
            'safety_responses' => [[
                'question_code' => 'SUPERVISOR_CONCERN',
                'response' => 'NO',
                'note' => null,
            ]],
            'safety_decision' => IntakeSafetyDecision::RoutineFlow->value,
            'note' => 'Catatan simulasi.',
            'handoff_summary' => 'Diteruskan untuk asesmen medis.',
        ];
    }

    private function projectedEventsAreSafe(mixed $events, string $nurseName): bool
    {
        if ($events instanceof Collection) {
            $events = $events->all();
        }

        if (! is_array($events) || $events === []) {
            return false;
        }

        $primaryTimes = [];
        $nursingSource = null;
        $correction = null;

        foreach ($events as $event) {
            if (! is_array($event) || ! is_string($event['primaryAt'] ?? null)) {
                return false;
            }

            $primaryTimes[] = $event['primaryAt'];

            if (($event['title'] ?? null) === 'Versi asesmen awal dibuat') {
                $nursingSource = $event;
            }

            if (($event['title'] ?? null) === 'Koreksi dokumentasi diminta') {
                $correction = $event;
            }
        }

        $sortedTimes = $primaryTimes;
        sort($sortedTimes);

        return $primaryTimes === $sortedTimes
            && is_array($nursingSource)
            && data_get($nursingSource, 'source.version') === 'v1'
            && data_get($nursingSource, 'actor.name') === $nurseName
            && ($nursingSource['showsRecordedTimeDifference'] ?? null) === true
            && is_array($correction)
            && ! array_key_exists('metadata', $correction)
            && ! array_key_exists('reason', $correction)
            && ! array_key_exists('requestCorrelationId', $correction)
            && ! array_key_exists('ipHash', $correction)
            && ! array_key_exists('userAgent', $correction);
    }

    private function containsSafeCancellation(mixed $events): bool
    {
        if ($events instanceof Collection) {
            $events = $events->all();
        }

        if (! is_array($events)) {
            return false;
        }

        foreach ($events as $event) {
            if (is_array($event)
                && ($event['title'] ?? null) === 'Kunjungan dibatalkan'
                && ($event['detail'] ?? null) === 'Outcome kunjungan: Dibatalkan.'
                && data_get($event, 'source.label') === 'Registrasi/appointment'
                && ! array_key_exists('reason', $event)
                && ! array_key_exists('metadata', $event)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $capabilities */
    private function createAssignment(
        User $user,
        SimulationSession $session,
        array $capabilities,
        ?int $patientId = null,
        ?int $encounterId = null,
        Program $program = Program::Nursing,
        ApplicationRole $role = ApplicationRole::Learner,
    ): Assignment {
        return Assignment::query()->create([
            'session_id' => $session->getKey(),
            'user_id' => $user->getKey(),
            'program' => $program,
            'application_role' => $role,
            'capabilities' => $capabilities,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'active_from' => now()->subHour(),
            'active_until' => now()->addHour(),
        ]);
    }
}
