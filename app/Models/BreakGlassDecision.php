<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BreakGlassDecision extends ImmutableBreakGlassFact
{
    public const APPROVED = 'APPROVED';

    public const DENIED = 'DENIED';

    protected $fillable = [
        'break_glass_request_id',
        'approver_user_id',
        'approver_snapshot',
        'decision',
        'rationale',
        'assurance_method',
        'assured_at',
        'decided_at',
        'request_digest',
        'environment',
        'release_sha',
        'canonical_digest',
    ];

    /** @return BelongsTo<BreakGlassRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(BreakGlassRequest::class, 'break_glass_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    /** @return HasOne<BreakGlassActivation, $this> */
    public function activation(): HasOne
    {
        return $this->hasOne(BreakGlassActivation::class);
    }

    protected function casts(): array
    {
        return [
            'approver_snapshot' => 'array',
            'assured_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
