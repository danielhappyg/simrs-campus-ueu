<?php

namespace App\Modules\Interoperability\Services;

use App\Modules\Clinical\Enums\ClinicalDocumentType;
use App\Modules\Clinical\Enums\ClinicalEntryStatus;
use App\Modules\Clinical\Models\ClinicalCondition;
use App\Modules\Clinical\Models\ClinicalEntry;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Clinical\Models\ClinicalProcedure;
use App\Modules\Clinical\Models\DiagnosticResult;
use App\Modules\Clinical\Models\MedicationDispense;
use App\Modules\Clinical\Models\MedicationRequest;
use App\Modules\Clinical\Models\PharmacyReview;
use App\Modules\Clinical\Models\ServiceRequest;
use App\Modules\Clinical\Support\NursingIntakeDefinition;
use App\Modules\Coding\Enums\CodingAssignmentStatus;
use App\Modules\Coding\Enums\CodingSourceType;
use App\Modules\Coding\Models\CodingAssignment;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Interoperability\Support\OutpatientPreviewUrl;
use Illuminate\Support\Collection;

final class OutpatientClinicalResourceMapper
{
    /**
     * @return array{entries: list<array{fullUrl: string, resource: array<string, mixed>}>, sourceIndex: list<array<string, string>>}
     */
    public function map(Encounter $encounter): array
    {
        $entries = [];
        $sourceIndex = [];
        $codingBySource = $this->approvedCodings($encounter)
            ->keyBy(fn (CodingAssignment $coding): string => $coding->source_type->value.'|'.$coding->sourcePublicId());

        $nursing = $this->currentApprovedVersion($encounter, ClinicalDocumentType::NursingIntake);

        if ($nursing) {
            foreach ($this->vitalEntries($encounter, $nursing) as $mapped) {
                $entries[] = $mapped['entry'];
                $sourceIndex[] = $mapped['source'];
            }
        }

        $conditions = ClinicalCondition::query()
            ->where('encounter_id', $encounter->getKey())
            ->orderBy('id')
            ->get();

        foreach ($conditions as $condition) {
            $coding = $codingBySource->get(CodingSourceType::Diagnosis->value.'|'.$condition->public_id);
            $this->append(
                $entries,
                $sourceIndex,
                'Condition',
                $condition->public_id,
                $this->condition($encounter, $condition, $coding),
                'clinical_condition',
                $condition->public_id,
                'condition',
            );
        }

        $serviceRequests = ServiceRequest::query()
            ->with(['results' => fn ($query) => $query->orderByDesc('version_number')])
            ->where('encounter_id', $encounter->getKey())
            ->orderBy('sequence_number')
            ->get();

        foreach ($serviceRequests as $request) {
            $this->append(
                $entries,
                $sourceIndex,
                'ServiceRequest',
                $request->public_id,
                $this->serviceRequest($encounter, $request),
                'service_request',
                $request->public_id,
                'serviceRequest',
            );

            /** @var DiagnosticResult|null $result */
            $result = $request->results->first();

            if ($result) {
                $resultReferences = [];

                foreach ($this->diagnosticObservations($encounter, $request, $result) as $mapped) {
                    $entries[] = $mapped['entry'];
                    $sourceIndex[] = $mapped['source'];
                    $resultReferences[] = ['reference' => $mapped['entry']['fullUrl']];
                }

                $this->append(
                    $entries,
                    $sourceIndex,
                    'DiagnosticReport',
                    $result->public_id,
                    $this->diagnosticReport($encounter, $request, $result, $resultReferences),
                    'diagnostic_result',
                    $result->public_id,
                    'diagnosticReport',
                );
            }
        }

        $medicationRequests = MedicationRequest::query()
            ->with([
                'pharmacyReviews' => fn ($query) => $query->orderByDesc('version_number'),
                'dispenses' => fn ($query) => $query->orderByDesc('id'),
            ])
            ->where('encounter_id', $encounter->getKey())
            ->orderBy('sequence_number')
            ->orderByDesc('revision_number')
            ->get()
            ->unique('sequence_number')
            ->sortBy('sequence_number');

        foreach ($medicationRequests as $request) {
            $this->append(
                $entries,
                $sourceIndex,
                'MedicationRequest',
                $request->public_id,
                $this->medicationRequest($encounter, $request),
                'medication_request',
                $request->public_id,
                'medicationRequest',
            );

            /** @var PharmacyReview|null $review */
            $review = $request->pharmacyReviews->first();

            if ($review) {
                $this->append(
                    $entries,
                    $sourceIndex,
                    'QuestionnaireResponse',
                    $review->public_id,
                    $this->pharmacyReview($encounter, $request, $review),
                    'pharmacy_review',
                    $review->public_id,
                    'pharmacyReview',
                );
            }

            /** @var MedicationDispense|null $dispense */
            $dispense = $request->dispenses->first();

            if ($dispense) {
                $this->append(
                    $entries,
                    $sourceIndex,
                    'MedicationDispense',
                    $dispense->public_id,
                    $this->medicationDispense($encounter, $request, $dispense),
                    'medication_dispense',
                    $dispense->public_id,
                    'medicationDispense',
                );
            }
        }

        $procedures = ClinicalProcedure::query()
            ->where('encounter_id', $encounter->getKey())
            ->orderBy('sequence_number')
            ->get();

        foreach ($procedures as $procedure) {
            $coding = $codingBySource->get(CodingSourceType::Procedure->value.'|'.$procedure->public_id);
            $this->append(
                $entries,
                $sourceIndex,
                'Procedure',
                $procedure->public_id,
                $this->procedure($encounter, $procedure, $coding),
                'clinical_procedure',
                $procedure->public_id,
                'procedure',
            );
        }

        return compact('entries', 'sourceIndex');
    }

