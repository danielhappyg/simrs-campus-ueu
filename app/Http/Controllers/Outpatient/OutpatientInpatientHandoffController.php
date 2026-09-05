<?php

namespace App\Http\Controllers\Outpatient;

use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\User;
use App\Support\Clinical\OutpatientDispositionDenied;
use App\Support\Clinical\OutpatientInpatientHandoffService;
use App\Support\Database\SchemaAwareRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class OutpatientInpatientHandoffController extends Controller
{
    public function __construct(private readonly OutpatientInpatientHandoffService $service) {}

    public function execute(Request $request, Encounter $encounter): RedirectResponse
    {
        $v = $request->validate(['expected_disposition_version' => ['required', 'integer', 'min:1'], 'bed_public_id' => ['required', 'string', 'size:26', SchemaAwareRules::exists(InpatientBed::class, 'public_id')], 'idempotency_key' => ['required', 'string', 'min:8', 'max:255']]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        try {
            $this->service->execute($encounter, $actor, (int) $v['expected_disposition_version'], $v['bed_public_id'], $v['idempotency_key']);
        } catch (OutpatientDispositionDenied $e) {
            if ($e->httpStatus === 403) {
                abort(403, __($e->getMessage()));
            }

            return back()->withErrors(['handoff' => __($e->getMessage())])->withInput();
        }

        return back()->with('success', 'Inpatient handoff completed.');
    }
}
