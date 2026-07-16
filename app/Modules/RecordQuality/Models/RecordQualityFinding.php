<?php

namespace App\Modules\RecordQuality\Models;

use App\Modules\Clinical\Models\EncounterClosure;
use App\Modules\RecordQuality\Enums\RecordQualityFindingSeverity;
use App\Modules\Teaching\Models\Assignment;
use App\Support\Models\HasPublicUlid;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $public_id
 * @property int $record_quality_review_id
 * @property int $sequence_number
 * @property string $code
 * @property RecordQualityFindingSeverity $severity
 * @property string $message
 * @property string $affected_resource_type
 * @property string $affected_resource_public_id
 * @property int $affected_version_number
 * @property string $affected_content_hash
 * @property int $responsible_assignment_id
 * @property string $requested_action
 */
class RecordQualityFinding extends Model
{
    use HasPublicUlid;

    public const AFFECTED_ENCOUNTER_CLOSURE = 'ENCOUNTER_CLOSURE';

    protected $fillable = [
        'record_quality_review_id',
        'sequence_number',
        'code',
        'severity',
        'message',
        'affected_resource_type',
        'affected_resource_public_id',
        'affected_version_number',
        'affected_content_hash',
        'responsible_assignment_id',
        'requested_action',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $finding): void {
            $review = RecordQualityReview::query()->find($finding->record_quality_review_id);
            $closure = EncounterClosure::query()->where('public_id', $finding->affected_resource_public_id)->first();
            $responsible = Assignment::query()->active()->find($finding->responsible_assignment_id);

            if (! $review
                || $finding->affected_resource_type !== self::AFFECTED_ENCOUNTER_CLOSURE
                || ! $closure
                || ! $responsible
                || $closure->encounter_id !== $review->encounter_id
                || $closure->version_number !== $finding->affected_version_number
                || ! hash_equals($closure->content_hash, $finding->affected_content_hash)
                || $closure->author_assignment_id !== $responsible->getKey()) {
                throw new DomainException('A record-quality finding must reference an exact immutable closure version and responsible author.');
            }
        });

        static::updating(function (): never {
            throw new DomainException('Record-quality findings are immutable.');
        });

        static::deleting(function (): never {
            throw new DomainException('Record-quality findings are append-only.');
        });
    }

    /** @return BelongsTo<RecordQualityReview, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(RecordQualityReview::class, 'record_quality_review_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function responsibleAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'responsible_assignment_id');
    }

    /** @return HasOne<RecordCorrectionRequest, $this> */
    public function correctionRequest(): HasOne
    {
        return $this->hasOne(RecordCorrectionRequest::class);
    }

    protected function casts(): array
    {
        return [
            'severity' => RecordQualityFindingSeverity::class,
        ];
    }
}
