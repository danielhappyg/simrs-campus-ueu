<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Claims\Enums\EClaimAction;
use App\Modules\Claims\Enums\EClaimCaseStatus;
use App\Modules\Claims\Models\EClaimCase;
use App\Modules\Claims\Models\EClaimEvent;
use App\Modules\Coding\Enums\TerminologyProvenanceStatus;
use App\Modules\Coding\Enums\TerminologyReleaseStatus;
use App\Modules\Coding\Enums\TerminologySystem;
use App\Modules\Coding\Models\TerminologyConcept;
use App\Modules\Coding\Models\TerminologyRelease;
use App\Modules\Coding\Support\TerminologyNormalizer;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Teaching\Enums\ApplicationRole;
use App\Modules\Teaching\Models\Assignment;
use App\Support\CanonicalJson;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;

class EClaimSimulationWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    public function test_finalized_encounter_exposes_a_never_sent_claim_mapping_and_ordered_checkpoint(): void
    {
        [$encounter, $coder] = $this->completeReferenceJourney();

        $this->actingAs($coder)
            ->get(route('encounters.eclaim-simulation.show', $encounter))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (Assert $page) => $page
                ->component('claims/eclaim-simulation')
                ->where('boundary.mode', 'SIMULATION_ONLY')
                ->where('boundary.transportState', 'NOT_SENT')
                ->where('boundary.outboundEnabled', false)
                ->where('boundary.certifiedGrouper', false)
                ->where('boundary.externalEndpoint', null)
                ->where('boundary.observedInstallationVersion', '5.8.8')
                ->where('claimCase', null)
                ->has('steps', 5)
                ->where('steps.0.method', 'new_claim')
                ->where('steps.0.available', true)
                ->where('steps.1.method', 'set_claim_data')
                ->where('steps.1.available', false)
                ->where('snapshot.coding.diagnoses.0.code', 'R42')
                ->where('snapshot.coding.procedures.0.code', '38.99')
                ->where('snapshot.billing.educationalPlaceholder', true));

        $audit = AuditEvent::query()->where('action', 'claims.eclaim_simulation_viewed')->sole();
        $this->assertSame('NOT_SENT', data_get($audit->metadata, 'transport_state'));
        $this->assertTrue(data_get($audit->metadata, 'can_advance'));
    }

    public function test_coder_can_complete_the_idempotent_local_claim_sequence_without_a_real_send_method(): void
    {
        [$encounter, $coder] = $this->completeReferenceJourney();
        $requestKeys = [];

        foreach (EClaimAction::cases() as $action) {
            $requestKeys[$action->value] = (string) Str::ulid();
            $payload = [
                'action' => $action->value,
                'request_key' => $requestKeys[$action->value],
            ];

            $this->actingAs($coder)
                ->post(route('encounters.eclaim-simulation.advance', $encounter), $payload)
                ->assertRedirect(route('encounters.eclaim-simulation.show', $encounter));

            if ($action === EClaimAction::CreateClaim) {
                $this->actingAs($coder)
                    ->post(route('encounters.eclaim-simulation.advance', $encounter), $payload)
                    ->assertRedirect(route('encounters.eclaim-simulation.show', $encounter));
                $this->assertDatabaseCount('e_claim_events', 1);
            }
        }

        $case = EClaimCase::query()->with('events')->sole();
        $this->assertSame(EClaimCaseStatus::SubmissionSimulated, $case->status);
        $this->assertSame('SIM-RJ-001', $case->grouper_code);
        $this->assertSame(275000, $case->simulated_tariff);
        $this->assertSame(hash('sha256', CanonicalJson::encode($case->source_snapshot)), $case->source_snapshot_hash);
        $this->assertCount(5, $case->events);
        $this->assertSame(
            ['new_claim', 'set_claim_data', 'grouper', 'claim_final', 'SIMULATE_SEND_CLAIM'],
            $case->events->pluck('method')->all(),
        );
        $this->assertSame([1, 2, 3, 4, 5], $case->events->pluck('sequence_number')->all());
        $this->assertTrue($case->events->every(fn (EClaimEvent $event): bool => $event->transport_state === 'NOT_SENT'));
        $this->assertTrue($case->events->every(fn (EClaimEvent $event): bool => data_get($event->response_payload, 'boundary.external_endpoint') === null));
        $this->assertSame(
            'NOT_SENT',
            data_get($case->events->last()?->response_payload, 'response.submission'),
        );
        $this->assertSame(
            5,
            AuditEvent::query()->where('action', 'like', 'claims.eclaim_simulation_%')->count(),
        );

        $serialized = json_encode($case->events->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('http://', $serialized);
        $this->assertStringNotContainsString('https://', $serialized);
        $this->assertStringNotContainsString('access_token', $serialized);
        $this->assertStringNotContainsString('client_secret', $serialized);

        $this->expectException(DomainException::class);
        $case->update(['synthetic_sep' => 'FORBIDDEN']);
    }

    public function test_out_of_order_unsafe_and_unauthorized_actions_fail_closed(): void
    {
        [$encounter, $coder] = $this->completeReferenceJourney();

        $this->actingAs($coder)
            ->post(route('encounters.eclaim-simulation.advance', $encounter), [
                'action' => EClaimAction::GroupClaim->value,
                'request_key' => (string) Str::ulid(),
            ])
            ->assertSessionHasErrors('workflow');
        $this->assertDatabaseCount('e_claim_cases', 0);

        $observer = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->sole();
        $this->actingAs($observer)
            ->get(route('encounters.eclaim-simulation.show', $encounter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('assignment.canAdvance', false)
                ->where('steps.0.available', false));
        $this->actingAs($observer)
            ->post(route('encounters.eclaim-simulation.advance', $encounter), [
                'action' => EClaimAction::CreateClaim->value,
                'request_key' => (string) Str::ulid(),
            ])
            ->assertForbidden();

        config(['eclaim.endpoint' => 'https://forbidden.example.invalid']);
        $this->actingAs($coder)
            ->post(route('encounters.eclaim-simulation.advance', $encounter), [
                'action' => EClaimAction::CreateClaim->value,
                'request_key' => (string) Str::ulid(),
            ])
            ->assertSessionHasErrors('workflow');
        $this->assertDatabaseCount('e_claim_cases', 0);
    }

    public function test_unfinalized_encounter_cannot_open_the_claim_workspace(): void
    {
        $this->seedReferenceOutpatient();
        $encounter = Encounter::query()->sole();
        $coder = User::query()->where('email', 'koder.rmik@example.invalid')->sole();

        $this->actingAs($coder)
            ->get(route('encounters.eclaim-simulation.show', $encounter))
            ->assertStatus(409);
    }

    /** @return array{Encounter, User} */
    private function completeReferenceJourney(): array
    {
        $this->seedReferenceOutpatient();
        $this->activateRelease(TerminologySystem::Icd10, 'R42', 'Dizziness and giddiness');
        $this->activateRelease(TerminologySystem::Icd9Cm, '38.99', 'Other puncture of vein');
        $command = $this->artisan('simulation:complete-reference-journey');

        if (! $command instanceof PendingCommand) {
            $this->fail('Console output mocking must remain enabled for the E-Klaim simulation test.');
        }

        $command->assertSuccessful()->run();

        return [
            Encounter::query()->sole(),
            User::query()->where('email', 'koder.rmik@example.invalid')->sole(),
        ];
    }

    private function activateRelease(TerminologySystem $system, string $code, string $display): void
    {
        $facilitator = User::query()->where('email', 'fasilitator.simulasi@example.invalid')->sole();
        $actor = Assignment::query()
            ->where('user_id', $facilitator->getKey())
            ->where('application_role', ApplicationRole::Facilitator)
            ->sole();
        $release = TerminologyRelease::query()->create([
            'request_key' => (string) Str::ulid(),
            'classification_system' => $system,
            'logical_version' => $system->logicalVersion(),
            'status' => TerminologyReleaseStatus::Imported,
            'source_filename' => 'synthetic-reference-'.$system->value.'.xlsx',
            'source_sha256' => hash('sha256', "{$system->value}|{$code}|{$display}"),
            'sheet_name' => $system->expectedSheetName(),
            'source_provenance_status' => TerminologyProvenanceStatus::SyntheticFixture,
            'imported_by_user_id' => $actor->user_id,
            'imported_by_assignment_id' => $actor->getKey(),
            'row_count' => 1,
            'ignored_blank_rows' => 0,
            'validation_report' => [
                'schema' => 'terminology-import-validation.v1',
                'classificationSystem' => $system->value,
                'sourceHashVerified' => true,
                'syntheticFixture' => true,
            ],
            'imported_at' => now(),
        ]);
        $normalizer = app(TerminologyNormalizer::class);
        $normalizedDisplay = $normalizer->text($display);
        TerminologyConcept::query()->create([
            'terminology_release_id' => $release->getKey(),
            'code' => $code,
            'display' => $display,
            'normalized_code' => $normalizer->code($code),
            'normalized_display' => $normalizedDisplay,
            'search_tokens' => implode(' ', $normalizer->tokens($normalizedDisplay)),
            'active' => true,
        ]);
        $release->persistActivation($actor, null);
    }
}
