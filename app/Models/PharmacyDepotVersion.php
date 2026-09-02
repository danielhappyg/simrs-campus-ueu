<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $depot_id
 * @property int $actor_user_id
 * @property int $version
 * @property string $display_name
 * @property list<string> $eligible_care_settings
 * @property string $location_kind
 * @property string $state
 * @property string $content_digest
 * @property Carbon $created_at
 */
class PharmacyDepotVersion extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = ['depot_id', 'actor_user_id', 'version', 'display_name', 'eligible_care_settings', 'location_kind', 'state', 'content_digest', 'created_at'];

    /** @return BelongsTo<PharmacyDepot, $this> */
    public function depot(): BelongsTo
    {
        return $this->belongsTo(PharmacyDepot::class, 'depot_id');
    }

    protected function casts(): array
    {
        return ['eligible_care_settings' => 'array', 'version' => 'integer', 'created_at' => 'datetime'];
    }
}
