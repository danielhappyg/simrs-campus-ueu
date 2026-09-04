<?php

namespace App\Http\Controllers\Outpatient;

use App\Http\Controllers\Controller;
use App\Support\Authorization\Capability;
use App\Support\Clinical\OutpatientLifecycleDenial;
use App\Support\Clinical\OutpatientTerminologyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class OutpatientTerminologyController extends Controller
{
    public function __invoke(Request $request, OutpatientTerminologyService $terminology): JsonResponse
    {
        Gate::authorize(Capability::CLINICAL_MEDICAL_WRITE);
        $validated = $request->validate(['system' => ['required', Rule::in(['ICD-10', 'ICD-9-CM'])], 'q' => ['required', 'string', 'min:2', 'max:100']]);

        try {
            return response()->json($terminology->search($validated['system'], trim($validated['q'])));
        } catch (OutpatientLifecycleDenial $denial) {
            return response()->json([
                'message' => $denial->getMessage(),
                'reason' => $denial->reason,
            ], $denial->status);
        }
    }
}
