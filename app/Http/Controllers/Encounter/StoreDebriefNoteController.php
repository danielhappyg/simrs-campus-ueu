<?php

namespace App\Http\Controllers\Encounter;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teaching\StoreDebriefNoteRequest;
use App\Models\User;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Teaching\Enums\DebriefNoteType;
use App\Modules\Teaching\Exceptions\DebriefWriteConflict;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use App\Modules\Teaching\Services\DebriefNoteService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class StoreDebriefNoteController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly DebriefNoteService $noteService,
    ) {}

    public function __invoke(StoreDebriefNoteRequest $request, Encounter $encounter): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $assignment = $this->assignmentResolver->forDebriefWrite($user, $encounter);
        /** @var array<string, mixed> $payload */
        $payload = $request->validated();

        try {
            $version = $this->noteService->create(
                encounter: $encounter,
                authorAssignment: $assignment,
                requestKey: (string) $payload['request_key'],
                noteType: DebriefNoteType::from((string) $payload['note_type']),
                body: (string) $payload['body'],
            );
        } catch (DebriefWriteConflict $exception) {
            abort(409, $exception->getMessage());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
        }

        return redirect()
            ->route('encounters.debrief.show', $encounter)
            ->with('success', "Catatan debrief bersama v{$version->version_number} disimpan.");
    }
}
