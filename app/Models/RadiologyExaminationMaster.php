<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property string $examination_code
 * @property string $display_name
 * @property string|null $preparation_instruction
 * @property string $state
 * @property int $version
 */
class RadiologyExaminationMaster extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const ACTIVE = 'ACTIVE';

    public const RETIRED = 'RETIRED';

    protected $fillable = ['examination_code', 'display_name', 'preparation_instruction', 'state', 'version'];

    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /** @return HasMany<RadiologyExaminationMasterVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(RadiologyExaminationMasterVersion::class);
    }

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
