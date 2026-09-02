<?php

namespace App\Models;

use App\Support\Emergency\EmergencyImmutableEvidence;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $accepted_by_user_id
 * @property int $proposal_id
 * @property string $public_id
 * @property string $content_digest
 * @property string $proposal_fingerprint
 * @property Carbon $accepted_at
 * @property Carbon $created_at
 * @property-read EmergencyResultFollowUpProposal $proposal
 * @property-read User $actor
 */
class EmergencyResultFollowUpAcceptance extends Model
{
    use EmergencyImmutableEvidence, HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = ['proposal_id', 'accepted_by_user_id', 'proposal_fingerprint', 'content_digest', 'accepted_at', 'created_at'];

    /** @return BelongsTo<EmergencyResultFollowUpProposal, $this> */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(EmergencyResultFollowUpProposal::class, 'proposal_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
