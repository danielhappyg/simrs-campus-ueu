<?php

namespace App\Models;

use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $item_code
 * @property string $label
 * @property bool $is_blocking
 * @property bool $is_complete
 * @property string|null $source_reference
 */
class OutpatientRmCompletenessItem extends Model
{
    use UsesSchemaQualifiedTable;

    protected $fillable = [
        'outpatient_rm_completeness_review_id', 'item_code', 'label',
        'is_blocking', 'is_complete', 'source_reference',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Outpatient RM completeness review items are immutable evidence.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Outpatient RM completeness review items cannot be deleted by ordinary workflow.');
        });
    }

    /** @return BelongsTo<OutpatientRmCompletenessReview, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(OutpatientRmCompletenessReview::class, 'outpatient_rm_completeness_review_id');
    }

    protected function casts(): array
    {
        return [
            'is_blocking' => 'boolean',
            'is_complete' => 'boolean',
        ];
    }
}
