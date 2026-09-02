<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $radiology_examination_master_id
 * @property int $actor_user_id
 * @property int $version
 * @property string $display_name
 * @property string|null $preparation_instruction
 * @property string $state
 * @property string $content_digest
 * @property Carbon $created_at
 */
class RadiologyExaminationMasterVersion extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = ['radiology_examination_master_id', 'actor_user_id', 'version', 'display_name', 'preparation_instruction', 'state', 'content_digest', 'created_at'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'created_at' => 'datetime'];
    }
}
