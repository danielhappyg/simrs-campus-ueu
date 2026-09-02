<?php

namespace App\Models;

use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $review_id
 * @property string $item_code
 * @property string $label
 * @property bool $is_blocking
 * @property bool $is_complete
 * @property string|null $source_reference
 */
class OutpatientRmAmendmentReviewItem extends Model
{
    use UsesSchemaQualifiedTable;

    protected $fillable = [
        'review_id', 'item_code', 'label', 'is_blocking', 'is_complete', 'source_reference',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $item): void {
            OutpatientRmAmendmentReview::assertExpectedItemCreation($item);
        });
        static::updating(static function (): never {
            throw new LogicException('Outpatient amendment review items are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Outpatient amendment review items cannot be deleted by ordinary workflow.');
        });
    }

    /** @return BelongsTo<OutpatientRmAmendmentReview, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(OutpatientRmAmendmentReview::class, 'review_id');
    }

    protected function casts(): array
    {
        return ['is_blocking' => 'boolean', 'is_complete' => 'boolean'];
    }
}
