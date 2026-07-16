<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Clinical\Enums\ClinicalDocumentType;
use App\Modules\Clinical\Enums\ClinicalEntryStatus;
use App\Modules\Clinical\Enums\MedicationRequestStatus;
use App\Modules\Clinical\Enums\PharmacyInterventionStatus;
use App\Modules\Clinical\Enums\ServiceRequestStatus;
use App\Modules\Clinical\Models\ClinicalEntry;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Clinical\Models\DiagnosticResult;
use App\Modules\Clinical\Models\MedicationDispense;
use App\Modules\Clinical\Models\MedicationRequest;
use App\Modules\Clinical\Models\PharmacyIntervention;
use App\Modules\Clinical\Models\ServiceRequest;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Models\WorkTask;

class ClosureReadinessService
{
    /**
     * @return array{
     *   ready: bool,
     *   checks: list<array{
     *     code: string,
     *     label: string,
     *     passed: bool,
     *     blocking: bool,
     *     detail: string,
     *     evidence: list<array<string, mixed>>
     *   }>
     * }
     */
    public function evaluate(Encounter $encounter): array
    {
        $nursingVersion = $this->currentVersion($encounter, ClinicalDocumentType::NursingIntake);
        $medicalVersion = $this->currentVersion($encounter, ClinicalDocumentType::MedicalAssessment);
        $resultEvidence = $this->resultEvidence($encounter);
        $medicationEvidence = $this->medicationEvidence($encounter);
        $interventionEvidence = $this->interventionEvidence($encounter);
        $taskEvidence = $this->blockingTaskEvidence($encounter);

        $checks = [
            $this->check(
                code: 'CURRENT_NURSING_APPROVED',
                label: 'Asesmen awal saat ini disetujui',
                passed: $nursingVersion?->status === ClinicalEntryStatus::Approved,
                passedDetail: 'Versi asesmen awal saat ini telah disetujui supervisor.',
                failedDetail: 'Versi asesmen awal saat ini belum disetujui supervisor.',
                evidence: $nursingVersion ? [$this->clinicalVersionEvidence($nursingVersion)] : [],
            ),
            $this->check(
                code: 'CURRENT_MEDICAL_APPROVED',
                label: 'Asesmen medis saat ini disetujui',
                passed: $medicalVersion?->status === ClinicalEntryStatus::Approved,
                passedDetail: 'Versi asesmen medis saat ini telah disetujui supervisor.',
                failedDetail: 'Versi asesmen medis saat ini belum disetujui supervisor.',
                evidence: $medicalVersion ? [$this->clinicalVersionEvidence($medicalVersion)] : [],
            ),
            $this->check(
                code: 'CURRENT_RESULTS_ACKNOWLEDGED',
                label: 'Semua hasil saat ini telah diakui peminta',
                passed: collect($resultEvidence)->every(fn (array $item): bool => (bool) $item['passed']),
                passedDetail: $resultEvidence === []
                    ? 'Tidak ada permintaan layanan diagnostik pada encounter ini.'
                    : 'Semua hasil diagnostik saat ini telah diakui oleh assignment peminta yang tepat.',
                failedDetail: 'Sedikitnya satu permintaan layanan belum memiliki hasil saat ini yang diakui peminta.',
                evidence: $resultEvidence,
            ),
            $this->check(
                code: 'MEDICATION_REQUESTS_TERMINAL',
                label: 'Semua permintaan obat telah mencapai outcome akhir',
                passed: collect($medicationEvidence)->every(fn (array $item): bool => (bool) $item['passed']),
                passedDetail: $medicationEvidence === []
                    ? 'Tidak ada permintaan obat pada encounter ini.'
                    : 'Semua permintaan obat telah selesai, parsial, tidak diserahkan, atau dibatalkan.',
                failedDetail: 'Sedikitnya satu permintaan obat masih memerlukan tindakan.',
                evidence: $medicationEvidence,
            ),
            $this->check(
                code: 'PHARMACY_INTERVENTIONS_RESOLVED',
                label: 'Semua intervensi farmasi telah diselesaikan',
                passed: collect($interventionEvidence)->every(fn (array $item): bool => (bool) $item['passed']),
                passedDetail: $interventionEvidence === []
                    ? 'Tidak ada intervensi farmasi pada encounter ini.'
                    : 'Semua intervensi farmasi telah berstatus selesai.',
                failedDetail: 'Sedikitnya satu intervensi farmasi masih terbuka atau menunggu penyelesaian.',
                evidence: $interventionEvidence,
            ),
            $this->check(
                code: 'DOWNSTREAM_TASKS_CLEARED',
                label: 'Tidak ada tugas hilir yang masih memblokir',
                passed: $taskEvidence === [],
                passedDetail: 'Tugas acknowledgement hasil dan farmasi yang memblokir telah selesai.',
                failedDetail: 'Masih ada tugas acknowledgement hasil atau farmasi yang belum selesai.',
                evidence: $taskEvidence,
            ),
        ];

        return [
            'ready' => collect($checks)->every(fn (array $check): bool => $check['passed']),
            'checks' => $checks,
        ];
    }

