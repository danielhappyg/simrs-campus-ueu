<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $public_id
 * @property int $laboratory_examination_master_id
 * @property int $actor_user_id
 * @property int $version
 * @property string $display_name
 * @property string $specimen_type
 * @property string|null $collection_instruction
 * @property array<int, array<string, mixed>> $components
 * @property string $state
 * @property string $content_digest
 * @property Carbon $created_at
 */
class LaboratoryExaminationMasterVersion extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = ['laboratory_examination_master_id', 'actor_user_id', 'version', 'display_name', 'specimen_type', 'collection_instruction', 'components', 'state', 'content_digest', 'created_at'];

    protected function casts(): array
    {
        return ['components' => 'array', 'version' => 'integer', 'created_at' => 'datetime'];
    }
}
