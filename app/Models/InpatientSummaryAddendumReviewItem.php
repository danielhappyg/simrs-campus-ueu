<?php

namespace App\Models;

use App\Support\Inpatient\InpatientSummaryAddendumMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $review_id
 * @property string $item_code
 * @property string $label
 * @property bool $is_blocking
 * @property bool $is_complete
 * @property string|null $source_reference
 */
class InpatientSummaryAddendumReviewItem extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const UPDATED_AT = null;

    protected $fillable = ['review_id', 'item_code', 'label', 'is_blocking', 'is_complete', 'source_reference'];

    protected static function booted(): void
    {
        static::creating(static fn () => InpatientSummaryAddendumMutationScope::assertActive());
        static::updating(static fn () => throw new LogicException('Summary addendum review items are immutable.'));
        static::deleting(static fn () => throw new LogicException('Summary addendum review items cannot be deleted ordinarily.'));
    }

    protected function casts(): array
    {
        return ['is_blocking' => 'boolean', 'is_complete' => 'boolean'];
    }
}
