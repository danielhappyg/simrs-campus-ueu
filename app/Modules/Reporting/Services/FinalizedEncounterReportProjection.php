<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Clinical\Enums\AllergyAssessmentState;
use App\Modules\Clinical\Enums\ClinicalDocumentType;
use App\Modules\Clinical\Enums\ClinicalEntryStatus;
use App\Modules\Clinical\Enums\CurrentMedicationState;
use App\Modules\Clinical\Enums\EncounterClosureStatus;
use App\Modules\Clinical\Models\ClinicalEntry;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Clinical\Models\DiagnosticResult;
use App\Modules\Clinical\Models\EncounterClosure;
use App\Modules\Clinical\Models\MedicationDispense;
use App\Modules\Clinical\Models\MedicationRequest;
use App\Modules\Clinical\Models\ServiceRequest;
use App\Modules\Clinical\Support\NursingIntakeDefinition;
use App\Modules\Coding\Enums\CodingAssignmentStatus;
use App\Modules\Coding\Enums\CodingSourceType;
use App\Modules\Coding\Models\CodingAssignment;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Enums\IdentifierType;
use App\Modules\Teaching\Enums\RubricReferenceStatus;
use App\Modules\Teaching\Models\DebriefNote;
use App\Modules\Teaching\Models\DebriefNoteVersion;
use App\Modules\Teaching\Services\EncounterDebriefTimeline;
use Illuminate\Support\Collection;

final class FinalizedEncounterReportProjection
{
    public function __construct(private readonly EncounterDebriefTimeline $timeline) {}

