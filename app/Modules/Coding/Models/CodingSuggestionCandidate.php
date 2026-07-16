<?php

namespace App\Modules\Coding\Models;

use App\Modules\Coding\Enums\CodingConfidenceBand;
use App\Support\Models\HasPublicUlid;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $coding_suggestion_run_id
 * @property int $rank
 * @property int $terminology_concept_id
 * @property CodingConfidenceBand $confidence_band
 * @property int $score
 * @property array<string, mixed> $evidence
 * @property string|null $specificity_warning
 * @property-read TerminologyConcept $concept
 */
class CodingSuggestionCandidate extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'coding_suggestion_run_id',
        'rank',
        'terminology_concept_id',
        'confidence_band',
        'score',
        'evidence',
        'specificity_warning',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $candidate): void {
            $run = CodingSuggestionRun::query()->find($candidate->coding_suggestion_run_id);
            $concept = TerminologyConcept::query()->find($candidate->terminology_concept_id);

            if (! $run
                || ! $concept
                || $concept->terminology_release_id !== $run->terminology_release_id
                || $candidate->rank < 1
                || $candidate->rank > 10
                || $candidate->score > 10000) {
                throw new DomainException('A coding candidate must be a bounded ranked concept from the run terminology release.');
            }
        });

        static::updating(function (): never {
            throw new DomainException('Coding suggestion candidates are immutable.');
        });

        static::deleting(function (): never {
            throw new DomainException('Coding suggestion candidates are append-only.');
        });
    }

    /** @return BelongsTo<CodingSuggestionRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(CodingSuggestionRun::class, 'coding_suggestion_run_id');
    }

    /** @return BelongsTo<TerminologyConcept, $this> */
    public function concept(): BelongsTo
    {
        return $this->belongsTo(TerminologyConcept::class, 'terminology_concept_id');
    }

    protected function casts(): array
    {
        return [
            'confidence_band' => CodingConfidenceBand::class,
            'evidence' => 'array',
        ];
    }
}
