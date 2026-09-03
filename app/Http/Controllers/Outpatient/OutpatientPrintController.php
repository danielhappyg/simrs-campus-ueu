<?php

namespace App\Http\Controllers\Outpatient;

use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Capability;
use App\Support\TeachingVocabulary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class OutpatientPrintController extends Controller
{
    /**
     * @var list<string>
     */
    public const DOCUMENT_KEYS = ['bukti', 'antrian', 'sep', 'gelang', 'kartu', 'consent'];

    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function show(Request $request, Encounter $encounter): View
    {
        Gate::authorize(Capability::ENCOUNTER_LIST);

        $encounter->loadMissing(['patient', 'consent']);
        abort_unless($encounter->patient?->is_synthetic === true, 404);

        $requested = array_values(array_filter(
            explode(',', (string) $request->query('docs', 'bukti')),
        ));
        $documents = array_values(array_unique(array_intersect($requested, self::DOCUMENT_KEYS)));

        if ($documents === []) {
            $documents = ['bukti'];
        }

        $user = $request->user();
        assert($user !== null);

        if ($encounter->isCancelled()) {
            $event = $this->auditRecorder->record(
                action: 'encounter.print',
                resourceType: 'encounter',
                resourceId: $encounter->public_id,
                actor: $user,
                outcome: 'DENIED',
                reason: 'encounter_cancelled',
                metadata: ['documents' => $documents],
            );

            abort_if($event === null, 503, 'Penolakan cetak tidak dapat direkam dalam audit.');
            abort(409, 'Dokumen aktif tidak dapat dicetak untuk kunjungan yang telah dibatalkan.');
        }

        $event = $this->auditRecorder->record(
            action: 'encounter.print',
            resourceType: 'encounter',
            resourceId: $encounter->public_id,
            actor: $user,
            outcome: 'SUCCESS',
            metadata: [
                'documents' => $documents,
                'teaching_only' => true,
                'live_bpjs' => false,
            ],
        );

        abort_if($event === null, 503, 'Dokumen tidak dapat dicetak karena audit gagal direkam.');

        return view('prints.encounter', [
            'encounter' => $encounter,
            'documents' => $documents,
            'printedAt' => now(),
            'labels' => TeachingVocabulary::printLabels($encounter),
        ]);
    }
}