    /** @return array<string, mixed> */
    public function sourceSnapshot(Encounter $encounter): array
    {
        $nursingVersion = $this->currentVersion($encounter, ClinicalDocumentType::NursingIntake);
        $medicalVersion = $this->currentVersion($encounter, ClinicalDocumentType::MedicalAssessment);
        $serviceRequests = ServiceRequest::query()
            ->where('encounter_id', $encounter->getKey())
            ->with([
                'results' => fn ($query) => $query
                    ->with('acknowledgements')
                    ->orderByDesc('version_number'),
            ])
            ->orderBy('sequence_number')
            ->get();
        $medicationRequests = MedicationRequest::query()
            ->where('encounter_id', $encounter->getKey())
            ->with(['dispenses' => fn ($query) => $query->orderByDesc('id')])
            ->orderBy('sequence_number')
            ->orderBy('revision_number')
            ->get();

        return [
            'nursing' => $nursingVersion ? $this->clinicalVersionEvidence($nursingVersion) : null,
            'medical' => $medicalVersion ? $this->clinicalVersionEvidence($medicalVersion) : null,
            'diagnoses' => $medicalVersion?->conditions()
                ->orderBy('id')
                ->get()
                ->map(fn ($condition): array => [
                    'publicId' => $condition->public_id,
                    'authoredText' => $condition->authored_text,
                    'certainty' => $condition->certainty->value,
                    'role' => $condition->role->value,
                    'codeSystem' => $condition->code_system,
                    'code' => $condition->code,
                    'display' => $condition->display,
                    'codeVersion' => $condition->code_version,
                ])
                ->values()
                ->all() ?? [],
            'results' => $serviceRequests->map(function (ServiceRequest $request): array {
                /** @var DiagnosticResult|null $currentResult */
                $currentResult = $request->results->first();
                $acknowledgement = $currentResult?->acknowledgements
                    ->firstWhere('actor_assignment_id', $request->requester_assignment_id);

                return [
                    'serviceRequestPublicId' => $request->public_id,
                    'authoredService' => $request->authored_service,
                    'requestStatus' => $request->status->value,
                    'resultPublicId' => $currentResult?->public_id,
                    'resultVersion' => $currentResult?->version_number,
                    'resultContentHash' => $currentResult?->content_hash,
                    'resultConclusion' => data_get($currentResult?->content, 'narrativeConclusion'),
                    'acknowledgementPublicId' => $acknowledgement?->public_id,
                    'acknowledgementOutcome' => $acknowledgement?->outcome,
                    'acknowledgedAt' => $acknowledgement?->acknowledged_at->toIso8601String(),
                ];
            })->values()->all(),
            'medications' => $medicationRequests->map(function (MedicationRequest $request): array {
                /** @var MedicationDispense|null $dispense */
                $dispense = $request->dispenses->first();

                return [
                    'medicationRequestPublicId' => $request->public_id,
                    'sequenceNumber' => $request->sequence_number,
                    'revisionNumber' => $request->revision_number,
                    'authoredMedication' => $request->authored_medication,
                    'status' => $request->status->value,
                    'dispensePublicId' => $dispense?->public_id,
                    'dispenseOutcome' => $dispense?->outcome->value,
                    'dispenseContentHash' => $dispense?->content_hash,
                ];
            })->values()->all(),
        ];
    }

    private function currentVersion(Encounter $encounter, ClinicalDocumentType $documentType): ?ClinicalEntryVersion
    {
        $entry = ClinicalEntry::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('document_type', $documentType)
            ->first();

        return $entry?->versions()->orderByDesc('version_number')->first();
    }

