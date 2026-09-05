<?php

namespace App\Http\Controllers\Emergency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Emergency\Concerns\RespondsToEmergencyMutation;
use App\Models\User;
use App\Support\Emergency\EmergencyDocumentationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EmergencyDocumentationController extends Controller
{
    use RespondsToEmergencyMutation;

    public function __construct(private readonly EmergencyDocumentationService $documents) {}

    public function saveDraft(Request $request, string $encounter, string $documentType): RedirectResponse
    {
        $validated = $request->validate([
            'definition_version' => ['required', Rule::in([EmergencyDocumentationService::DEFINITION_VERSION])],
            'expected_version' => ['required', 'integer', 'min:0'],
            'fields' => ['required', 'array'],
            'fields.*' => ['required', 'string', 'max:4000'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $this->emergencyMutation(
            fn () => $this->documents->saveDraft(
                $encounter,
                $documentType,
                $actor,
                $validated['expected_version'],
                $validated['fields'],
                $validated['idempotency_key'],
            ),
            'Emergency documentation draft saved.',
        );
    }

    public function finalize(Request $request, string $encounter, string $documentType): RedirectResponse
    {
        $validated = $request->validate([
            'definition_version' => ['required', Rule::in([EmergencyDocumentationService::DEFINITION_VERSION])],
            'expected_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $this->emergencyMutation(
            fn () => $this->documents->finalize(
                $encounter,
                $documentType,
                $actor,
                $validated['expected_version'],
                $validated['idempotency_key'],
            ),
            'Emergency documentation finalized.',
        );
    }
}
