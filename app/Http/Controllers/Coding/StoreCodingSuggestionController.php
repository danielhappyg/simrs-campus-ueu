<?php

namespace App\Http\Controllers\Coding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coding\StoreCodingSuggestionRequest;
use App\Models\User;
use App\Modules\Clinical\Models\ClinicalCondition;
use App\Modules\Coding\Services\CodingSuggestionService;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class StoreCodingSuggestionController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly CodingSuggestionService $suggestionService,
    ) {}

    public function __invoke(
        StoreCodingSuggestionRequest $request,
        Encounter $encounter,
        ClinicalCondition $condition,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        if ($condition->encounter_id !== $encounter->getKey()) {
            abort(404);
        }

        $assignment = $this->assignmentResolver->forEncounter($user, $encounter, Capability::CodingWrite);
        /** @var array<string, mixed> $payload */
        $payload = $request->validated();

        try {
            $run = $this->suggestionService->generate(
                encounter: $encounter,
                condition: $condition,
                coderAssignment: $assignment,
                requestKey: (string) $payload['request_key'],
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
        }

        return redirect()
            ->route('encounters.coding.show', ['encounter' => $encounter, 'source' => $condition->public_id])
            ->with('success', $run->candidates()->exists()
                ? 'Kandidat ICD dibuat. Setiap kandidat tetap wajib ditinjau koder.'
                : 'Tidak ada kandidat andal. Gunakan pencarian manual atau catat kebutuhan koreksi dokumentasi.');
    }
}