    /** @return Collection<int, CodingAssignment> */
    private function approvedCodings(Encounter $encounter): Collection
    {
        return CodingAssignment::query()
            ->with(['sourceCondition', 'sourceProcedure', 'concept', 'release'])
            ->where('encounter_id', $encounter->getKey())
            ->where('status', CodingAssignmentStatus::Approved)
            ->orderByDesc('id')
            ->get()
            ->unique(fn (CodingAssignment $coding): string => $coding->source_type->value.'|'.$coding->sourcePublicId())
            ->values();
    }

    private function currentApprovedVersion(Encounter $encounter, ClinicalDocumentType $type): ?ClinicalEntryVersion
    {
        $entry = ClinicalEntry::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('document_type', $type)
            ->first();

        return $entry?->versions()
            ->where('status', ClinicalEntryStatus::Approved)
            ->orderByDesc('version_number')
            ->first();
    }

    /**
     * @return list<array{entry: array{fullUrl: string, resource: array<string, mixed>}, source: array<string, string>}>
     */
    private function vitalEntries(Encounter $encounter, ClinicalEntryVersion $nursing): array
    {
        $mapped = [];

        foreach (NursingIntakeDefinition::vitalDefinitions() as $key => $definition) {
            $source = data_get($nursing->content, "vitalObservations.{$key}");

            if (! is_array($source)
                || ! array_key_exists('value', $source)
                || $source['value'] === null
                || $source['value'] === '') {
                continue;
            }

            $id = $nursing->public_id.'-'.str_replace('_', '-', $key);
            $resource = [
                'status' => 'final',
                'category' => [[
                    'coding' => [[
                        'system' => 'http://terminology.hl7.org/CodeSystem/observation-category',
                        'code' => 'vital-signs',
                        'display' => 'Vital Signs',
                    ]],
                ]],
                'code' => [
                    'coding' => [[
                        'system' => (string) ($source['codeSystem'] ?? 'http://loinc.org'),
                        'code' => (string) ($source['code'] ?? $definition['code']),
                        'display' => (string) ($source['display'] ?? $definition['display']),
                    ]],
                    'text' => $definition['label'],
                ],
                'subject' => $this->patientReference($encounter),
                'encounter' => $this->encounterReference($encounter),
                'effectiveDateTime' => $nursing->clinical_occurrence_at->utc()->toIso8601String(),
                'valueQuantity' => [
                    'value' => (float) $source['value'],
                    'unit' => (string) ($source['unitDisplay'] ?? $definition['unitDisplay']),
                    'system' => (string) ($source['unitSystem'] ?? 'http://unitsofmeasure.org'),
                    'code' => (string) ($source['unitCode'] ?? $definition['unitCode']),
                ],
            ];
            $entry = $this->entry('Observation', $id, $resource);
            $mapped[] = [
                'entry' => $entry,
                'source' => $this->source($entry, 'clinical_entry_version', $nursing->public_id, "content.vitalObservations.{$key}"),
            ];
        }

        return $mapped;
    }

