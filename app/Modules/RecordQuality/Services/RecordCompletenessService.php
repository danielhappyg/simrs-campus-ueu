<?php

namespace App\Modules\RecordQuality\Services;

use App\Modules\Clinical\Enums\EncounterClosureReviewAction;
use App\Modules\Clinical\Enums\EncounterClosureStatus;
use App\Modules\Clinical\Enums\ProcedureDocumentationState;
use App\Modules\Clinical\Models\EncounterClosure;
use App\Modules\Clinical\Services\ClosureReadinessService;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\RecordQuality\Enums\RecordQualityReviewStatus;
use App\Modules\RecordQuality\Models\RecordQualityReview;
use App\Support\CanonicalJson;

class RecordCompletenessService
{
    public const CHECKLIST_VERSION = 'OPD-COMP-v2';

    public function __construct(private readonly ClosureReadinessService $closureReadinessService) {}

    /**
     * @return array{
     *   checklistVersion: string,
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
        $closure = EncounterClosure::query()
            ->where('encounter_id', $encounter->getKey())
            ->with('reviewActions')
            ->orderByDesc('version_number')
            ->first();
        $closureReadiness = $this->closureReadinessService->evaluate($encounter);
        $currentSnapshot = $this->closureReadinessService->sourceSnapshot($encounter);
        $authored = $closure?->content['authored'] ?? null;
        $sourceSnapshot = $closure?->content['sourceSnapshot'] ?? null;
        $requiredFields = [
            'leavingCondition',
            'disposition',
            'followUpPlan',
            'educationInstructions',
            'outpatientSummary',
        ];
        $missingFields = is_array($authored)
            ? collect($requiredFields)
                ->filter(fn (string $field): bool => blank($authored[$field] ?? null))
                ->values()
                ->all()
            : $requiredFields;
        $procedureDocumentation = $closure?->content['procedureDocumentation'] ?? null;
        $procedureState = is_array($procedureDocumentation)
            ? ProcedureDocumentationState::tryFrom((string) ($procedureDocumentation['state'] ?? ''))
            : null;
        $procedures = is_array($procedureDocumentation) && is_array($procedureDocumentation['procedures'] ?? null)
            ? array_values($procedureDocumentation['procedures'])
            : [];
        $proceduresComplete = $procedures !== [] && collect($procedures)->every(
            fn (mixed $procedure): bool => is_array($procedure)
                && ($procedure['status'] ?? null) === 'COMPLETED'
                && filled($procedure['publicId'] ?? null)
                && filled($procedure['authoredText'] ?? null)
                && filled($procedure['performedStartAt'] ?? null)
                && filled($procedure['performerText'] ?? null)
                && filled($procedure['contentHash'] ?? null),
        );
        $procedureAttestationComplete = ($procedureState === ProcedureDocumentationState::NonePerformed && $procedures === [])
            || ($procedureState === ProcedureDocumentationState::ProceduresRecorded && $proceduresComplete);
        $approvedAction = $closure?->reviewActions
            ->first(fn ($action): bool => $action->action === EncounterClosureReviewAction::ApproveSimulation
                && hash_equals($closure->content_hash, $action->reviewed_content_hash));
        $provenanceMatches = is_array($sourceSnapshot)
            && data_get($sourceSnapshot, 'nursing.contentHash') === data_get($currentSnapshot, 'nursing.contentHash')
            && data_get($sourceSnapshot, 'medical.contentHash') === data_get($currentSnapshot, 'medical.contentHash');
        $readinessByCode = collect($closureReadiness['checks'])->keyBy('code');

        $checks = [
            $this->check(
                code: 'CURRENT_CLOSURE_APPROVED',
                label: 'Versi penutupan saat ini disetujui',
                passed: $closure?->status === EncounterClosureStatus::Approved,
                detail: $closure?->status === EncounterClosureStatus::Approved
                    ? 'Versi penutupan saat ini telah disetujui untuk simulasi.'
                    : 'Versi penutupan saat ini belum disetujui untuk simulasi.',
                evidence: $closure ? [[
                    'publicId' => $closure->public_id,
                    'versionNumber' => $closure->version_number,
                    'status' => $closure->status->value,
                    'contentHash' => $closure->content_hash,
                ]] : [],
            ),
            $this->check(
                code: 'CLOSURE_REQUIRED_FIELDS',
                label: 'Elemen minimum penutupan tersedia',
                passed: $missingFields === [],
                detail: $missingFields === []
                    ? 'Kondisi, disposisi, tindak lanjut, edukasi, dan ringkasan tersedia.'
                    : 'Elemen penutupan yang belum tersedia: '.implode(', ', $missingFields).'.',
                evidence: array_values(array_map(fn (string $field): array => ['field' => $field], $missingFields)),
            ),
            $this->check(
                code: 'PROCEDURE_DOCUMENTATION_ATTESTED',
                label: 'Pernyataan tindakan/prosedur eksplisit',
                passed: $procedureAttestationComplete,
                detail: $procedureAttestationComplete
                    ? ($procedureState === ProcedureDocumentationState::NonePerformed
                        ? 'Penulis menyatakan tidak ada tindakan/prosedur yang dilakukan pada encounter ini.'
                        : count($procedures).' tindakan/prosedur yang telah dilakukan memiliki sumber, waktu, pelaksana, dan hash.')
                    : 'Penutupan harus menyatakan tidak ada prosedur atau mencatat sedikitnya satu prosedur yang benar-benar telah dilakukan.',
                evidence: $this->procedureEvidence($procedures),
            ),
            $this->checkFromClosureReadiness($readinessByCode->get('CURRENT_NURSING_APPROVED'), 'RMIK_NURSING_APPROVED'),
            $this->checkFromClosureReadiness($readinessByCode->get('CURRENT_MEDICAL_APPROVED'), 'RMIK_MEDICAL_APPROVED'),
            $this->checkFromClosureReadiness($readinessByCode->get('CURRENT_RESULTS_ACKNOWLEDGED'), 'RMIK_RESULTS_ACKNOWLEDGED'),
            $this->checkFromClosureReadiness($readinessByCode->get('MEDICATION_REQUESTS_TERMINAL'), 'RMIK_MEDICATION_OUTCOMES'),
            $this->check(
                code: 'CLOSURE_SOURCE_PROVENANCE_CURRENT',
                label: 'Provenance penutupan menunjuk sumber saat ini',
                passed: $provenanceMatches,
                detail: $provenanceMatches
                    ? 'Hash asesmen awal dan medis pada penutupan sama dengan versi saat ini.'
                    : 'Hash sumber pada penutupan tidak sama dengan versi klinis saat ini.',
                evidence: [[
                    'closureNursingHash' => data_get($sourceSnapshot, 'nursing.contentHash'),
                    'currentNursingHash' => data_get($currentSnapshot, 'nursing.contentHash'),
                    'closureMedicalHash' => data_get($sourceSnapshot, 'medical.contentHash'),
                    'currentMedicalHash' => data_get($currentSnapshot, 'medical.contentHash'),
                ]],
            ),
            $this->check(
                code: 'CLOSURE_SUPERVISOR_ATTESTED',
                label: 'Supervisor menyetujui hash penutupan yang tepat',
                passed: $approvedAction !== null,
                detail: $approvedAction
                    ? 'Persetujuan supervisor terhubung ke hash versi penutupan saat ini.'
                    : 'Belum ada persetujuan supervisor untuk hash versi penutupan saat ini.',
                evidence: $approvedAction ? [[
                    'reviewActionPublicId' => $approvedAction->public_id,
                    'reviewedContentHash' => $approvedAction->reviewed_content_hash,
                ]] : [],
            ),
        ];

        return [
            'checklistVersion' => self::CHECKLIST_VERSION,
            'ready' => collect($checks)->every(fn (array $check): bool => $check['passed']),
            'checks' => $checks,
        ];
    }

    /** @return array<string, mixed> */
    public function assemblySnapshot(Encounter $encounter): array
    {
        $closure = EncounterClosure::query()
            ->where('encounter_id', $encounter->getKey())
            ->with(['author', 'authorAssignment', 'reviewActions.reviewer'])
            ->orderByDesc('version_number')
            ->first();

        return [
            'closure' => $closure ? [
                'publicId' => $closure->public_id,
                'versionNumber' => $closure->version_number,
                'schemaVersion' => $closure->schema_version,
                'status' => $closure->status->value,
                'contentHash' => $closure->content_hash,
                'content' => $closure->content,
                'author' => $closure->author->name,
                'authorAssignmentPublicId' => $closure->authorAssignment->public_id,
                'reviewActions' => $closure->reviewActions->map(fn ($action): array => [
                    'publicId' => $action->public_id,
                    'action' => $action->action->value,
                    'reviewer' => $action->reviewer->name,
                    'reviewedContentHash' => $action->reviewed_content_hash,
                    'reviewedAt' => $action->reviewed_at->toIso8601String(),
                ])->values()->all(),
            ] : null,
            'clinicalSources' => $this->closureReadinessService->sourceSnapshot($encounter),
        ];
    }

