<?php

namespace App\Modules\Coding\Models;

use App\Support\Models\HasPublicUlid;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $terminology_release_id
 * @property int $terminology_concept_id
 * @property string $rule_id
 * @property string $phrase
 * @property string $normalized_phrase
 * @property string $source
 * @property bool $active
 */
class TerminologyAlias extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'terminology_release_id',
        'terminology_concept_id',
        'rule_id',
        'phrase',
        'normalized_phrase',
        'source',
        'active',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $alias): void {
            $concept = TerminologyConcept::query()->find($alias->terminology_concept_id);

            if (! $concept
                || $concept->terminology_release_id !== $alias->terminology_release_id
                || blank($alias->rule_id)
                || blank($alias->phrase)
                || blank($alias->normalized_phrase)
                || blank($alias->source)) {
                throw new DomainException('A terminology alias must reference a concept in the same release and an attributable rule.');
            }
        });

        static::updating(function (): never {
            throw new DomainException('Terminology aliases are immutable.');
        });

        static::deleting(function (): never {
            throw new DomainException('Terminology aliases are append-only.');
        });
    }

    /** @return BelongsTo<TerminologyRelease, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(TerminologyRelease::class, 'terminology_release_id');
    }

    /** @return BelongsTo<TerminologyConcept, $this> */
    public function concept(): BelongsTo
    {
        return $this->belongsTo(TerminologyConcept::class, 'terminology_concept_id');
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
