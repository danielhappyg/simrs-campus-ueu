<?php

namespace App\Models;

use App\Support\Inpatient\InpatientSummaryAddendumMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $correction_request_id
 * @property int $actor_user_id
 * @property int $version
 * @property string $request_state
 * @property string|null $active_slot
 * @property int|null $decided_by_user_id
 * @property string|null $decision_note
 * @property Carbon|null $decided_at
 * @property Carbon|null $consumed_at
 */
class InpatientSummaryCorrectionRequestVersion extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const UPDATED_AT = null;

    protected $fillable = ['correction_request_id', 'actor_user_id', 'version', 'request_state', 'active_slot', 'decided_by_user_id', 'decision_note', 'decided_at', 'consumed_at'];

    protected static function booted(): void
    {
        static::creating(static fn () => InpatientSummaryAddendumMutationScope::assertActive());
        static::updating(static fn () => throw new LogicException('Correction request versions are immutable.'));
        static::deleting(static fn () => throw new LogicException('Correction request versions cannot be deleted ordinarily.'));
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'decided_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
