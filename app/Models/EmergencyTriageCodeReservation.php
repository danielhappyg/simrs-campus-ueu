<?php

namespace App\Models;

use App\Support\Emergency\EmergencyImmutableEvidence;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmergencyTriageCodeReservation extends Model
{
    use EmergencyImmutableEvidence, HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = ['actor_user_id', 'normalized_code', 'created_at'];

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
