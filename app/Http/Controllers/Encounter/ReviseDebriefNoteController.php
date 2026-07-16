<?php

namespace App\Http\Controllers\Encounter;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teaching\ReviseDebriefNoteRequest;
use App\Models\User;
use App\Modules\Teaching\Exceptions\DebriefWriteConflict;
use App\Modules\Teaching\Models\DebriefNote;
use App\Modules\Teaching\Services\AssignmentContextResolver;
use App\Modules\Teaching\Services\DebriefNoteService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class ReviseDebriefNoteController extends Controller
{
    public function __construct(
        private readonly AssignmentContextResolver $assignmentResolver,
        private readonly DebriefNoteService $noteService,
    ) {}

    public function __invoke(ReviseDebriefNoteRequest $request, DebriefNote $note): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $note->load('encounter.session');
        $assignment = $this->assignmentResolver->forDebriefWrite($user, $note->encounter);
        /** @var array<string, mixed> $payload */
        $payload = $request->validated();

        try {
            $version = $this->noteService->revise(
                note: $note,
                authorAssignment: $assignment,
                requestKey: (string) $payload['request_key'],
                body: (string) $payload['body'],
                changeReason: (string) $payload['change_reason'],
            );
        } catch (DebriefWriteConflict $exception) {
            abort(409, $exception->getMessage());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
        }

        return redirect()
            ->route('encounters.debrief.show', $note->encounter)
            ->with('success', "Catatan debrief diperbarui sebagai v{$version->version_number}; versi sebelumnya tetap tersimpan.");
    }
}
