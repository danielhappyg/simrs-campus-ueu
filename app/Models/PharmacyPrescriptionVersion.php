<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $prescription_id
 * @property int $actor_user_id
 * @property int $version
 * @property string $state
 * @property array<string, mixed> $content_snapshot
 * @property string|null $reason_code
 * @property string|null $reason_note
 * @property string $content_digest
 * @property Carbon $created_at
 */
class PharmacyPrescriptionVersion extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = ['prescription_id', 'actor_user_id', 'version', 'state', 'content_snapshot', 'reason_code', 'reason_note', 'content_digest', 'created_at'];

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'content_snapshot' => 'array', 'created_at' => 'datetime'];
    }
}
