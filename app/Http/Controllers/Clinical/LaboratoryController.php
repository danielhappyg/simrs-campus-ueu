<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\LabDiagnosticResult;
use App\Models\LabServiceRequest;
use App\Support\Authorization\Capability;
use App\Support\Clinical\OutpatientLabLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LaboratoryController extends Controller
{
    public function __construct(private readonly OutpatientLabLifecycle $lifecycle) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Capability::CLINICAL_LAB_RESULT_WRITE);

        $q = trim((string) $request->query('q', ''));

        $orders = [];

        try {
            $query = LabServiceRequest::query()
                ->syntheticOnly()
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

        $validated = $request->validate([
            'result_text' => ['required', 'string', 'max:10000'],
            'status' => ['required', Rule::in([LabDiagnosticResult::STATUS_FINAL])],
        ]);

        $user = $request->user();
        assert($user !== null);

        $this->lifecycle->writeFinalLabResult(
            order: $order,
            actor: $user,
            resultText: $validated['result_text'],
        );

        return redirect()
            ->route('pemeriksaan.laboratorium.index')
            ->with('success', 'Hasil lab disimpan.');
    }
}
