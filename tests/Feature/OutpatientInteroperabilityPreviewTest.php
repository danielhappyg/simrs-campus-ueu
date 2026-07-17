<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Coding\Enums\TerminologyProvenanceStatus;
use App\Modules\Coding\Enums\TerminologyReleaseStatus;
use App\Modules\Coding\Enums\TerminologySystem;
use App\Modules\Coding\Models\TerminologyConcept;
use App\Modules\Coding\Models\TerminologyRelease;
use App\Modules\Coding\Support\TerminologyNormalizer;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Interoperability\Services\OutpatientFhirPreview;
use App\Modules\Teaching\Enums\ApplicationRole;
use App\Modules\Teaching\Models\Assignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
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
