<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BreakGlassRevocation extends ImmutableBreakGlassFact
{
    public const REVOKER_USER = 'USER';

    public const REVOKER_SERVICE = 'SERVICE';

    protected $fillable = [
        'break_glass_activation_id',
        'revoker_user_id',
        'revoker_type',
        'revoker_reference',
        'revoker_snapshot',
        'reason',
        'change_reference',
        'revoked_at',
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
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoker_user_id');
    }

    protected function casts(): array
    {
        return [
            'revoker_snapshot' => 'array',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
