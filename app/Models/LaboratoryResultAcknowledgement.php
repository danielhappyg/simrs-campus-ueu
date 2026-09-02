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
 * @property int|null $emergency_follow_up_acceptance_id
 * @property string $result_fingerprint
 * @property Carbon $acknowledged_at
 * @property Carbon $created_at
 * @property-read User $actor
 */
class LaboratoryResultAcknowledgement extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = [
        'laboratory_result_version_id',
        'actor_user_id',
        'emergency_follow_up_acceptance_id',
        'result_fingerprint',
        'acknowledged_at',
        'created_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<EmergencyResultFollowUpAcceptance, $this> */
    public function emergencyFollowUpAcceptance(): BelongsTo
    {
        return $this->belongsTo(EmergencyResultFollowUpAcceptance::class, 'emergency_follow_up_acceptance_id');
    }

    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