    /** @return array<string, mixed> */
    private function condition(Encounter $encounter, ClinicalCondition $condition, ?CodingAssignment $coding): array
    {
        return [
            'clinicalStatus' => [
                'coding' => [[
                    'system' => 'http://terminology.hl7.org/CodeSystem/condition-clinical',
                    'code' => 'active',
                ]],
            ],
            'verificationStatus' => [
                'coding' => [[
                    'system' => 'http://terminology.hl7.org/CodeSystem/condition-ver-status',
                    'code' => 'provisional',
                ]],
            ],
            'code' => $this->codedConcept($condition->authored_text, $coding, 'http://hl7.org/fhir/sid/icd-10'),
            'subject' => $this->patientReference($encounter),
            'encounter' => $this->encounterReference($encounter),
            'onsetDateTime' => $condition->onset_at?->utc()->toIso8601String(),
            'recordedDate' => $condition->recorded_at->utc()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function serviceRequest(Encounter $encounter, ServiceRequest $request): array
    {
        $resource = [
            'status' => strtolower($request->status->value),
            'intent' => 'order',
            'priority' => strtolower($request->priority),
            'code' => ['text' => $request->authored_service],
            'subject' => $this->patientReference($encounter),
            'encounter' => $this->encounterReference($encounter),
            'authoredOn' => $request->authored_at->utc()->toIso8601String(),
            'note' => [['text' => $request->clinical_question]],
        ];

        if ($request->sourceCondition) {
            $resource['reasonReference'] = [[
                'reference' => OutpatientPreviewUrl::resource('Condition', $request->sourceCondition->public_id),
            ]];
        }

        return $resource;
    }

    /**
     * @return list<array{entry: array{fullUrl: string, resource: array<string, mixed>}, source: array<string, string>}>
     */
    private function diagnosticObservations(Encounter $encounter, ServiceRequest $request, DiagnosticResult $result): array
    {
        $components = data_get($result->content, 'components', []);
        $mapped = [];

        if (! is_array($components)) {
            return [];
        }

        foreach (array_values($components) as $index => $component) {
            if (! is_array($component)) {
                continue;
            }

            $id = count($components) === 1 ? $result->public_id : $result->public_id.'-'.($index + 1);
            $value = trim((string) ($component['value'] ?? '').' '.(string) ($component['unit'] ?? ''));
            $resource = [
                'status' => 'final',
                'category' => [['text' => $request->request_type]],
                'code' => ['text' => (string) ($component['display'] ?? $component['code'] ?? 'Diagnostic result')],
                'subject' => $this->patientReference($encounter),
                'encounter' => $this->encounterReference($encounter),
                'basedOn' => [['reference' => OutpatientPreviewUrl::resource('ServiceRequest', $request->public_id)]],
                'effectiveDateTime' => $result->effective_at->utc()->toIso8601String(),
                'valueString' => $value,
            ];
            $entry = $this->entry('Observation', $id, $resource);
            $mapped[] = [
                'entry' => $entry,
                'source' => $this->source($entry, 'diagnostic_result', $result->public_id, "content.components.{$index}"),
            ];
        }

        return $mapped;
    }

    /** @param list<array{reference: string}> $resultReferences
     * @return array<string, mixed>
     */
    private function diagnosticReport(
        Encounter $encounter,
        ServiceRequest $request,
        DiagnosticResult $result,
        array $resultReferences,
    ): array {
        return [
            'status' => 'final',
            'code' => ['text' => $result->report_display],
            'subject' => $this->patientReference($encounter),
            'encounter' => $this->encounterReference($encounter),
            'basedOn' => [['reference' => OutpatientPreviewUrl::resource('ServiceRequest', $request->public_id)]],
            'effectiveDateTime' => $result->effective_at->utc()->toIso8601String(),
            'issued' => $result->issued_at->utc()->toIso8601String(),
            'result' => $resultReferences,
            'conclusion' => data_get($result->content, 'narrativeConclusion'),
        ];
    }

    /** @return array<string, mixed> */
    private function medicationRequest(Encounter $encounter, MedicationRequest $request): array
    {
        $resource = [
            'status' => strtolower($request->status->value),
            'intent' => 'order',
            'medicationCodeableConcept' => ['text' => $request->authored_medication],
            'subject' => $this->patientReference($encounter),
            'encounter' => $this->encounterReference($encounter),
            'authoredOn' => $request->authored_at->utc()->toIso8601String(),
            'dosageInstruction' => [[
                'text' => $request->directions,
                'route' => ['text' => $request->route],
                'doseAndRate' => [[
                    'doseQuantity' => [
                        'value' => (float) $request->dose_value,
                        'unit' => $request->dose_unit,
                    ],
                ]],
            ]],
            'dispenseRequest' => [
                'quantity' => [
                    'value' => (float) $request->quantity_value,
                    'unit' => $request->quantity_unit,
                ],
            ],
            'note' => [['text' => trim("{$request->form} {$request->strength}; {$request->frequency}; {$request->duration}")]],
        ];

        if ($request->sourceCondition) {
            $resource['reasonReference'] = [[
                'reference' => OutpatientPreviewUrl::resource('Condition', $request->sourceCondition->public_id),
            ]];
        }

        return $resource;
    }

    /** @return array<string, mixed> */
    private function pharmacyReview(Encounter $encounter, MedicationRequest $request, PharmacyReview $review): array
    {
        $items = [[
            'linkId' => 'overall-outcome',
            'text' => 'Overall pharmacy review outcome',
            'answer' => [['valueString' => $review->overall_outcome->value]],
        ]];

        foreach ($review->domain_results as $domain => $results) {
            if (! is_array($results)) {
                continue;
            }

            foreach ($results as $index => $result) {
                if (! is_array($result)) {
                    continue;
                }

                $items[] = [
                    'linkId' => $domain.'-'.($index + 1),
                    'text' => (string) ($result['criterionCode'] ?? $result['criterion_code'] ?? 'Review criterion'),
                    'answer' => [['valueString' => (string) ($result['outcome'] ?? '')]],
                ];
            }
        }

        return [
            'status' => 'completed',
            'subject' => $this->patientReference($encounter),
            'encounter' => $this->encounterReference($encounter),
            'authored' => $review->reviewed_at->utc()->toIso8601String(),
            'basedOn' => [['reference' => OutpatientPreviewUrl::resource('MedicationRequest', $request->public_id)]],
            'item' => $items,
        ];
    }

    /** @return array<string, mixed> */
    private function medicationDispense(
        Encounter $encounter,
        MedicationRequest $request,
        MedicationDispense $dispense,
    ): array {
        return [
            'status' => 'completed',
            'medicationCodeableConcept' => ['text' => $request->authored_medication],
            'subject' => $this->patientReference($encounter),
            'context' => $this->encounterReference($encounter),
            'authorizingPrescription' => [[
                'reference' => OutpatientPreviewUrl::resource('MedicationRequest', $request->public_id),
            ]],
            'quantity' => [
                'value' => (float) $dispense->quantity,
                'unit' => $dispense->unit,
            ],
            'whenPrepared' => $dispense->prepared_at->utc()->toIso8601String(),
            'whenHandedOver' => $dispense->handed_over_at?->utc()->toIso8601String(),
            'note' => [['text' => $dispense->outcome->value]],
        ];
    }

    /** @return array<string, mixed> */
    private function procedure(Encounter $encounter, ClinicalProcedure $procedure, ?CodingAssignment $coding): array
    {
        $resource = [
            'status' => 'completed',
            'code' => $this->codedConcept($procedure->authored_text, $coding, 'http://hl7.org/fhir/sid/icd-9-cm'),
            'subject' => $this->patientReference($encounter),
            'encounter' => $this->encounterReference($encounter),
            'performedPeriod' => [
                'start' => $procedure->performed_start_at->utc()->toIso8601String(),
                'end' => $procedure->performed_end_at?->utc()->toIso8601String(),
            ],
            'performer' => [['actor' => ['display' => $procedure->performer_text]]],
            'bodySite' => $procedure->body_site_text ? [['text' => $procedure->body_site_text]] : [],
            'outcome' => $procedure->outcome_text ? ['text' => $procedure->outcome_text] : null,
            'note' => $procedure->note ? [['text' => $procedure->note]] : [],
        ];

        if ($procedure->basedOnServiceRequest) {
            $resource['basedOn'] = [[
                'reference' => OutpatientPreviewUrl::resource('ServiceRequest', $procedure->basedOnServiceRequest->public_id),
            ]];
        }

        return $resource;
    }

    /** @return array<string, mixed> */
    private function codedConcept(string $text, ?CodingAssignment $coding, string $system): array
    {
        if (! $coding) {
            return ['text' => $text];
        }

        return [
            'coding' => [[
                'system' => $system,
                'version' => $coding->release->logical_version,
                'code' => $coding->concept->code,
                'display' => $coding->concept->display,
                'extension' => [[
                    'url' => 'https://simrs-campus-ueu.example.invalid/fhir/StructureDefinition/human-reviewed',
                    'valueBoolean' => true,
                ]],
            ]],
            'text' => $text,
        ];
    }

    /** @return array{reference: string} */
    private function patientReference(Encounter $encounter): array
    {
        return ['reference' => OutpatientPreviewUrl::resource('Patient', $encounter->patient->public_id)];
    }

    /** @return array{reference: string} */
    private function encounterReference(Encounter $encounter): array
    {
        return ['reference' => OutpatientPreviewUrl::resource('Encounter', $encounter->public_id)];
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
     * @param  list<array{fullUrl: string, resource: array<string, mixed>}>  $entries
     * @param  list<array<string, string>>  $sourceIndex
     * @param  array<string, mixed>  $resource
     */
    private function append(
        array &$entries,
        array &$sourceIndex,
        string $type,
        string $id,
        array $resource,
        string $sourceType,
        string $sourcePublicId,
        string $sourcePath,
    ): void {
        $entry = $this->entry($type, $id, $resource);
        $entries[] = $entry;
        $sourceIndex[] = $this->source($entry, $sourceType, $sourcePublicId, $sourcePath);
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
}
