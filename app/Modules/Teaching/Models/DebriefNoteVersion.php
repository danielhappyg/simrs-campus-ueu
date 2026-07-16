<?php

namespace App\Modules\Teaching\Models;

use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $debrief_note_id
 * @property int $authored_by_assignment_id
 * @property string $request_key
 * @property int $version_number
 * @property string $body
 * @property string $content_hash
 * @property string|null $change_reason
 * @property CarbonImmutable $authored_at
 * @property-read DebriefNote $note
 * @property-read Assignment $authorAssignment
 */
class DebriefNoteVersion extends Model
{
    use HasPublicUlid;

    public $timestamps = false;

    protected $fillable = [
        'debrief_note_id',
        'authored_by_assignment_id',
        'request_key',
        'version_number',
        'body',
        'content_hash',
        'change_reason',
        'authored_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $version): void {
            $note = DebriefNote::query()->with('encounter.session')->find($version->debrief_note_id);
            $assignment = Assignment::query()->active()->find($version->authored_by_assignment_id);
            $latestVersion = self::query()
                ->where('debrief_note_id', $version->debrief_note_id)
                ->max('version_number');
            $expectedVersion = ((int) $latestVersion) + 1;
            $body = trim($version->body);

            if (! $note
                || ! $assignment
                || $note->encounter->status !== EncounterStatus::Finalized
                || $note->encounter->environment_mode !== EnvironmentMode::Simulation
                || $note->encounter->session->status !== SessionStatus::Active
                || $assignment->session_id !== $note->encounter->session_id
                || ! $assignment->hasCapability(Capability::DebriefWrite)
                || ! DebriefNote::assignmentMatchesEncounter($assignment, $note->encounter)
                || $version->version_number !== $expectedVersion
                || $body === ''
                || mb_strlen($body) > 4000
                || ! hash_equals(hash('sha256', $body), $version->content_hash)
                || ($version->version_number === 1 && $version->change_reason !== null)
                || ($version->version_number > 1 && trim((string) $version->change_reason) === '')) {
                throw new DomainException('A debrief note version must preserve its author, case, sequence, content hash, and revision reason.');
            }
        });

        static::updating(function (): never {
            throw new DomainException('Debrief note versions are immutable.');
        });

        static::deleting(function (): never {
            throw new DomainException('Debrief note versions cannot be deleted.');
        });
    }

    /** @return BelongsTo<DebriefNote, $this> */
    public function note(): BelongsTo
    {
        return $this->belongsTo(DebriefNote::class, 'debrief_note_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function authorAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'authored_by_assignment_id');
    }

    protected function casts(): array
    {
        return [
            'authored_at' => 'immutable_datetime',
        ];
    }
}
