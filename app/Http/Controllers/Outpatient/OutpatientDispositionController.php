<?php

namespace App\Http\Controllers\Outpatient;

use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Models\User;
use App\Support\Clinical\OutpatientDispositionDenied;
use App\Support\Clinical\OutpatientDispositionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class OutpatientDispositionController extends Controller
{
    public function __construct(private readonly OutpatientDispositionService $service) {}

    public function sign(Request $request, Encounter $encounter): RedirectResponse
    {
        $v = $request->validate(['disposition_type' => ['required', Rule::in(['KONTROL_ULANG', 'SEMBUH', 'RAWAT_INAP'])], 'expected_document_version' => ['required', 'integer', 'min:1'], 'payload' => ['required', 'array'], 'idempotency_key' => ['required', 'string', 'min:8', 'max:255']]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        try {
            $this->service->sign($encounter, $actor, $v['disposition_type'], $v['payload'], (int) $v['expected_document_version'], $v['idempotency_key']);
        } catch (OutpatientDispositionDenied $e) {
            if ($e->httpStatus === 403) {
                abort(403, __($e->getMessage()));
            }

            return back()->withErrors(['disposition' => __($e->getMessage())])->withInput();
        }

        return back()->with('success', 'Outpatient disposition signed.');
    }

    public function correct(Request $request, Encounter $encounter): RedirectResponse
    {
        $v = $request->validate(['expected_disposition_version' => ['required', 'integer', 'min:1'], 'disposition_type' => ['required', Rule::in(['KONTROL_ULANG', 'SEMBUH', 'RAWAT_INAP'])], 'expected_document_version' => ['required', 'integer', 'min:1'], 'correction_reason' => ['required', 'string', 'min:3', 'max:2000'], 'payload' => ['required', 'array'], 'idempotency_key' => ['required', 'string', 'min:8', 'max:255']]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        try {
            $this->service->correct($encounter, $actor, (int) $v['expected_disposition_version'], $v['disposition_type'], $v['payload'], (int) $v['expected_document_version'], $v['correction_reason'], $v['idempotency_key']);
        } catch (OutpatientDispositionDenied $e) {
            if ($e->httpStatus === 403) {
                abort(403, __($e->getMessage()));
            }

            return back()->withErrors(['disposition' => __($e->getMessage())])->withInput();
        }

        return back()->with('success', 'Disposition correction signed.');
    }
}
