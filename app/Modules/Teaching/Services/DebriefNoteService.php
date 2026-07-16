<?php

namespace App\Modules\Teaching\Services;

use App\Modules\Audit\Services\AuditRecorder;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\DebriefNoteType;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Exceptions\DebriefWriteConflict;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\DebriefNote;
use App\Modules\Teaching\Models\DebriefNoteVersion;
use App\Modules\Teaching\Models\SimulationSession;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class DebriefNoteService
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function create(
        Encounter $encounter,
        Assignment $authorAssignment,
        string $requestKey,
        DebriefNoteType $noteType,
        string $body,
    ): DebriefNoteVersion {
        return DB::transaction(function () use ($encounter, $authorAssignment, $requestKey, $noteType, $body): DebriefNoteVersion {
            [$lockedEncounter, $session, $activeAuthor] = $this->lockedContext($encounter, $authorAssignment);
            $normalizedBody = trim($body);
            $contentHash = hash('sha256', $normalizedBody);
            $existingNote = DebriefNote::query()
                ->with('versions')
                ->where('encounter_id', $lockedEncounter->getKey())
                ->where('request_key', $requestKey)
                ->lockForUpdate()
                ->first();

            if ($existingNote) {
                $version = $existingNote->versions->firstWhere('request_key', $requestKey);

                if (! $version
                    || $version->version_number !== 1
                    || $existingNote->created_by_assignment_id !== $activeAuthor->getKey()
                    || $existingNote->note_type !== $noteType
                    || ! hash_equals($version->content_hash, $contentHash)) {
                    throw new DebriefWriteConflict('The debrief request key was already used for different note content or context.');
                }

                return $version;
            }

            $note = DebriefNote::query()->create([
                'encounter_id' => $lockedEncounter->getKey(),
                'created_by_assignment_id' => $activeAuthor->getKey(),
                'request_key' => $requestKey,
                'note_type' => $noteType,
            ]);
            $authoredAt = CarbonImmutable::now();
            $version = DebriefNoteVersion::query()->create([
                'debrief_note_id' => $note->getKey(),
                'authored_by_assignment_id' => $activeAuthor->getKey(),
                'request_key' => $requestKey,
                'version_number' => 1,
                'body' => $normalizedBody,
                'content_hash' => $contentHash,
                'change_reason' => null,
                'authored_at' => $authoredAt,
            ]);

            $this->auditRecorder->record(
                action: 'debrief.note_created',
                resourceType: 'debrief_note_version',
                resourceId: $version->public_id,
                actor: $activeAuthor->user,
                assignment: $activeAuthor,
                session: $session,
                encounter: $lockedEncounter,
                metadata: [
                    'note_public_id' => $note->public_id,
                    'note_type' => $noteType->value,
                    'version_number' => 1,
                    'content_hash' => $contentHash,
                ],
            );

            return $version->load(['note', 'authorAssignment.user']);
        });
    }

    public function revise(
        DebriefNote $note,
        Assignment $authorAssignment,
        string $requestKey,
        string $body,
        string $changeReason,
    ): DebriefNoteVersion {
        return DB::transaction(function () use ($note, $authorAssignment, $requestKey, $body, $changeReason): DebriefNoteVersion {
            $lockedNote = DebriefNote::query()
                ->whereKey($note->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $encounter = Encounter::query()->findOrFail($lockedNote->encounter_id);
            [$lockedEncounter, $session, $activeAuthor] = $this->lockedContext($encounter, $authorAssignment);
            $normalizedBody = trim($body);
            $normalizedReason = trim($changeReason);
            $contentHash = hash('sha256', $normalizedBody);
            $existingVersion = DebriefNoteVersion::query()
                ->where('debrief_note_id', $lockedNote->getKey())
                ->where('request_key', $requestKey)
                ->lockForUpdate()
                ->first();

            if ($existingVersion) {
                if ($existingVersion->authored_by_assignment_id !== $activeAuthor->getKey()
                    || ! hash_equals($existingVersion->content_hash, $contentHash)
                    || ! hash_equals((string) $existingVersion->change_reason, $normalizedReason)) {
                    throw new DebriefWriteConflict('The debrief revision request key was already used for different content or context.');
                }

                return $existingVersion;
            }

            $latest = DebriefNoteVersion::query()
                ->where('debrief_note_id', $lockedNote->getKey())
                ->orderByDesc('version_number')
                ->lockForUpdate()
                ->firstOrFail();

            if (hash_equals($latest->content_hash, $contentHash)) {
                throw new DebriefWriteConflict('The revised debrief note must differ from the current version.');
            }

            $version = DebriefNoteVersion::query()->create([
                'debrief_note_id' => $lockedNote->getKey(),
                'authored_by_assignment_id' => $activeAuthor->getKey(),
                'request_key' => $requestKey,
                'version_number' => $latest->version_number + 1,
                'body' => $normalizedBody,
                'content_hash' => $contentHash,
                'change_reason' => $normalizedReason,
                'authored_at' => CarbonImmutable::now(),
            ]);

            $this->auditRecorder->record(
                action: 'debrief.note_revised',
                resourceType: 'debrief_note_version',
                resourceId: $version->public_id,
                actor: $activeAuthor->user,
                assignment: $activeAuthor,
                session: $session,
                encounter: $lockedEncounter,
                metadata: [
                    'note_public_id' => $lockedNote->public_id,
                    'note_type' => $lockedNote->note_type->value,
                    'version_number' => $version->version_number,
                    'supersedes_version_public_id' => $latest->public_id,
                    'content_hash' => $contentHash,
                    'change_reason_recorded' => true,
                ],
            );

            return $version->load(['note', 'authorAssignment.user']);
        });
    }

    /** @return array{Encounter, SimulationSession, Assignment} */
    private function lockedContext(Encounter $encounter, Assignment $authorAssignment): array
    {
        $lockedEncounter = Encounter::query()
            ->with('session')
            ->whereKey($encounter->getKey())
            ->lockForUpdate()
            ->firstOrFail();
        $session = SimulationSession::query()
            ->whereKey($lockedEncounter->session_id)
            ->lockForUpdate()
            ->firstOrFail();
        $activeAuthor = Assignment::query()
            ->with('user')
            ->active()
            ->whereKey($authorAssignment->getKey())
            ->lockForUpdate()
            ->first();

        if ($lockedEncounter->status !== EncounterStatus::Finalized) {
            throw new DebriefWriteConflict('Debrief notes are available only after encounter finalization.');
        }

        if ($session->environment_mode !== EnvironmentMode::Simulation
            || $session->status !== SessionStatus::Active) {
            throw new DebriefWriteConflict('Debrief notes can be authored only while the finalized simulation session is active.');
        }

        if (! $activeAuthor
            || $activeAuthor->session_id !== $session->getKey()
            || ! $activeAuthor->hasCapability(Capability::DebriefWrite)
            || ! DebriefNote::assignmentMatchesEncounter($activeAuthor, $lockedEncounter)) {
            throw new DomainException('The active assignment does not permit debrief authorship for this encounter.');
        }

        return [$lockedEncounter, $session, $activeAuthor];
    }
}
