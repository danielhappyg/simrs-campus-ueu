<?php

namespace App\Modules\Coding\Models;

use App\Models\User;
use App\Modules\Coding\Enums\TerminologyProvenanceStatus;
use App\Modules\Coding\Enums\TerminologyReleaseStatus;
use App\Modules\Coding\Enums\TerminologySystem;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Models\Assignment;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property string $request_key
 * @property TerminologySystem $classification_system
 * @property string $logical_version
 * @property TerminologyReleaseStatus $status
 * @property string $source_filename
 * @property string $source_sha256
 * @property string $sheet_name
 * @property TerminologyProvenanceStatus $source_provenance_status
 * @property int $imported_by_user_id
 * @property int $imported_by_assignment_id
 * @property int $row_count
 * @property int $ignored_blank_rows
 * @property array<string, mixed> $validation_report
 * @property CarbonImmutable $imported_at
 * @property CarbonImmutable|null $activated_at
 * @property int|null $supersedes_release_id
 */
class TerminologyRelease extends Model
{
    use HasPublicUlid;

    private bool $lifecycleTransitionInProgress = false;

    protected $fillable = [
        'request_key',
        'classification_system',
        'logical_version',
        'status',
        'source_filename',
        'source_sha256',
        'sheet_name',
        'source_provenance_status',
        'imported_by_user_id',
        'imported_by_assignment_id',
        'row_count',
        'ignored_blank_rows',
        'validation_report',
        'imported_at',
        'activated_by_user_id',
        'activated_by_assignment_id',
        'activated_at',
        'supersedes_release_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $release): void {
            $assignment = Assignment::query()->active()->find($release->imported_by_assignment_id);

            if (! $assignment
                || $release->imported_by_user_id !== $assignment->user_id
                || ! $assignment->hasCapability(Capability::TerminologyManage)
                || $release->status !== TerminologyReleaseStatus::Imported
                || $release->logical_version !== $release->classification_system->logicalVersion()
                || $release->sheet_name !== $release->classification_system->expectedSheetName()
                || preg_match('/^[a-f0-9]{64}$/', $release->source_sha256) !== 1
                || $release->row_count < 1) {
                throw new DomainException('A terminology release must preserve an authorized import, expected workbook contract, and source checksum.');
            }
        });

        static::updating(function (self $release): void {
            if (! $release->lifecycleTransitionInProgress) {
                throw new DomainException('Terminology releases are immutable outside the activation lifecycle.');
            }

            $immutable = [
                'request_key',
                'classification_system',
                'logical_version',
                'source_filename',
                'source_sha256',
                'sheet_name',
                'source_provenance_status',
                'imported_by_user_id',
                'imported_by_assignment_id',
                'row_count',
                'ignored_blank_rows',
                'validation_report',
                'imported_at',
            ];

            if ($release->isDirty($immutable)) {
                throw new DomainException('Terminology release source and validation provenance are immutable.');
            }
        });

        static::deleting(function (): never {
            throw new DomainException('Terminology releases are append-only.');
        });
    }

    public function persistActivation(Assignment $actor, ?self $superseded): void
    {
        if ($this->status !== TerminologyReleaseStatus::Imported
            || ! $actor->hasCapability(Capability::TerminologyManage)) {
            throw new DomainException('Only an imported release can be activated by terminology management.');
        }

        $this->lifecycleTransitionInProgress = true;

        try {
            $this->forceFill([
                'status' => TerminologyReleaseStatus::Active,
                'activated_by_user_id' => $actor->user_id,
                'activated_by_assignment_id' => $actor->getKey(),
                'activated_at' => now(),
                'supersedes_release_id' => $superseded?->getKey(),
            ])->save();
        } finally {
            $this->lifecycleTransitionInProgress = false;
        }
    }

    public function persistSuperseded(): void
    {
        if ($this->status !== TerminologyReleaseStatus::Active) {
            throw new DomainException('Only an active terminology release can be superseded.');
        }

        $this->lifecycleTransitionInProgress = true;

        try {
            $this->forceFill(['status' => TerminologyReleaseStatus::Superseded])->save();
        } finally {
            $this->lifecycleTransitionInProgress = false;
        }
    }

    /** @return BelongsTo<User, $this> */
    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by_user_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function importedByAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'imported_by_assignment_id');
    }

    /** @return HasMany<TerminologyConcept, $this> */
    public function concepts(): HasMany
    {
        return $this->hasMany(TerminologyConcept::class);
    }

    /** @return HasMany<TerminologyAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(TerminologyAlias::class);
    }

    protected function casts(): array
    {
        return [
            'classification_system' => TerminologySystem::class,
            'status' => TerminologyReleaseStatus::class,
            'source_provenance_status' => TerminologyProvenanceStatus::class,
            'validation_report' => 'array',
            'imported_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
        ];
    }
}
