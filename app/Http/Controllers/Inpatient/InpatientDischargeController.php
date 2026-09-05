<?php

namespace App\Http\Controllers\Inpatient;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Inpatient\InpatientDischargeDenied;
use App\Support\Inpatient\InpatientDischargeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class InpatientDischargeController extends Controller
{
    public function __construct(private readonly InpatientDischargeService $service) {}

    public function __invoke(Request $request, string $encounter): RedirectResponse
    {
        Gate::authorize(Capability::INPATIENT_DISCHARGE_EXECUTE);
        $actor = $request->user();
        assert($actor instanceof User);
        $allowed = ['expected_summary_version', 'expected_location_sequence', 'source_bed_public_id', 'idempotency_key'];
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw ValidationException::withMessages(['inpatient_discharge' => 'The discharge request contains unauthorized fields.']);
        }
        $input = $request->all();
        $input['idempotency_key'] ??= $request->header('Idempotency-Key');
        $payload = Validator::make($input, [
            'expected_summary_version' => ['required', 'integer', 'min:1'],
            'expected_location_sequence' => ['required', 'integer', 'min:0'],
            'source_bed_public_id' => ['required', 'ulid'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:255', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]+\z/'],
        ])->validate();

        try {
            $result = $this->service->execute(
                $encounter,
                $actor,
                $payload['expected_summary_version'],
                $payload['expected_location_sequence'],
                $payload['source_bed_public_id'],
                $payload['idempotency_key'],
                $request->attributes->get('request_id'),
            );
        } catch (InpatientDischargeDenied $denial) {
            if ($request->header('X-Inertia') === 'true') {
                return back()->withErrors(['inpatient_discharge' => __($denial->getMessage())]);
            }
            abort($denial->status, __($denial->getMessage()));
        }

        return redirect()->route('pemeriksaan.rawat-inap.show', $encounter)
            ->with('success', $result->replayed ? 'The discharge is already recorded.' : 'Patient discharged and bed made available.');
    }
}
