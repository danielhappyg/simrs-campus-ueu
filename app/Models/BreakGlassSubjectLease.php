<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BreakGlassSubjectLease extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    protected $fillable = [
        'subject_user_id',
        'break_glass_activation_id',
        'expires_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /** @return BelongsTo<BreakGlassActivation, $this> */
    public function activation(): BelongsTo
    {
        return $this->belongsTo(BreakGlassActivation::class, 'break_glass_activation_id');
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
        ];
    }
}
