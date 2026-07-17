<?php

namespace App\Http\Controllers\Interoperability;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Interoperability\Services\OutpatientFhirPreview;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class OutpatientInteroperabilityPreviewController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly OutpatientFhirPreview $preview,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function __invoke(Request $request, Encounter $encounter): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $encounter->load(['session']);
        $assignment = $this->assignmentResolver->forReport($user, $encounter);

        if ($encounter->status !== EncounterStatus::Finalized) {
            abort(409, 'Pratinjau interoperabilitas tersedia setelah encounter difinalisasi untuk simulasi.');
        }

        $preview = $this->preview->build($encounter);
        $validationIssueCount = count(data_get($preview, 'validation.issues', []))
            + count(data_get($preview, 'validation.structuralErrors', []));

        $this->auditRecorder->record(
            action: 'interop.preview_viewed',
            resourceType: 'interoperability_preview',
            resourceId: $encounter->public_id,
            actor: $user,
            assignment: $assignment,
            session: $encounter->session,
            encounter: $encounter,
            metadata: [
                'resource_count' => data_get($preview, 'summary.resourceCount'),
                'resource_type_counts' => data_get($preview, 'summary.resourceTypeCounts'),
                'validation_issue_count' => $validationIssueCount,
                'ready_for_transmission' => false,
            ],
            request: $request,
        );

        $response = Inertia::render('encounter/interoperability-preview', [
            ...$preview,
            'urls' => [
                'self' => route('encounters.interoperability-preview.show', $encounter),
                'back' => route('encounters.debrief.show', $encounter),
                'encounter' => route('encounters.show', $encounter),
                'timeline' => route('encounters.timeline.show', $encounter),
            ],
        ])->toResponse($request);
        $response->headers->add($this->privacyHeaders());

        return $response;
    }

    /** @return array<string, string> */
    private function privacyHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
    }
}
