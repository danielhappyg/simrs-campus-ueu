<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $display_name
 * @property string $public_id
 * @property string $examination_code
 * @property string $specimen_type
 * @property string|null $collection_instruction
 * @property array<int, array<string, mixed>> $components
 * @property int $version
 * @property string $state
 */
class LaboratoryExaminationMaster extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const ACTIVE = 'ACTIVE';

    public const RETIRED = 'RETIRED';

    protected $fillable = ['examination_code', 'display_name', 'specimen_type', 'collection_instruction', 'components', 'state', 'version'];

    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /** @return HasMany<LaboratoryExaminationMasterVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(LaboratoryExaminationMasterVersion::class);
    }

    protected function casts(): array
    {
        return ['components' => 'array', 'version' => 'integer'];
    }
}