    /** @return array<string, mixed> */
    public function outpatientSummary(Encounter $encounter): array
    {
        $this->loadContext($encounter);
        $nursing = $this->currentApprovedVersion($encounter, ClinicalDocumentType::NursingIntake);
        $medical = $this->currentApprovedVersion($encounter, ClinicalDocumentType::MedicalAssessment);
        $closure = EncounterClosure::query()
            ->with(['procedures'])
            ->where('encounter_id', $encounter->getKey())
            ->where('status', EncounterClosureStatus::Approved)
            ->orderByDesc('version_number')
            ->first();
        $codings = $this->approvedCodings($encounter);
        $diagnosisCodings = $codings
            ->where('source_type', CodingSourceType::Diagnosis)
            ->keyBy('source_condition_id');
        $procedureCodings = $codings
            ->where('source_type', CodingSourceType::Procedure)
            ->keyBy('source_procedure_id');
        $diagnoses = $medical?->conditions()
            ->orderBy('id')
            ->get()
            ->map(function ($condition) use ($diagnosisCodings): array {
                /** @var CodingAssignment|null $coding */
                $coding = $diagnosisCodings->get($condition->getKey());

                return [
                    'publicId' => $condition->public_id,
                    'authoredText' => $condition->authored_text,
                    'role' => $condition->role->label(),
                    'certainty' => $condition->certainty->label(),
                    'onsetAt' => $condition->onset_at?->toDateString(),
                    'coding' => $this->coding($coding),
                ];
            })->values()->all() ?? [];
        $procedures = $closure?->procedures
            ->sortBy('sequence_number')
            ->map(function ($procedure) use ($procedureCodings): array {
                /** @var CodingAssignment|null $coding */
                $coding = $procedureCodings->get($procedure->getKey());

                return [
                    'publicId' => $procedure->public_id,
                    'authoredText' => $procedure->authored_text,
                    'status' => $procedure->status->value,
                    'performedStartAt' => $procedure->performed_start_at->toIso8601String(),
                    'performedEndAt' => $procedure->performed_end_at?->toIso8601String(),
                    'performerText' => $procedure->performer_text,
                    'bodySiteText' => $procedure->body_site_text,
                    'outcomeText' => $procedure->outcome_text,
                    'note' => $procedure->note,
                    'coding' => $this->coding($coding),
                ];
            })->values()->all() ?? [];
        $results = $this->results($encounter);
        $medications = $this->medications($encounter);
        $nursingContent = $nursing ? $nursing->content : [];
        $medicalContent = $medical ? $medical->content : [];
        $closureContent = $closure ? $closure->content : [];

        return [
            'document' => $this->documentMetadata(
                title: 'Ringkasan Rawat Jalan Simulasi',
                type: 'OUTPATIENT_SUMMARY',
                encounter: $encounter,
            ),
            ...$this->caseContext($encounter),
            'sourceStatus' => [
                'nursing' => $this->clinicalSource($nursing, 'Asesmen awal keperawatan'),
                'medical' => $this->clinicalSource($medical, 'Asesmen medis'),
                'closure' => $closure ? [
                    'label' => 'Penutupan encounter',
                    'publicId' => $closure->public_id,
                    'versionNumber' => $closure->version_number,
                    'status' => $closure->status->label(),
                    'author' => $closure->author?->name,
                    'clinicalOccurrenceAt' => $closure->clinical_occurrence_at->toIso8601String(),
                ] : null,
            ],
            'sections' => [
                'history' => [
                    'chiefComplaint' => data_get($nursingContent, 'chiefComplaint'),
                    'onsetDuration' => data_get($nursingContent, 'onsetDuration'),
                    'presentIllness' => data_get($medicalContent, 'history.presentIllness'),
                    'pastMedical' => data_get($medicalContent, 'history.pastMedical'),
                    'family' => data_get($medicalContent, 'history.family'),
                    'social' => data_get($medicalContent, 'history.social'),
                ],
                'allergyAndMedicationHistory' => [
                    'allergy' => [
                        'state' => AllergyAssessmentState::tryFrom((string) data_get($nursingContent, 'allergyAssessment.state'))?->label()
                            ?? 'Belum dinilai',
                        'details' => data_get($nursingContent, 'allergyAssessment.details'),
                    ],
                    'currentMedication' => [
                        'state' => CurrentMedicationState::tryFrom((string) data_get($nursingContent, 'currentMedication.state'))?->label()
                            ?? 'Belum diketahui',
                        'details' => data_get($nursingContent, 'currentMedication.details'),
                    ],
                ],
                'examination' => [
                    'consciousness' => data_get($nursingContent, 'consciousness'),
                    'vitals' => $this->vitals($nursingContent),
                    'general' => data_get($medicalContent, 'examination.general'),
                    'focused' => data_get($medicalContent, 'examination.focused'),
                    'assessmentSummary' => data_get($medicalContent, 'assessmentSummary'),
                ],
                'diagnoses' => $diagnoses,
                'results' => $results,
                'procedures' => $procedures,
                'medications' => $medications,
                'planAndClosure' => [
                    'carePlan' => data_get($medicalContent, 'plan.carePlan'),
                    'education' => data_get($medicalContent, 'plan.education'),
                    'medicalFollowUp' => data_get($medicalContent, 'plan.followUp'),
                    'intendedDisposition' => data_get($medicalContent, 'plan.intendedDisposition'),
                    'leavingCondition' => data_get($closureContent, 'authored.leavingCondition'),
                    'disposition' => data_get($closureContent, 'authored.disposition'),
                    'followUpPlan' => data_get($closureContent, 'authored.followUpPlan'),
                    'referralPlan' => data_get($closureContent, 'authored.referralPlan'),
                    'educationInstructions' => data_get($closureContent, 'authored.educationInstructions'),
                    'outpatientSummary' => data_get($closureContent, 'authored.outpatientSummary'),
                ],
            ],
            'sourceCounts' => [
                'approvedClinicalDocuments' => collect([$nursing, $medical])->filter()->count(),
                'diagnoses' => count($diagnoses),
                'currentResults' => count($results),
                'performedProcedures' => count($procedures),
                'currentMedicationRequests' => count($medications),
                'approvedCodingAssignments' => $codings->count(),
            ],
            'sectionCount' => 8,
        ];
    }

