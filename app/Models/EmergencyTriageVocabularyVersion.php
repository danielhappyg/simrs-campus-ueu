<?php

namespace App\Models;

use App\Support\Emergency\EmergencyImmutableEvidence;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $public_id
 * @property int $version
 * @property string $display_name
 * @property string $state
 * @property array<int, array<string, mixed>> $categories
 * @property string $content_digest
 * @property Carbon $created_at
 * @property-read User $actor
 */
class EmergencyTriageVocabularyVersion extends Model
{
    use EmergencyImmutableEvidence, HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = ['emergency_triage_vocabulary_id', 'actor_user_id', 'version', 'display_name', 'categories', 'state', 'content_digest', 'created_at'];

    /** @return BelongsTo<EmergencyTriageVocabulary, $this> */
    public function vocabulary(): BelongsTo
    {
        return $this->belongsTo(EmergencyTriageVocabulary::class, 'emergency_triage_vocabulary_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'categories' => 'array', 'created_at' => 'datetime'];
    }
}
