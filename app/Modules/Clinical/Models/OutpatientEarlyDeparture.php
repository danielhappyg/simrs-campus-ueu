<?php

namespace App\Modules\Clinical\Models;

use App\Models\User;
use App\Modules\Clinical\Enums\ClinicalDocumentType;
use App\Modules\Clinical\Enums\OutpatientEarlyDepartureOutcome;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Models\SyntheticPatient;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use App\Support\CanonicalJson;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property string $request_key
 * @property int $session_id
 * @property int $patient_id
 * @property int $encounter_id
 * @property int $actor_user_id
 * @property int $actor_assignment_id
 * @property OutpatientEarlyDepartureOutcome $outcome
 * @property EncounterStatus $source_encounter_status
 * @property array<string, mixed> $source_snapshot
 * @property string $source_snapshot_hash
 * @property string $stated_reason
 * @property string $communication_summary
 * @property CarbonImmutable $occurred_at
 */
class OutpatientEarlyDeparture extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'request_key',
        'session_id',
        'patient_id',
        'encounter_id',
        'actor_user_id',
        'actor_assignment_id',
        'outcome',
        'source_encounter_status',
        'source_snapshot',
        'source_snapshot_hash',
        'stated_reason',
        'communication_summary',
        'occurred_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $departure): void {
            $encounter = Encounter::query()->find($departure->encounter_id);
            $actor = Assignment::query()->active()->find($departure->actor_assignment_id);
            $allowedSources = [
                EncounterStatus::InIntake,
                EncounterStatus::WaitingClinician,
                EncounterStatus::InConsultation,
                EncounterStatus::AwaitingResult,
                EncounterStatus::AwaitingPharmacy,
                EncounterStatus::ClosurePending,
            ];
            $matchesCaseSupervisor = $actor
                && $encounter
                && $actor->patient_id === $encounter->patient_id
                && $actor->encounter_id === $encounter->getKey()
                && $actor->hasCapability(Capability::SupervisionReview);
            $sessionWideFacilitator = $actor
                && $actor->patient_id === null
                && $actor->encounter_id === null
                && $actor->hasCapability(Capability::SessionFacilitate);
            $snapshotStatus = data_get($departure->source_snapshot, 'encounterStatus');

            if (! $encounter
                || ! $actor
                || $departure->session_id !== $encounter->session_id
                || $departure->patient_id !== $encounter->patient_id
                || $departure->actor_user_id !== $actor->user_id
                || $actor->session_id !== $encounter->session_id
                || ! $actor->hasCapability(Capability::EarlyDepartureRecord)
                || (! $matchesCaseSupervisor && ! $sessionWideFacilitator)
                || ! in_array($departure->source_encounter_status, $allowedSources, true)
                || $encounter->status !== $departure->source_encounter_status
                || $snapshotStatus !== $departure->source_encounter_status->value
                || ! is_array(data_get($departure->source_snapshot, 'clinicalSources'))
                || ! self::snapshotSourcesMatch(
                    $encounter,
                    data_get($departure->source_snapshot, 'clinicalSources'),
                )
                || ! hash_equals(
                    hash('sha256', CanonicalJson::encode($departure->source_snapshot)),
                    $departure->source_snapshot_hash,
                )) {
                throw new DomainException('An early departure must match its active human actor, eligible source state, case context, and source snapshot hash.');
            }
        });

        static::updating(function (): never {
            throw new DomainException('Outpatient early departures are append-only and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new DomainException('Outpatient early departures are append-only and cannot be deleted.');
        });
    }

    private static function snapshotSourcesMatch(Encounter $encounter, mixed $sources): bool
    {
        if (! is_array($sources)) {
            return false;
        }

        $expectedVersionPublicIds = ClinicalEntry::query()
            ->with('latestVersion')
            ->where('encounter_id', $encounter->getKey())
            ->get()
            ->map(fn (ClinicalEntry $entry): ?string => $entry->latestVersion?->public_id)
            ->filter()
            ->sort()
            ->values()
            ->all();
        $providedVersionPublicIds = collect($sources)
            ->map(fn (mixed $source): mixed => is_array($source) ? ($source['versionPublicId'] ?? null) : null)
            ->filter(fn (mixed $value): bool => is_string($value))
            ->sort()
            ->values()
            ->all();

        if ($expectedVersionPublicIds !== $providedVersionPublicIds) {
            return false;
        }

        $seen = [];

        foreach ($sources as $source) {
            if (! is_array($source)) {
                return false;
            }

            $documentType = ClinicalDocumentType::tryFrom((string) ($source['documentType'] ?? ''));
            $entryPublicId = $source['entryPublicId'] ?? null;
            $versionPublicId = $source['versionPublicId'] ?? null;
            $versionNumber = $source['versionNumber'] ?? null;
            $status = $source['status'] ?? null;
            $contentHash = $source['contentHash'] ?? null;

            if (! $documentType
                || ! is_string($entryPublicId)
                || ! is_string($versionPublicId)
                || ! is_int($versionNumber)
                || ! is_string($status)
                || ! is_string($contentHash)
                || isset($seen[$versionPublicId])) {
                return false;
            }

            $matches = ClinicalEntryVersion::query()
                ->where('public_id', $versionPublicId)
                ->where('version_number', $versionNumber)
                ->where('status', $status)
                ->where('content_hash', $contentHash)
                ->whereHas('clinicalEntry', fn ($query) => $query
                    ->where('encounter_id', $encounter->getKey())
                    ->where('public_id', $entryPublicId)
                    ->where('document_type', $documentType))
                ->exists();

            if (! $matches) {
                return false;
            }

            $seen[$versionPublicId] = true;
        }

        return true;
    }

    /** @return BelongsTo<SimulationSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(SimulationSession::class);
    }

    /** @return BelongsTo<SyntheticPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(SyntheticPatient::class);
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function actorAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'actor_assignment_id');
    }

    protected function casts(): array
    {
        return [
            'outcome' => OutpatientEarlyDepartureOutcome::class,
            'source_encounter_status' => EncounterStatus::class,
            'source_snapshot' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
