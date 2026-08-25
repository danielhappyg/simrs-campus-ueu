<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BreakGlassSessionBinding extends ImmutableBreakGlassFact
{
    protected $hidden = [
        'session_reference_hmac',
    ];

    protected $fillable = [
        'break_glass_activation_id',
        'subject_user_id',
        'session_reference_hmac',
        'assurance_method',
        'assured_at',
        'bound_at',
        'activation_digest',
        'environment',
        'release_sha',
        'canonical_digest',
    ];

    /** @return BelongsTo<BreakGlassActivation, $this> */
    public function activation(): BelongsTo
    {
        return $this->belongsTo(BreakGlassActivation::class, 'break_glass_activation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    protected function casts(): array
    {
        return [
            'assured_at' => 'immutable_datetime',
            'bound_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
