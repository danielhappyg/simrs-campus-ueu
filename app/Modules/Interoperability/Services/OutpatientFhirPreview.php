<?php

namespace App\Modules\Interoperability\Services;

use App\Modules\Encounter\Models\Encounter;
use App\Modules\Interoperability\Support\FhirPreviewValidator;
use App\Modules\Interoperability\Support\OutpatientPreviewUrl;
use DomainException;

final class OutpatientFhirPreview
{
    public function __construct(private readonly FhirPreviewValidator $validator) {}

    /** @return array<string, mixed> */
    public function build(Encounter $encounter): array
    {
        if ($encounter->finalized_at === null) {
            throw new DomainException('The interoperability preview is available only after encounter finalization.');
        }

        $encounter->loadMissing(['patient', 'location']);
        $entries = [
            $this->entry('Composition', $encounter->public_id, $this->composition($encounter)),
            $this->entry('Patient', $encounter->patient->public_id, $this->patient($encounter)),
            $this->entry('Organization', 'simrs-campus-ueu', $this->organization()),
            $this->entry('Encounter', $encounter->public_id, $this->encounter($encounter)),
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
        $validation = $this->validator->validate($bundle);

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
            'sourceIndex' => [],
            'validation' => $validation,
            'urls' => [
                'externalEndpoint' => null,
            ],
        ];
    }

    /** @return array{fullUrl: string, resource: array<string, mixed>} */
    private function entry(string $type, string $id, array $resource): array
    {
        return [
            'fullUrl' => OutpatientPreviewUrl::resource($type, $id),
            'resource' => ['resourceType' => $type, 'id' => $id] + $resource,
        ];
    }

    /** @return array<string, mixed> */
    private function composition(Encounter $encounter): array
    {
        return [
            'status' => 'preliminary',
            'type' => ['text' => 'Local outpatient interoperability preview'],
            'subject' => ['reference' => OutpatientPreviewUrl::resource('Patient', $encounter->patient->public_id)],
            'encounter' => ['reference' => OutpatientPreviewUrl::resource('Encounter', $encounter->public_id)],
            'date' => $encounter->finalized_at?->utc()->toIso8601String(),
            'author' => [['reference' => OutpatientPreviewUrl::resource('Organization', 'simrs-campus-ueu')]],
            'title' => 'SIMRS Campus UEU outpatient mapping preview — not sent',
            'custodian' => ['reference' => OutpatientPreviewUrl::resource('Organization', 'simrs-campus-ueu')],
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
