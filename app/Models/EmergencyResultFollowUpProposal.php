<?php

namespace App\Models;

use App\Support\Emergency\EmergencyImmutableEvidence;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $encounter_id
 * @property int $proposed_by_user_id
 * @property int $proposed_to_user_id
 * @property int|null $prior_proposal_id
 * @property string $order_type
 * @property string $order_public_id
 * @property string $result_fingerprint
 * @property string $assignment_reason
 * @property string $handoff_note
 * @property string|null $prior_proposal_digest
 * @property string $public_id
 * @property string $content_digest
 * @property Carbon $effective_at
 * @property Carbon $created_at
 * @property-read User $assignee
 * @property-read User $proposer
 * @property-read Encounter $encounter
 * @property-read EmergencyResultFollowUpProposal|null $priorProposal
 * @property-read EmergencyResultFollowUpAcceptance|null $acceptance
 */
class EmergencyResultFollowUpProposal extends Model
{
    use EmergencyImmutableEvidence, HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $guarded = ['id', 'public_id'];

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<User, $this> */
    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_to_user_id');
    }

    /** @return BelongsTo<EmergencyResultFollowUpProposal, $this> */
    public function priorProposal(): BelongsTo
    {
        return $this->belongsTo(self::class, 'prior_proposal_id');
    }

    /** @return HasOne<EmergencyResultFollowUpAcceptance, $this> */
    public function acceptance(): HasOne
    {
        return $this->hasOne(EmergencyResultFollowUpAcceptance::class, 'proposal_id');
    }

    protected function casts(): array
    {
        return ['effective_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
