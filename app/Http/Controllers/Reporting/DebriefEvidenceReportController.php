<?php

namespace App\Http\Controllers\Reporting;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Reporting\Services\FinalizedEncounterReportProjection;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DebriefEvidenceReportController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly FinalizedEncounterReportProjection $projection,
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
            abort(409, 'Laporan tersedia setelah encounter difinalisasi untuk simulasi.');
        }

        $report = $this->projection->debriefEvidence($encounter);
        $this->auditRecorder->record(
            action: 'report.rendered',
            resourceType: 'finalized_encounter_report',
            resourceId: $encounter->public_id,
            actor: $user,
            assignment: $assignment,
            session: $encounter->session,
            encounter: $encounter,
            metadata: [
                'report_type' => data_get($report, 'document.type'),
                'section_count' => data_get($report, 'sectionCount'),
                'source_counts' => data_get($report, 'sourceCounts'),
            ],
            request: $request,
        );

        return response()
            ->view('reports.debrief-evidence', [
                'report' => $report,
                'backUrl' => route('encounters.debrief.show', $encounter),
            ])
            ->withHeaders($this->privacyHeaders());
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
