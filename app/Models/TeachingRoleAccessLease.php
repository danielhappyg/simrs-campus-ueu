<?php

namespace App\Models;

use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property string $expected_role
 * @property string $credential_commitment
 * @property string $password_state_commitment
 * @property string $environment
 * @property string $release_sha
 * @property string $deployment_url
 * @property string $canonical_host
 * @property string $status
 * @property int|null $active_slot
 * @property int $activated_at_epoch
 * @property int $expires_at_epoch
 * @property int|null $ended_at_epoch
 * @property string|null $end_reason
 * @property string $operator
 * @property string $reason
 */
class TeachingRoleAccessLease extends Model
{
    use UsesSchemaQualifiedTable;

    /** @var list<string> */
    protected $hidden = [
        'credential_commitment',
        'password_state_commitment',
    ];

    protected $fillable = [
        'public_id',
        'user_id',
        'expected_role',
        'credential_commitment',
        'password_state_commitment',
        'environment',
        'release_sha',
        'deployment_url',
        'canonical_host',
        'status',
        'active_slot',
        'activated_at_epoch',
        'expires_at_epoch',
        'ended_at_epoch',
        'end_reason',
        'operator',
        'reason',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'activated_at_epoch' => 'integer',
            'expires_at_epoch' => 'integer',
            'ended_at_epoch' => 'integer',
            'active_slot' => 'integer',
        ];
    }
}