    public function approvedReviewIsCurrent(?RecordQualityReview $review, Encounter $encounter): bool
    {
        $recordedAssembly = $review?->content['assemblySnapshot'] ?? null;

        return $review?->status === RecordQualityReviewStatus::Approved
            && is_array($recordedAssembly)
            && hash_equals(
                hash('sha256', CanonicalJson::encode($recordedAssembly)),
                hash('sha256', CanonicalJson::encode($this->assemblySnapshot($encounter))),
            );
    }

    /**
     * @param  array<mixed>  $procedures
     * @return list<array<string, mixed>>
     */
    private function procedureEvidence(array $procedures): array
    {
        $evidence = [];

        foreach ($procedures as $procedure) {
            if (! is_array($procedure)) {
                continue;
            }

            $evidence[] = [
                'publicId' => $procedure['publicId'] ?? null,
                'status' => $procedure['status'] ?? null,
                'contentHash' => $procedure['contentHash'] ?? null,
            ];
        }

        return $evidence;
    }

    /**
     * @param  array<string, mixed>|null  $source
     * @return array{code: string, label: string, passed: bool, blocking: bool, detail: string, evidence: list<array<string, mixed>>}
     */
    private function checkFromClosureReadiness(?array $source, string $code): array
    {
        if ($source === null) {
            return $this->check(
                code: $code,
                label: 'Pemeriksaan sumber klinis',
                passed: false,
                detail: 'Pemeriksaan sumber klinis tidak tersedia.',
                evidence: [],
            );
        }

        /** @var list<array<string, mixed>> $evidence */
        $evidence = is_array($source['evidence'] ?? null) ? array_values($source['evidence']) : [];

        return $this->check(
            code: $code,
            label: (string) ($source['label'] ?? 'Pemeriksaan sumber klinis'),
            passed: (bool) ($source['passed'] ?? false),
            detail: (string) ($source['detail'] ?? ''),
            evidence: $evidence,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $evidence
     * @return array{code: string, label: string, passed: bool, blocking: bool, detail: string, evidence: list<array<string, mixed>>}
     */
    private function check(string $code, string $label, bool $passed, string $detail, array $evidence): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'passed' => $passed,
            'blocking' => ! $passed,
            'detail' => $detail,
            'evidence' => $evidence,
        ];
    }
}
