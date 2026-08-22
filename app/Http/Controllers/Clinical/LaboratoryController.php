<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\LabDiagnosticResult;
use App\Models\LabServiceRequest;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Capability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LaboratoryController extends Controller
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Capability::CLINICAL_LAB_RESULT_WRITE);

        $q = trim((string) $request->query('q', ''));

        $orders = [];

        try {
            $query = LabServiceRequest::query()
                ->with(['encounter.patient', 'requestedBy'])
                ->where('status', LabServiceRequest::STATUS_ACTIVE)
                ->orderBy('requested_at');

            if ($q !== '') {
                $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $query->where(function ($builder) use ($q, $like): void {
                    $builder->where('test_label', $like, '%'.$q.'%')
                        ->orWhere('test_code', $like, '%'.$q.'%')
                        ->orWhereHas('encounter.patient', function ($patientQuery) use ($q, $like): void {
                            $patientQuery->where('full_name', $like, '%'.$q.'%')
                                ->orWhere('medical_record_number', $like, '%'.$q.'%');
                        });
                });
            }

            $orders = $query
                ->limit(100)
                ->get()
                ->map(fn (LabServiceRequest $order): array => [
                    'public_id' => $order->public_id,
                    'test_code' => $order->test_code,
                    'test_label' => $order->test_label,
                    'clinical_question' => $order->clinical_question,
                    'requested_at' => $order->requested_at->toIso8601String(),
                    'requested_by_name' => $order->requestedBy?->name,
                    'encounter' => [
                        'public_id' => $order->encounter?->public_id,
                        'clinic_name' => $order->encounter?->clinic_name,
                        'status' => $order->encounter?->status,
                    ],
                    'patient' => [
                        'full_name' => $order->encounter?->patient?->full_name,
                        'medical_record_number' => $order->encounter?->patient?->medical_record_number,
                    ],
                ])
                ->all();
        } catch (\Throwable $e) {
            report($e);
        }

        return Inertia::render('pemeriksaan/laboratorium/index', [
            'orders' => $orders,
            'filters' => [
                'q' => $q,
            ],
            'canEnterResult' => $request->user()?->canCapability(Capability::CLINICAL_LAB_RESULT_WRITE) ?? false,
        ]);
    }

    public function storeResult(Request $request, LabServiceRequest $order): RedirectResponse
    {
        Gate::authorize(Capability::CLINICAL_LAB_RESULT_WRITE);

        abort_unless(
            $order->status === LabServiceRequest::STATUS_ACTIVE,
            422,
            'Order lab sudah selesai atau dibatalkan.',
        );

        abort_if(
            $order->result()->exists(),
            422,
            'Hasil lab sudah tercatat.',
        );

        $validated = $request->validate([
            'result_text' => ['required', 'string', 'max:10000'],
            'status' => ['required', Rule::in(LabDiagnosticResult::STATUS_VALUES)],
        ]);

        $user = $request->user();
        assert($user !== null);

        DB::transaction(function () use ($validated, $order, $user): void {
            LabDiagnosticResult::query()->create([
                'lab_service_request_id' => $order->id,
                'entered_by_user_id' => $user->id,
                'status' => $validated['status'],
                'result_text' => $validated['result_text'],
                'issued_at' => now(),
            ]);

            $order->update(['status' => LabServiceRequest::STATUS_COMPLETED]);
        });

        $this->auditRecorder->record(
            action: 'clinical.lab.result.write',
            resourceType: 'lab_service_request',
            resourceId: $order->public_id,
            actor: $user,
            outcome: 'SUCCESS',
            metadata: [
                'encounter_id' => $order->encounter?->public_id,
                'test_code' => $order->test_code,
                'result_status' => $validated['status'],
            ],
        );

        return redirect()
            ->route('pemeriksaan.laboratorium.index')
            ->with('success', 'Hasil lab disimpan.');
    }
}
