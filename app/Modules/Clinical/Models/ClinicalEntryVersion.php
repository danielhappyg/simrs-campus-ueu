<?php

namespace App\Modules\Clinical\Models;

use App\Models\User;
use App\Modules\Clinical\Enums\ClinicalEntryStatus;
use App\Modules\Clinical\Enums\ClinicalSaveIntent;
use App\Modules\Teaching\Models\Assignment;
use App\Support\CanonicalJson;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $clinical_entry_id
 * @property string $public_id
 * @property string $request_key
 * @property int $version_number
 * @property ClinicalSaveIntent $creation_intent
 * @property array<string, mixed> $content
 * @property string $content_hash
 * @property ClinicalEntryStatus $status
 * @property CarbonImmutable $clinical_occurrence_at
 * @property CarbonImmutable $recorded_at
 * @property-read ClinicalEntry $clinicalEntry
 * @property-read Assignment $authorAssignment
 */
class ClinicalEntryVersion extends Model
{
    use HasPublicUlid;

    private bool $statusTransitionInProgress = false;

    protected $fillable = [
        'request_key',
        'clinical_entry_id',
        'version_number',
        'creation_intent',
        'schema_version',
        'content',
        'content_hash',
        'author_user_id',
        'author_assignment_id',
        'clinical_occurrence_at',
        'recorded_at',
        'status',
        'change_reason',
        'supersedes_version_id',
        'submitted_at',
        'last_reviewed_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $version): void {
            $entry = ClinicalEntry::query()->find($version->clinical_entry_id);
            $assignment = Assignment::query()->find($version->author_assignment_id);

            if (! $entry
                || ! $assignment
                || $version->author_user_id !== $assignment->user_id
                || $assignment->session_id !== $entry->session_id
                || $assignment->patient_id !== $entry->patient_id
                || $assignment->encounter_id !== $entry->encounter_id
                || ! $assignment->hasCapability($entry->document_type->authorCapability())
                || $version->schema_version !== $entry->document_type->schemaVersion()) {
                throw new DomainException('A clinical version must match its entry, author, assignment, schema, and case context.');
            }

            $calculatedHash = hash('sha256', CanonicalJson::encode($version->content));

            if (! hash_equals($calculatedHash, $version->content_hash)) {
                throw new DomainException('Clinical version content does not match its integrity hash.');
            }

            if ($version->exists && $version->isDirty([
                'request_key',
                'clinical_entry_id',
                'version_number',
                'creation_intent',
                'schema_version',
                'content',
                'content_hash',
                'author_user_id',
                'author_assignment_id',
                'clinical_occurrence_at',
                'recorded_at',
                'change_reason',
                'supersedes_version_id',
            ])) {
                throw new DomainException('Clinical version content and provenance are immutable.');
            }

            if ($version->exists && $version->isDirty('status') && ! $version->statusTransitionInProgress) {
                throw new DomainException('Clinical version status changes must use the documentation service.');
            }
        });

        static::deleting(function (): never {
            throw new DomainException('Clinical versions are immutable and cannot be deleted.');
        });
    }

    /** @internal Clinical lifecycle transitions are persisted by ClinicalDocumentationService. */
    public function persistStatus(
        ClinicalEntryStatus $target,
        ?CarbonImmutable $submittedAt = null,
        ?CarbonImmutable $reviewedAt = null,
    ): void {
        $allowed = match ($this->status) {
            ClinicalEntryStatus::Draft => [ClinicalEntryStatus::Submitted],
            ClinicalEntryStatus::Submitted => [ClinicalEntryStatus::ChangesRequested, ClinicalEntryStatus::Approved],
            ClinicalEntryStatus::Approved => [ClinicalEntryStatus::Amended],
            default => [],
        };

        if (! in_array($target, $allowed, true)) {
            throw new DomainException("Clinical version cannot transition from {$this->status->value} to {$target->value}.");
        }

        $this->statusTransitionInProgress = true;

        try {
            $this->status = $target;
            $this->submitted_at = $submittedAt ?? $this->submitted_at;
            $this->last_reviewed_at = $reviewedAt ?? $this->last_reviewed_at;
            $this->save();
        } finally {
            $this->statusTransitionInProgress = false;
        }
    }

    /** @return BelongsTo<ClinicalEntry, $this> */
    public function clinicalEntry(): BelongsTo
    {
        return $this->belongsTo(ClinicalEntry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function authorAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'author_assignment_id');
    }

    /** @return BelongsTo<ClinicalEntryVersion, $this> */
    public function supersedesVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_version_id');
    }

    /** @return HasMany<ClinicalReviewActionModel, $this> */
    public function reviewActions(): HasMany
    {
        return $this->hasMany(ClinicalReviewActionModel::class);
    }

    /** @return HasMany<ClinicalObservation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(ClinicalObservation::class, 'source_entry_version_id');
    }

    /** @return HasOne<AllergyAssessment, $this> */
    public function allergyAssessment(): HasOne
    {
        return $this->hasOne(AllergyAssessment::class, 'source_entry_version_id');
    }

    /** @return HasMany<ClinicalCondition, $this> */
    public function conditions(): HasMany
    {
        return $this->hasMany(ClinicalCondition::class, 'source_entry_version_id');
    }

    /** @return HasMany<ServiceRequest, $this> */
    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class, 'source_entry_version_id');
    }

    /** @return HasMany<MedicationRequest, $this> */
    public function medicationRequests(): HasMany
    {
        return $this->hasMany(MedicationRequest::class, 'source_entry_version_id');
    }

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'creation_intent' => ClinicalSaveIntent::class,
            'status' => ClinicalEntryStatus::class,
            'clinical_occurrence_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'last_reviewed_at' => 'immutable_datetime',
        ];
    }
}
