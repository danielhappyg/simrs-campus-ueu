<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Coding\Enums\TerminologyProvenanceStatus;
use App\Modules\Coding\Enums\TerminologyReleaseStatus;
use App\Modules\Coding\Enums\TerminologySystem;
use App\Modules\Coding\Models\TerminologyConcept;
use App\Modules\Coding\Models\TerminologyRelease;
use App\Modules\Coding\Support\TerminologyNormalizer;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Interoperability\Services\OutpatientFhirPreview;
use App\Modules\Teaching\Enums\ApplicationRole;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Models\Assignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SeedsReferenceOutpatient;
use Tests\TestCase;

class OutpatientInteroperabilityPreviewTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceOutpatient;

    public function test_finalized_sources_produce_a_deterministic_local_collection_with_no_transmission_claim(): void
    {
        $encounter = $this->completeReferenceJourney();

        $first = app(OutpatientFhirPreview::class)->build($encounter);
        $second = app(OutpatientFhirPreview::class)->build($encounter->fresh());

        $this->assertSame($first, $second);
        $this->assertSame('SIMULASI — DATA SINTETIS', data_get($first, 'boundary.classification'));
        $this->assertSame('SIMULATION', data_get($first, 'boundary.environment'));
        $this->assertSame('LOCAL_MAPPING_PREVIEW', data_get($first, 'boundary.mode'));
        $this->assertSame('NOT_SENT', data_get($first, 'boundary.transportState'));
        $this->assertSame('4.0.1', data_get($first, 'boundary.fhirVersion'));
        $this->assertSame('NOT_CLAIMED', data_get($first, 'boundary.satusehatProfileStatus'));
        $this->assertFalse(data_get($first, 'boundary.readyForTransmission'));
        $this->assertNull(data_get($first, 'boundary.externalEndpoint'));
        $this->assertSame('Bundle', data_get($first, 'bundle.resourceType'));
        $this->assertSame('collection', data_get($first, 'bundle.type'));
        $this->assertSame(
            $encounter->finalized_at?->utc()->toIso8601String(),
            data_get($first, 'bundle.timestamp'),
        );
        $this->assertSame('Composition', data_get($first, 'bundle.entry.0.resource.resourceType'));
        $this->assertSame([], data_get($first, 'validation.structuralErrors'));

        $entries = data_get($first, 'bundle.entry', []);
        $fullUrls = collect($entries)->pluck('fullUrl')->all();
        $this->assertNotEmpty($fullUrls);
        $this->assertCount(count($fullUrls), array_unique($fullUrls));

        foreach ($entries as $entry) {
            $this->assertStringStartsWith(
                'https://simrs-campus-ueu.example.invalid/fhir/',
                $entry['fullUrl'],
            );
            $this->assertMatchesRegularExpression(
                '/^[A-Za-z0-9\-.]{1,64}$/',
                (string) data_get($entry, 'resource.id'),
            );
        }

        $serialized = json_encode($first, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('"profile"', $serialized);
        $this->assertStringNotContainsString('"request"', $serialized);
        $this->assertStringNotContainsString('api-satusehat', $serialized);
        $this->assertStringNotContainsString('client_secret', $serialized);
        $this->assertStringNotContainsString('access_token', $serialized);
    }

    public function test_finalized_reference_journey_maps_each_supported_clinical_source_and_human_approved_code(): void
    {
        $preview = app(OutpatientFhirPreview::class)->build($this->completeReferenceJourney());
        $entries = collect(data_get($preview, 'bundle.entry', []));
        $resources = $entries->pluck('resource');

        $this->assertSame([
            'Composition' => 1,
            'Condition' => 1,
            'DiagnosticReport' => 1,
            'Encounter' => 1,
            'MedicationDispense' => 1,
            'MedicationRequest' => 1,
            'Observation' => 7,
            'Organization' => 1,
            'Patient' => 1,
            'Procedure' => 1,
            'QuestionnaireResponse' => 1,
            'ServiceRequest' => 1,
        ], data_get($preview, 'summary.resourceTypeCounts'));
        $this->assertCount(18, $entries);
        $this->assertCount(18, data_get($preview, 'sourceIndex', []));
        $this->assertSame([], data_get($preview, 'validation.structuralErrors'));

        $condition = $resources->firstWhere('resourceType', 'Condition');
        $this->assertSame('R42', data_get($condition, 'code.coding.0.code'));
        $this->assertSame('http://hl7.org/fhir/sid/icd-10', data_get($condition, 'code.coding.0.system'));
        $this->assertTrue(data_get($condition, 'code.coding.0.extension.0.valueBoolean'));

        $procedure = $resources->firstWhere('resourceType', 'Procedure');
        $this->assertSame('38.99', data_get($procedure, 'code.coding.0.code'));
        $this->assertSame('http://hl7.org/fhir/sid/icd-9-cm', data_get($procedure, 'code.coding.0.system'));
        $this->assertTrue(data_get($procedure, 'code.coding.0.extension.0.valueBoolean'));

        $vitals = $resources
            ->where('resourceType', 'Observation')
            ->filter(fn (array $resource): bool => data_get($resource, 'category.0.coding.0.code') === 'vital-signs');
        $this->assertCount(6, $vitals);
        $this->assertSame(
            ['2708-6', '8310-5', '8462-4', '8480-6', '8867-4', '9279-1'],
            $vitals->pluck('code.coding.0.code')->sort()->values()->all(),
        );

        $serialized = json_encode($resources, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Pemeriksaan darah sintetis skenario', $serialized);
        $this->assertStringContainsString('Obat Simulasi A', $serialized);
        $this->assertStringContainsString('Pengambilan sampel darah vena', $serialized);
        $this->assertStringNotContainsString('http://snomed.info/sct', $serialized);
        $this->assertStringNotContainsString('http://sys-ids.kemkes.go.id', $serialized);
    }

    public function test_authorized_participant_can_open_the_minimized_never_sent_preview_with_audit(): void
    {
        $encounter = $this->completeReferenceJourney();
        $participant = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->sole();

        $this->actingAs($participant)
            ->get(route('encounters.interoperability-preview.show', $encounter))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (Assert $page) => $page
                ->component('encounter/interoperability-preview')
                ->where('boundary.transportState', 'NOT_SENT')
                ->where('boundary.readyForTransmission', false)
                ->where('summary.resourceCount', 18)
                ->where('bundle.type', 'collection')
                ->where('urls.back', route('encounters.debrief.show', $encounter)),
            );

        foreach ([
            route('encounters.show', $encounter),
            route('encounters.timeline.show', $encounter),
            route('encounters.debrief.show', $encounter),
        ] as $navigationPage) {
            $this->actingAs($participant)
                ->get($navigationPage)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where(
                    'urls.interoperabilityPreview',
                    route('encounters.interoperability-preview.show', $encounter),
                ));
        }

        $event = AuditEvent::query()->where('action', 'interop.preview_viewed')->sole();
        $this->assertSame('interoperability_preview', $event->resource_type);
        $this->assertSame($encounter->public_id, $event->resource_id);
        $this->assertSame(
            ['resource_count', 'resource_type_counts', 'validation_issue_count', 'ready_for_transmission'],
            array_keys($event->metadata ?? []),
        );
        $this->assertSame(18, data_get($event->metadata, 'resource_count'));
        $this->assertFalse(data_get($event->metadata, 'ready_for_transmission'));
        $auditJson = json_encode($event->metadata, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Pasien Sintetis Arunika', $auditJson);
        $this->assertStringNotContainsString('Sindrom pusing', $auditJson);
    }

    public function test_preview_requires_finalization_report_capability_and_exact_case_context(): void
    {
        $this->seedReferenceOutpatient();
        $encounter = Encounter::query()->sole();
        $participant = User::query()->where('email', 'mahasiswa.keperawatan@example.invalid')->sole();
        $assignment = Assignment::query()->where('user_id', $participant->getKey())->sole();

        $this->actingAs($participant)
            ->get(route('encounters.interoperability-preview.show', $encounter))
            ->assertStatus(409);

        $capabilities = $assignment->capabilities;
        $assignment->update([
            'capabilities' => collect($capabilities)
                ->reject(fn (string $capability): bool => $capability === Capability::ReportView->value)
                ->values()
                ->all(),
        ]);
        $this->actingAs($participant)
            ->get(route('encounters.interoperability-preview.show', $encounter))
            ->assertForbidden();

        $assignment->update([
            'capabilities' => $capabilities,
            'encounter_id' => null,
            'supervisor_assignment_id' => null,
        ]);
        $this->actingAs($participant)
            ->get(route('encounters.interoperability-preview.show', $encounter))
            ->assertForbidden();

        $this->assertDatabaseMissing('audit_events', ['action' => 'interop.preview_viewed']);
    }

    private function completeReferenceJourney(): Encounter
    {
        $this->seedReferenceOutpatient();
        $this->activateReferenceTerminology();
        $command = $this->artisan('simulation:complete-reference-journey');

        if (! $command instanceof PendingCommand) {
            $this->fail('Console output mocking must remain enabled for the interoperability preview test.');
        }

        $command->assertSuccessful()->run();

        return Encounter::query()->sole();
    }

    private function activateReferenceTerminology(): void
    {
        $this->activateRelease(TerminologySystem::Icd10, 'R42', 'Dizziness and giddiness');
        $this->activateRelease(TerminologySystem::Icd9Cm, '38.99', 'Other puncture of vein');
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
