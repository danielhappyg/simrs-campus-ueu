<?php

namespace App\Modules\Interoperability\Services;

use App\Modules\Encounter\Models\Encounter;
use App\Modules\Interoperability\Support\FhirPreviewValidator;
use App\Modules\Interoperability\Support\OutpatientPreviewUrl;
use DomainException;

final class OutpatientFhirPreview
{
    public function __construct(
        private readonly FhirPreviewValidator $validator,
        private readonly OutpatientClinicalResourceMapper $clinicalMapper,
    ) {}

    /** @return array<string, mixed> */
    public function build(Encounter $encounter): array
    {
        if ($encounter->finalized_at === null) {
            throw new DomainException('The interoperability preview is available only after encounter finalization.');
        }

        $encounter->loadMissing(['patient', 'location']);
        $clinical = $this->clinicalMapper->map($encounter);
        $entries = [
            $this->entry('Composition', $encounter->public_id, $this->composition($encounter, $clinical['entries'])),
            $this->entry('Patient', $encounter->patient->public_id, $this->patient($encounter)),
            $this->entry('Organization', 'simrs-campus-ueu', $this->organization()),
            $this->entry('Encounter', $encounter->public_id, $this->encounter($encounter)),
            ...$clinical['entries'],
        ];
        $bundle = [
            'resourceType' => 'Bundle',
            'id' => $encounter->public_id,
            'identifier' => [
                'system' => 'https://simrs-campus-ueu.example.invalid/identifiers/interoperability-preview',
                'value' => $encounter->public_id,
            ],
            'type' => 'collection',
            'timestamp' => $encounter->finalized_at->utc()->toIso8601String(),
            'entry' => $entries,
        ];
        $sourceIndex = [
            $this->source($entries[0], 'encounter', $encounter->public_id, 'composition'),
            $this->source($entries[1], 'synthetic_patient', $encounter->patient->public_id, 'patient'),
            $this->source($entries[2], 'simulation_configuration', $encounter->session->public_id, 'organization'),
            $this->source($entries[3], 'encounter', $encounter->public_id, 'encounter'),
            ...$clinical['sourceIndex'],
        ];
        $validation = $this->validator->validate($bundle, $sourceIndex);

        return [
            'boundary' => [
                'classification' => 'SIMULASI — DATA SINTETIS',
                'environment' => 'SIMULATION',
                'mode' => 'LOCAL_MAPPING_PREVIEW',
                'transportState' => 'NOT_SENT',
                'fhirVersion' => '4.0.1',
                'satusehatProfileStatus' => 'NOT_CLAIMED',
                'readyForTransmission' => false,
                'externalEndpoint' => null,
            ],
            'summary' => [
                'resourceCount' => count($entries),
                'resourceTypeCounts' => collect($entries)
                    ->countBy(fn (array $entry): string => $entry['resource']['resourceType'])
                    ->sortKeys()
                    ->all(),
            ],
            'bundle' => $bundle,
            'sourceIndex' => $sourceIndex,
            'validation' => $validation,
            'urls' => [
                'externalEndpoint' => null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $resource
     * @return array{fullUrl: string, resource: array<string, mixed>}
     */
    private function entry(string $type, string $id, array $resource): array
    {
        return [
            'fullUrl' => OutpatientPreviewUrl::resource($type, $id),
            'resource' => ['resourceType' => $type, 'id' => $id] + $resource,
        ];
    }

    /**
     * @param  list<array{fullUrl: string, resource: array<string, mixed>}>  $clinicalEntries
     * @return array<string, mixed>
     */
    private function composition(Encounter $encounter, array $clinicalEntries): array
    {
        $sections = collect($clinicalEntries)
            ->groupBy(fn (array $entry): string => $entry['resource']['resourceType'])
            ->map(fn ($entries, string $type): array => [
                'title' => $type,
                'entry' => $entries->map(fn (array $entry): array => ['reference' => $entry['fullUrl']])->values()->all(),
            ])
            ->values()
            ->all();

        return [
            'status' => 'preliminary',
            'type' => ['text' => 'Local outpatient interoperability preview'],
            'subject' => ['reference' => OutpatientPreviewUrl::resource('Patient', $encounter->patient->public_id)],
            'encounter' => ['reference' => OutpatientPreviewUrl::resource('Encounter', $encounter->public_id)],
            'date' => $encounter->finalized_at?->utc()->toIso8601String(),
            'author' => [['reference' => OutpatientPreviewUrl::resource('Organization', 'simrs-campus-ueu')]],
            'title' => 'SIMRS Campus UEU outpatient mapping preview — not sent',
            'custodian' => ['reference' => OutpatientPreviewUrl::resource('Organization', 'simrs-campus-ueu')],
            'section' => $sections,
        ];
    }

    /**
     * @param  array{fullUrl: string, resource: array<string, mixed>}  $entry
     * @return array<string, string>
     */
    private function source(array $entry, string $sourceType, string $sourcePublicId, string $sourcePath): array
    {
        return [
            'fullUrl' => $entry['fullUrl'],
            'resourceType' => (string) $entry['resource']['resourceType'],
            'resourceId' => (string) $entry['resource']['id'],
            'sourceType' => $sourceType,
            'sourcePublicId' => $sourcePublicId,
            'sourcePath' => $sourcePath,
        ];
    }

    /** @return array<string, mixed> */
    private function patient(Encounter $encounter): array
    {
        return [
            'active' => true,
            'name' => [['text' => $encounter->patient->full_name]],
            'gender' => strtolower($encounter->patient->administrative_sex->value),
            'birthDate' => $encounter->patient->birth_date->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function organization(): array
    {
        return [
            'active' => true,
            'name' => 'SIMRS Campus UEU (synthetic simulation)',
        ];
    }

    /** @return array<string, mixed> */
    private function encounter(Encounter $encounter): array
    {
        return [
            'status' => 'finished',
            'class' => [
                'system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode',
                'code' => 'AMB',
                'display' => 'ambulatory',
            ],
            'subject' => ['reference' => OutpatientPreviewUrl::resource('Patient', $encounter->patient->public_id)],
            'serviceProvider' => ['reference' => OutpatientPreviewUrl::resource('Organization', 'simrs-campus-ueu')],
            'period' => [
                'start' => $encounter->period_start?->utc()->toIso8601String(),
                'end' => $encounter->period_end?->utc()->toIso8601String(),
            ],
        ];
    }
}
