<?php

namespace App\Models;

use App\Support\Inpatient\InpatientRmMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $inpatient_rm_completeness_review_id
 * @property string $item_code
 * @property string $label
 * @property bool $is_blocking
 * @property bool $is_complete
 * @property string|null $source_reference
 */
class InpatientRmCompletenessItem extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'inpatient_rm_completeness_review_id', 'item_code', 'label',
        'is_blocking', 'is_complete', 'source_reference',
    ];

    protected static function booted(): void
    {
        static::creating(static fn () => InpatientRmMutationScope::assertActive());
        static::updating(static function (): never {
            throw new LogicException('Inpatient RMIK completeness items are immutable evidence.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Inpatient RMIK completeness items cannot be deleted by ordinary workflow.');
        });
    }

    /** @return BelongsTo<InpatientRmCompletenessReview, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(InpatientRmCompletenessReview::class, 'inpatient_rm_completeness_review_id');
    }

    protected function casts(): array
    {
        return ['is_blocking' => 'boolean', 'is_complete' => 'boolean'];
    }
}
