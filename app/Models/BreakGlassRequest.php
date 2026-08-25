<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BreakGlassRequest extends ImmutableBreakGlassFact
{
    protected $fillable = [
        'subject_user_id',
        'requester_user_id',
        'subject_snapshot',
        'requester_snapshot',
        'scope_key',
        'capability_snapshot',
        'reason',
        'change_reference',
        'requested_ttl_minutes',
        'requested_at',
        'approval_deadline_at',
        'environment',
        'release_sha',
        'canonical_digest',
    ];

    /** @return BelongsTo<User, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    /** @return HasOne<BreakGlassDecision, $this> */
    public function decision(): HasOne
    {
        return $this->hasOne(BreakGlassDecision::class);
    }

    /** @return HasOne<BreakGlassActivation, $this> */
    public function activation(): HasOne
    {
        return $this->hasOne(BreakGlassActivation::class);
    }

    protected function casts(): array
    {
        return [
            'subject_snapshot' => 'array',
            'requester_snapshot' => 'array',
            'capability_snapshot' => 'array',
            'requested_ttl_minutes' => 'integer',
            'requested_at' => 'immutable_datetime',
            'approval_deadline_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
