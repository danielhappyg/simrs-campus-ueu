<?php

namespace App\Http\Controllers\Coding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coding\StoreCodingSuggestionRequest;
use App\Models\User;
use App\Modules\Clinical\Models\ClinicalProcedure;
use App\Modules\Coding\Services\ProcedureCodingSuggestionService;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class StoreProcedureCodingSuggestionController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly ProcedureCodingSuggestionService $suggestionService,
    ) {}

    public function __invoke(
        StoreCodingSuggestionRequest $request,
        Encounter $encounter,
        ClinicalProcedure $procedure,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        if ($procedure->encounter_id !== $encounter->getKey()) {
            abort(404);
        }

        $assignment = $this->assignmentResolver->forEncounter($user, $encounter, Capability::CodingWrite);
        /** @var array<string, mixed> $payload */
        $payload = $request->validated();

        try {
            $run = $this->suggestionService->generate(
                encounter: $encounter,
                procedure: $procedure,
                coderAssignment: $assignment,
                requestKey: (string) $payload['request_key'],
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
        }

        return redirect()
            ->route('encounters.coding.show', ['encounter' => $encounter, 'source' => $procedure->public_id])
            ->with('success', $run->candidates()->exists()
                ? 'Kandidat ICD-9-CM dibuat. Setiap kandidat tetap wajib ditinjau koder.'
                : 'Tidak ada kandidat andal. Gunakan pencarian ICD-9-CM manual atau tolak saran dengan alasan.');
    }
}