    /** @return list<array<string, mixed>> */
    private function resultEvidence(Encounter $encounter): array
    {
        $evidence = ServiceRequest::query()
            ->where('encounter_id', $encounter->getKey())
            ->with([
                'results' => fn ($query) => $query
                    ->with('acknowledgements')
                    ->orderByDesc('version_number'),
            ])
            ->orderBy('sequence_number')
            ->get()
            ->map(function (ServiceRequest $request): array {
                /** @var DiagnosticResult|null $currentResult */
                $currentResult = $request->results->first();
                $acknowledgement = $currentResult?->acknowledgements
                    ->firstWhere('actor_assignment_id', $request->requester_assignment_id);
                $cancelled = $request->status === ServiceRequestStatus::Cancelled;

                return [
                    'publicId' => $request->public_id,
                    'label' => $request->authored_service,
                    'status' => $request->status->value,
                    'currentResultPublicId' => $currentResult?->public_id,
                    'currentResultVersion' => $currentResult?->version_number,
                    'currentResultContentHash' => $currentResult?->content_hash,
                    'acknowledgementPublicId' => $acknowledgement?->public_id,
                    'acknowledgedByRequester' => $acknowledgement !== null,
                    'passed' => $cancelled || ($currentResult !== null && $acknowledgement !== null),
                ];
            })
            ->values()
            ->all();

        return array_values($evidence);
    }

    /** @return list<array<string, mixed>> */
    private function medicationEvidence(Encounter $encounter): array
    {
        $terminalStatuses = [
            MedicationRequestStatus::Completed,
            MedicationRequestStatus::Partial,
            MedicationRequestStatus::NotDispensed,
            MedicationRequestStatus::Cancelled,
        ];

        $evidence = MedicationRequest::query()
            ->where('encounter_id', $encounter->getKey())
            ->orderBy('sequence_number')
            ->orderBy('revision_number')
            ->get()
            ->map(fn (MedicationRequest $request): array => [
                'publicId' => $request->public_id,
                'label' => $request->authored_medication,
                'status' => $request->status->value,
                'revisionNumber' => $request->revision_number,
                'passed' => in_array($request->status, $terminalStatuses, true),
            ])
            ->values()
            ->all();

        return array_values($evidence);
    }

    /** @return list<array<string, mixed>> */
    private function interventionEvidence(Encounter $encounter): array
    {
        $evidence = PharmacyIntervention::query()
            ->whereRelation('medicationRequest', 'encounter_id', $encounter->getKey())
            ->orderBy('opened_at')
            ->get()
            ->map(fn (PharmacyIntervention $intervention): array => [
                'publicId' => $intervention->public_id,
                'label' => $intervention->issue_category,
                'status' => $intervention->status->value,
                'passed' => $intervention->status === PharmacyInterventionStatus::Resolved,
            ])
            ->values()
            ->all();

        return array_values($evidence);
    }

    /** @return list<array<string, mixed>> */
    private function blockingTaskEvidence(Encounter $encounter): array
    {
        $evidence = WorkTask::query()
            ->where('encounter_id', $encounter->getKey())
            ->whereIn('task_type', [
                WorkTaskType::ResultAcknowledgement->value,
                WorkTaskType::PharmacyReview->value,
                WorkTaskType::PrescriptionInterventionResponse->value,
                WorkTaskType::Dispensing->value,
            ])
            ->whereNotIn('status', [WorkTaskStatus::Complete->value, WorkTaskStatus::Cancelled->value])
            ->orderBy('priority')
            ->get()
            ->map(fn (WorkTask $task): array => [
                'publicId' => $task->public_id,
                'label' => $task->title,
                'type' => $task->task_type->value,
                'status' => $task->status->value,
            ])
            ->values()
            ->all();

        return array_values($evidence);
    }

    /** @return array<string, mixed> */
    private function clinicalVersionEvidence(ClinicalEntryVersion $version): array
    {
        return [
            'publicId' => $version->public_id,
            'versionNumber' => $version->version_number,
            'schemaVersion' => $version->schema_version,
            'status' => $version->status->value,
            'contentHash' => $version->content_hash,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $evidence
     * @return array{code: string, label: string, passed: bool, blocking: bool, detail: string, evidence: list<array<string, mixed>>}
     */
    private function check(
        string $code,
        string $label,
        bool $passed,
        string $passedDetail,
        string $failedDetail,
        array $evidence,
    ): array {
        return [
            'code' => $code,
            'label' => $label,
            'passed' => $passed,
            'blocking' => ! $passed,
            'detail' => $passed ? $passedDetail : $failedDetail,
            'evidence' => $evidence,
        ];
    }
}
