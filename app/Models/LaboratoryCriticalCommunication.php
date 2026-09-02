<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $public_id
 * @property int $laboratory_result_version_id
 * @property int $actor_user_id
 * @property int $recipient_user_id
 * @property string $communication_method
 * @property string $outcome
 * @property string|null $note
 * @property Carbon $communicated_at
 * @property string $content_digest
 * @property Carbon $created_at
 * @property-read User $actor
 * @property-read User $recipient
 * @property-read LaboratoryResultVersion $resultVersion
 */
class LaboratoryCriticalCommunication extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = ['laboratory_result_version_id', 'actor_user_id', 'recipient_user_id', 'communication_method', 'outcome', 'note', 'communicated_at', 'content_digest', 'created_at'];

    /** @return BelongsTo<LaboratoryResultVersion, $this> */
    public function resultVersion(): BelongsTo
    {
        return $this->belongsTo(LaboratoryResultVersion::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    protected function casts(): array
    {
        return ['communicated_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
