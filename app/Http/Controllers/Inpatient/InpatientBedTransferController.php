<?php

namespace App\Http\Controllers\Inpatient;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Inpatient\InpatientBedTransferAuditUnavailable;
use App\Support\Inpatient\InpatientBedTransferDenied;
use App\Support\Inpatient\InpatientBedTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class InpatientBedTransferController extends Controller
{
    public function __construct(
        private readonly InpatientBedTransferService $service,
    ) {}

    public function __invoke(Request $request, string $encounter): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        try {
            $this->service->authorizeActor($actor);
        } catch (InpatientBedTransferAuditUnavailable $failure) {
            if ($request->header('X-Inertia') === 'true') {
                return back()->withErrors(['transfer' => $failure->getMessage()]);
            }
            abort(503, $failure->getMessage());
        }
        try {
            $unknown = array_values(array_diff(array_keys($request->except('_token')), [
                'expected_location_sequence', 'expected_source_bed_public_id', 'target_bed_public_id', 'reason', 'idempotency_key',
            ]));
            if ($unknown !== []) {
                throw ValidationException::withMessages(['transfer' => 'Permintaan memuat atribut yang tidak didukung.']);
            }
            $input = $request->all();
            $input['idempotency_key'] ??= $request->header('Idempotency-Key');
            $data = Validator::make($input, [
                'expected_location_sequence' => ['required', 'integer', 'min:0'],
                'expected_source_bed_public_id' => ['required', 'string', 'size:26', 'regex:/\A[0-9A-HJKMNP-TV-Z]{26}\z/i'],
                'target_bed_public_id' => ['required', 'string', 'size:26', 'regex:/\A[0-9A-HJKMNP-TV-Z]{26}\z/i'],
                'reason' => ['required', 'string'],
                'idempotency_key' => ['required', 'string', 'min:8', 'max:255', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/'],
            ])->validate();
        } catch (ValidationException $validation) {
            try {
                $this->service->recordValidationDenial($actor, $encounter);
            } catch (InpatientBedTransferAuditUnavailable $failure) {
                if ($request->header('X-Inertia') === 'true') {
                    return back()->withErrors(['transfer' => $failure->getMessage()]);
                }
                abort(503, $failure->getMessage());
            }
            throw $validation;
        }

        try {
            $result = $this->service->transfer($encounter, $actor, (int) $data['expected_location_sequence'],
                $data['expected_source_bed_public_id'], $data['target_bed_public_id'], $data['reason'],
                $data['idempotency_key'], $request->attributes->get('request_id'));
        } catch (InpatientBedTransferDenied $denial) {
            if ($request->header('X-Inertia') === 'true') {
                return back()->withErrors(['transfer' => $denial->getMessage()]);
            }
            abort($denial->status, $denial->getMessage());
        } catch (InpatientBedTransferAuditUnavailable $failure) {
            if ($request->header('X-Inertia') === 'true') {
                return back()->withErrors(['transfer' => $failure->getMessage()]);
            }
            abort(503, $failure->getMessage());
        }

        return redirect()->route('pemeriksaan.rawat-inap.show', $encounter)
            ->with('success', $result->replayed ? 'Transfer tempat tidur sudah tercatat.' : 'Transfer tempat tidur berhasil.');
    }
}