    /** @return array<string, mixed> */
    public function debriefEvidence(Encounter $encounter): array
    {
        $this->loadContext($encounter);
        $timeline = $this->timeline->build($encounter);
        $notes = DebriefNote::query()
            ->with(['versions.authorAssignment.user'])
            ->where('encounter_id', $encounter->getKey())
            ->orderBy('created_at')
            ->get()
            ->map(fn (DebriefNote $note): array => [
                'publicId' => $note->public_id,
                'type' => $note->note_type->label(),
                'createdAt' => $note->created_at?->toIso8601String(),
                'versions' => $note->versions
                    ->sortBy('version_number')
                    ->values()
                    ->map(fn (DebriefNoteVersion $version): array => [
                        'versionNumber' => $version->version_number,
                        'body' => $version->body,
                        'changeReason' => $version->change_reason,
                        'authoredAt' => $version->authored_at->toIso8601String(),
                        'author' => [
                            'name' => $version->authorAssignment->user->name,
                            'program' => $version->authorAssignment->program->label(),
                            'role' => $version->authorAssignment->application_role->label(),
                        ],
                    ])->all(),
            ])->all();
        $learningOutcomes = $encounter->session->scenario->learning_outcomes ?? [];
        $rubricReferences = collect($encounter->session->scenario->rubric_references ?? [])
            ->filter(fn (array $reference): bool => is_string($reference['code'] ?? null)
                && is_string($reference['title'] ?? null)
                && is_string($reference['version'] ?? null))
            ->map(function (array $reference) use ($learningOutcomes): array {
                $numbers = collect(is_array($reference['learning_outcome_numbers'] ?? null)
                    ? $reference['learning_outcome_numbers']
                    : [])
                    ->filter(fn (mixed $number): bool => is_int($number) && $number > 0)
                    ->unique()
                    ->sort()
                    ->values();

                return [
                    'code' => $reference['code'],
                    'title' => $reference['title'],
                    'version' => $reference['version'],
                    'status' => RubricReferenceStatus::tryFrom((string) ($reference['status'] ?? ''))?->label()
                        ?? 'Status tidak dikenali',
                    'sourceLabel' => is_string($reference['source_label'] ?? null)
                        ? $reference['source_label']
                        : 'Sumber belum dinyatakan',
                    'learningOutcomes' => $numbers->map(fn (int $number): array => [
                        'number' => $number,
                        'label' => $learningOutcomes[$number - 1] ?? "Tujuan pembelajaran {$number}",
                    ])->all(),
                    'nonScoring' => true,
                ];
            })->values()->all();

        return [
            'document' => $this->documentMetadata(
                title: 'Laporan Bukti Debrief Simulasi',
                type: 'DEBRIEF_EVIDENCE',
                encounter: $encounter,
            ),
            ...$this->caseContext($encounter),
            'session' => [
                'code' => $encounter->session->code,
                'scenarioTitle' => $encounter->session->scenario->title,
                'scenarioVersion' => $encounter->session->scenario->version,
                'learningOutcomes' => $learningOutcomes,
            ],
            'timeline' => $timeline,
            'notes' => $notes,
            'rubricReferences' => $rubricReferences,
            'sourceCounts' => [
                'timelineEvents' => data_get($timeline, 'summary.displayedEventCount', 0),
                'logicalNotes' => count($notes),
                'noteVersions' => collect($notes)->sum(fn (array $note): int => count($note['versions'])),
                'rubricReferences' => count($rubricReferences),
            ],
            'sectionCount' => 4,
        ];
    }

    private function loadContext(Encounter $encounter): void
    {
        $encounter->loadMissing([
            'session.scenario',
            'patient.identifiers',
            'location',
        ]);
    }

    /** @return array<string, mixed> */
    private function documentMetadata(string $title, string $type, Encounter $encounter): array
    {
        return [
            'title' => $title,
            'type' => $type,
            'generatedAt' => now()->toIso8601String(),
            'finalizedAt' => $encounter->finalized_at?->toIso8601String()
                ?? $encounter->period_end?->toIso8601String(),
            'classification' => 'SIMULASI — DATA SINTETIS',
            'legalStatus' => 'Pratinjau pembelajaran; bukan rekam medis legal, PDF tersertifikasi, atau kiriman SATUSEHAT.',
            'sourcePolicy' => 'Dihasilkan saat diminta dari sumber encounter final yang disetujui; tidak disimpan sebagai dokumen klinis baru.',
        ];
    }

    /** @return array<string, mixed> */
    private function caseContext(Encounter $encounter): array
    {
        $mrn = $encounter->patient->identifiers->firstWhere('type', IdentifierType::MedicalRecordNumber);
        $ageAtEncounter = $encounter->period_start
            ? (int) $encounter->patient->birth_date->diffInYears($encounter->period_start)
            : null;

        return [
            'patient' => [
                'fullName' => $encounter->patient->full_name,
                'mrn' => $mrn?->value,
                'birthDate' => $encounter->patient->birth_date->toDateString(),
                'ageAtEncounter' => $ageAtEncounter,
                'administrativeSex' => $encounter->patient->administrative_sex->label(),
                'synthetic' => true,
            ],
            'encounter' => [
                'publicId' => $encounter->public_id,
                'number' => $encounter->encounter_number,
                'status' => $encounter->status->label(),
                'serviceType' => $encounter->service_type_display,
                'location' => $encounter->location->name,
                'periodStart' => $encounter->period_start?->toIso8601String(),
                'periodEnd' => $encounter->period_end?->toIso8601String(),
                'disposition' => $encounter->disposition,
                'sessionCode' => $encounter->session->code,
            ],
        ];
    }

