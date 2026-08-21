<?php

namespace App\Http\Controllers\Outpatient;

use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Capability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OutpatientRmController extends Controller
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Capability::ENCOUNTER_LIST);
        Gate::authorize(Capability::RMIK_REVIEW);

        $encounters = Encounter::query()
            ->with(['patient', 'clinicalEntries'])
            ->where('care_setting', Encounter::CARE_SETTING_OUTPATIENT)
            ->where('status', Encounter::STATUS_READY_FOR_RM)
            ->orderBy('updated_at')
            ->limit(100)
            ->get()
            ->map(fn (Encounter $encounter): array => [
                'public_id' => $encounter->public_id,
                'status' => $encounter->status,
                'clinic_name' => $encounter->clinic_name,
                'payer_type' => $encounter->payer_type,
                'registered_at' => $encounter->registered_at?->toIso8601String(),
                'entry_count' => $encounter->clinicalEntries->count(),
                'patient' => [
                    'public_id' => $encounter->patient?->public_id,
                    'medical_record_number' => $encounter->patient?->medical_record_number,
                    'full_name' => $encounter->patient?->full_name,
                    'date_of_birth' => $encounter->patient?->date_of_birth?->toDateString(),
                    'sex' => $encounter->patient?->sex,
                ],
            ])
            ->all();

        return Inertia::render('rm/rawat-jalan', [
            'encounters' => $encounters,
            'canComplete' => $request->user()?->canCapability(Capability::RMIK_REVIEW) ?? false,
        ]);
    }

    public function complete(Request $request, Encounter $encounter): RedirectResponse
    {
        Gate::authorize(Capability::RMIK_REVIEW);

        abort_unless(
            $encounter->care_setting === Encounter::CARE_SETTING_OUTPATIENT,
            404,
        );

        abort_unless(
            $encounter->status === Encounter::STATUS_READY_FOR_RM,
            422,
            'Kunjungan belum siap untuk penutupan RM.',
        );

        $user = $request->user();
        assert($user !== null);

        $encounter->update(['status' => Encounter::STATUS_CLOSED]);

        $this->auditRecorder->record(
            action: 'rmik.review.complete',
            resourceType: 'encounter',
            resourceId: $encounter->public_id,
            actor: $user,
            outcome: 'SUCCESS',
            metadata: [
                'previous_status' => Encounter::STATUS_READY_FOR_RM,
                'new_status' => Encounter::STATUS_CLOSED,
            ],
        );

        return redirect()
            ->route('rm.rawat-jalan.index')
            ->with('success', 'Rekam medis rawat jalan ditutup.');
    }
}
