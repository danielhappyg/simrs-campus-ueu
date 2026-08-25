<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BreakGlassActivation extends ImmutableBreakGlassFact
{
    protected $hidden = [
        'nonce_digest',
    ];

    protected $fillable = [
        'break_glass_request_id',
        'break_glass_decision_id',
        'subject_user_id',
        'approved_by_user_id',
        'subject_snapshot',
        'approver_snapshot',
        'scope_key',
        'capability_snapshot',
        'starts_at',
        'expires_at',
        'nonce_version',
        'nonce_digest',
        'request_digest',
        'decision_digest',
        'environment',
        'release_sha',
        'canonical_digest',
    ];

    /** @return BelongsTo<BreakGlassRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(BreakGlassRequest::class, 'break_glass_request_id');
    }

    /** @return BelongsTo<BreakGlassDecision, $this> */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(BreakGlassDecision::class, 'break_glass_decision_id');
    }

    /** @return BelongsTo<User, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /** @return HasOne<BreakGlassRevocation, $this> */
    public function revocation(): HasOne
    {
        return $this->hasOne(BreakGlassRevocation::class);
    }

    /** @return HasOne<BreakGlassSessionBinding, $this> */
    public function sessionBinding(): HasOne
    {
        return $this->hasOne(BreakGlassSessionBinding::class);
    }

    /** @return HasOne<BreakGlassSubjectLease, $this> */
    public function subjectLease(): HasOne
    {
        return $this->hasOne(BreakGlassSubjectLease::class);
    }

    protected function casts(): array
    {
        return [
            'subject_snapshot' => 'array',
            'approver_snapshot' => 'array',
            'capability_snapshot' => 'array',
            'starts_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'nonce_version' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
