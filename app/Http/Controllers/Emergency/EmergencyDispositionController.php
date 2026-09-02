<?php

namespace App\Http\Controllers\Emergency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Emergency\Concerns\RespondsToEmergencyMutation;
use App\Models\EmergencyDisposition;
use App\Models\User;
use App\Support\Emergency\EmergencyDispositionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EmergencyDispositionController extends Controller
{
    use RespondsToEmergencyMutation;

    public function __construct(private readonly EmergencyDispositionService $dispositions) {}

    public function sign(Request $request, string $encounter): RedirectResponse
    {
        $validated = $this->validatedDisposition($request, expectedVersion: false);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $this->emergencyMutation(
            fn () => $this->dispositions->sign(
                $encounter,
                $actor,
                $validated['disposition_type'],
                $validated['payload'],
                $validated['idempotency_key'],
            ),
            'Disposisi IGD telah ditandatangani.',
        );
    }

    public function correct(Request $request, string $encounter): RedirectResponse
    {
        $validated = $this->validatedDisposition($request, expectedVersion: true);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $this->emergencyMutation(
            fn () => $this->dispositions->correctBeforeHandoff(
                $encounter,
                $actor,
                $validated['expected_disposition_version'],
                $validated['replacement_type'],
                $validated['replacement_payload'],
                $validated['reason'],
                $validated['idempotency_key'],
            ),
            'Koreksi disposisi IGD telah ditandatangani.',
        );
    }

    public function createCorrectionIntent(Request $request, string $encounter): RedirectResponse
    {
        $validated = $request->validate([
            'expected_disposition_version' => ['required', 'integer', 'min:1'],
            'replacement_type' => ['required', Rule::in(EmergencyDisposition::TYPES)],
            'replacement_payload' => ['required', 'array'],
            'replacement_payload.*' => ['required'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'expires_at' => ['required', 'date'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $this->emergencyMutation(
            fn () => $this->dispositions->createExecutedHandoffCorrectionIntent(
                $encounter,
                $actor,
                $validated['expected_disposition_version'],
                $validated['replacement_type'],
                $validated['replacement_payload'],
                $validated['reason'],
                $validated['expires_at'],
                $validated['idempotency_key'],
            ),
            'Maksud koreksi serah-terima telah ditandatangani dokter.',
        );
    }

    public function revokeCorrectionIntent(Request $request, string $intent): RedirectResponse
    {
        $validated = $request->validate([
            'expected_intent_fingerprint' => ['required', 'string', 'size:64'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $this->emergencyMutation(
            fn () => $this->dispositions->revokeCorrectionIntent(
                $intent,
                $actor,
                $validated['expected_intent_fingerprint'],
                $validated['reason'],
                $validated['idempotency_key'],
            ),
            'Maksud koreksi serah-terima telah dicabut.',
        );
    }

    /** @return array<string, mixed> */
    private function validatedDisposition(Request $request, bool $expectedVersion): array
    {
        return $request->validate([
            'expected_disposition_version' => [$expectedVersion ? 'required' : 'nullable', 'integer', $expectedVersion ? 'min:1' : 'in:0'],
            ...($expectedVersion ? [
                'replacement_type' => ['required', Rule::in(EmergencyDisposition::TYPES)],
                'replacement_payload' => ['required', 'array'],
                'replacement_payload.*' => ['required'],
                'reason' => ['required', 'string', 'min:3', 'max:2000'],
            ] : [
                'disposition_type' => ['required', Rule::in(EmergencyDisposition::TYPES)],
                'payload' => ['required', 'array'],
                'payload.*' => ['required'],
            ]),
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);
    }
}
