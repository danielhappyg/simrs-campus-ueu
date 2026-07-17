<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Clinical\Enums\ClinicalDocumentType;
use App\Modules\Clinical\Enums\ClinicalEntryStatus;
use App\Modules\Clinical\Enums\OutpatientSafetyDispositionOutcome;
use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Clinical\Models\OutpatientSafetyDisposition;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Enums\IdentifierType;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class OutpatientSafetyDispositionWorkspaceController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function __invoke(Request $request, Encounter $encounter): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $encounter->load(['patient.identifiers', 'session.scenario', 'location']);
        $assignment = $this->assignmentResolver->forSafetyDisposition($user, $encounter);
        $source = ClinicalEntryVersion::query()
            ->with(['clinicalEntry', 'author', 'authorAssignment'])
            ->where('status', ClinicalEntryStatus::Approved)
            ->whereHas('clinicalEntry', fn ($query) => $query
                ->where('encounter_id', $encounter->getKey())
                ->where('document_type', ClinicalDocumentType::NursingIntake))
            ->orderByDesc('version_number')
            ->first();

        if (! $source) {
            abort(409, 'Belum ada versi asesmen awal tereskalasi yang disetujui.');
        }

        $disposition = OutpatientSafetyDisposition::query()
            ->with(['actor', 'actorAssignment'])
            ->where('encounter_id', $encounter->getKey())
            ->first();
        $canRecord = $encounter->status === EncounterStatus::Escalated && ! $disposition;
        $mrn = $encounter->patient->identifiers->firstWhere('type', IdentifierType::MedicalRecordNumber);

        $this->auditRecorder->record(
            action: 'clinical.outpatient_safety_disposition_workspace_viewed',
            resourceType: 'encounter',
            resourceId: $encounter->public_id,
            actor: $user,
            assignment: $assignment,
            session: $encounter->session,
            encounter: $encounter,
            metadata: [
                'encounter_status' => $encounter->status->value,
                'source_nursing_version_public_id' => $source->public_id,
                'disposition_recorded' => $disposition !== null,
            ],
            request: $request,
        );

        $response = Inertia::render('clinical/safety-disposition', [
            'boundary' => [
                'classification' => 'SIMULASI — DATA SINTETIS',
                'emergencyTriageClaim' => false,
                'clinicalRecommendation' => false,
            ],
            'encounter' => [
                'publicId' => $encounter->public_id,
                'number' => $encounter->encounter_number,
                'status' => [
                    'code' => $encounter->status->value,
                    'label' => $encounter->status->label(),
                ],
                'serviceType' => $encounter->service_type_display,
                'location' => $encounter->location->name,
                'periodStart' => $encounter->period_start?->toIso8601String(),
                'environmentMode' => $encounter->environment_mode->value,
            ],
            'patient' => [
                'publicId' => $encounter->patient->public_id,
                'fullName' => $encounter->patient->full_name,
                'mrn' => $mrn?->value,
                'birthDate' => $encounter->patient->birth_date->toDateString(),
                'administrativeSex' => $encounter->patient->administrative_sex->label(),
                'allergyStatus' => 'Lihat asesmen awal yang disetujui',
                'synthetic' => true,
            ],
            'session' => [
                'code' => $encounter->session->code,
                'scenarioTitle' => $encounter->session->scenario->title,
            ],
            'source' => [
                'versionPublicId' => $source->public_id,
                'versionNumber' => $source->version_number,
                'contentHash' => $source->content_hash,
                'author' => $source->author->name,
                'authorRole' => $source->authorAssignment->application_role->label(),
                'approvedAt' => $source->last_reviewed_at?->toIso8601String(),
                'safetyDecision' => data_get($source->content, 'safetyDecision'),
                'safetyResponses' => data_get($source->content, 'safetyScreenResponses', []),
                'handoffSummary' => data_get($source->content, 'handoffSummary'),
            ],
            'authorization' => [
                'assignmentPublicId' => $assignment->public_id,
                'role' => $assignment->application_role->label(),
                'canRecord' => $canRecord,
            ],
            'disposition' => $disposition ? [
                'publicId' => $disposition->public_id,
                'outcome' => [
                    'code' => $disposition->outcome->value,
                    'label' => $disposition->outcome->label(),
                ],
                'rationale' => $disposition->rationale,
                'actor' => $disposition->actor->name,
                'role' => $disposition->actorAssignment->application_role->label(),
                'occurredAt' => $disposition->occurred_at->toIso8601String(),
            ] : null,
            'formOptions' => [
                'requestKey' => $canRecord ? (string) Str::ulid() : null,
                'selectedOutcome' => null,
                'outcomes' => [
                    [
                        'code' => OutpatientSafetyDispositionOutcome::ResumeRoutineFlow->value,
                        'label' => OutpatientSafetyDispositionOutcome::ResumeRoutineFlow->label(),
                        'consequence' => 'Encounter kembali menunggu klinisi dan tugas asesmen medis menjadi siap.',
                    ],
                    [
                        'code' => OutpatientSafetyDispositionOutcome::SimulatedTransfer->value,
                        'label' => OutpatientSafetyDispositionOutcome::SimulatedTransfer->label(),
                        'consequence' => 'Encounter dicatat dialihkan dalam simulasi dan asesmen medis rutin dibatalkan.',
                    ],
                ],
            ],
            'urls' => [
                'store' => route('encounters.safety-disposition.store', $encounter),
                'encounter' => route('encounters.show', $encounter),
                'workQueue' => route('work'),
            ],
        ])->toResponse($request);
        $response->headers->add([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);

        return $response;
    }
}