    private function currentApprovedVersion(
        Encounter $encounter,
        ClinicalDocumentType $documentType,
    ): ?ClinicalEntryVersion {
        $entry = ClinicalEntry::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('document_type', $documentType)
            ->first();

        return $entry?->versions()
            ->with(['author'])
            ->where('status', ClinicalEntryStatus::Approved)
            ->orderByDesc('version_number')
            ->first();
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

    /** @return array<string, mixed>|null */
    private function coding(?CodingAssignment $coding): ?array
    {
        if (! $coding) {
            return null;
        }

        return [
            'system' => $coding->release->classification_system->label(),
            'version' => $coding->release->logical_version,
            'code' => $coding->concept->code,
            'display' => $coding->concept->display,
            'status' => $coding->status->label(),
            'humanReviewed' => true,
        ];
    }

    /** @param array<string, mixed> $content
     * @return list<array<string, mixed>>
     */
    private function vitals(array $content): array
    {
        $vitals = collect(NursingIntakeDefinition::vitalDefinitions())
            ->map(function (array $definition, string $key) use ($content): ?array {
                $value = data_get($content, "vitalObservations.{$key}.value");

                if ($value === null || $value === '') {
                    return null;
                }

                return [
                    'label' => $definition['label'],
                    'value' => $value,
                    'unit' => $definition['unitDisplay'],
                    'code' => $definition['code'],
                ];
            })
            ->filter()
            ->values()
            ->all();

        return array_values($vitals);
    }

    /** @return list<array<string, mixed>> */
    private function results(Encounter $encounter): array
    {
        $results = ServiceRequest::query()
            ->where('encounter_id', $encounter->getKey())
            ->with(['results' => fn ($query) => $query->orderByDesc('version_number')])
            ->orderBy('sequence_number')
            ->get()
            ->map(function (ServiceRequest $request): array {
                /** @var DiagnosticResult|null $result */
                $result = $request->results->first();
                $rawComponents = $result ? data_get($result->content, 'components', []) : [];
                $components = is_array($rawComponents)
                    ? array_values(array_filter($rawComponents, fn (mixed $component): bool => is_array($component)))
                    : [];

                return [
                    'publicId' => $request->public_id,
                    'service' => $request->authored_service,
                    'requestType' => $request->request_type,
                    'requestStatus' => $request->status->value,
                    'result' => $result ? [
                        'publicId' => $result->public_id,
                        'versionNumber' => $result->version_number,
                        'status' => $result->status->label(),
                        'reportDisplay' => $result->report_display,
                        'effectiveAt' => $result->effective_at->toIso8601String(),
                        'conclusion' => data_get($result->content, 'narrativeConclusion'),
                        'components' => $components,
                    ] : null,
                ];
            })
            ->values()
            ->all();

        return array_values($results);
    }

    /** @return list<array<string, mixed>> */
    private function medications(Encounter $encounter): array
    {
        $medications = MedicationRequest::query()
            ->where('encounter_id', $encounter->getKey())
            ->with(['dispenses' => fn ($query) => $query->orderByDesc('id')])
            ->orderBy('sequence_number')
            ->orderByDesc('revision_number')
            ->get()
            ->unique('sequence_number')
            ->sortBy('sequence_number')
            ->map(function (MedicationRequest $request): array {
                /** @var MedicationDispense|null $dispense */
                $dispense = $request->dispenses->first();

                return [
                    'publicId' => $request->public_id,
                    'authoredMedication' => $request->authored_medication,
                    'form' => $request->form,
                    'strength' => $request->strength,
                    'dose' => trim("{$request->dose_value} {$request->dose_unit}"),
                    'route' => $request->route,
                    'frequency' => $request->frequency,
                    'duration' => $request->duration,
                    'directions' => $request->directions,
                    'indicationText' => $request->indication_text,
                    'status' => $request->status->value,
                    'dispense' => $dispense ? [
                        'outcome' => $dispense->outcome->label(),
                        'quantity' => trim("{$dispense->quantity} {$dispense->unit}"),
                        'handedOverAt' => $dispense->handed_over_at?->toIso8601String(),
                    ] : null,
                ];
            })
            ->values()
            ->all();

        return array_values($medications);
    }

    /** @return array<string, mixed>|null */
    private function clinicalSource(?ClinicalEntryVersion $version, string $label): ?array
    {
        if (! $version) {
            return null;
        }

        return [
            'label' => $label,
            'publicId' => $version->public_id,
            'versionNumber' => $version->version_number,
            'status' => $version->status->label(),
            'author' => $version->author?->name,
            'clinicalOccurrenceAt' => $version->clinical_occurrence_at->toIso8601String(),
        ];
    }
}
