<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property string $medicine_code
 * @property string $generic_name
 * @property string|null $brand_name
 * @property string $strength_text
 * @property string $dosage_form
 * @property string $base_unit
 * @property list<string> $route_choices
 * @property int $acquisition_value
 * @property int $teaching_sale_value
 * @property string $state
 * @property int $version
 * @property string $current_content_digest
 */
class PharmacyMedicine extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const ACTIVE = 'ACTIVE';

    public const RETIRED = 'RETIRED';

    protected $fillable = ['medicine_code', 'generic_name', 'brand_name', 'strength_text', 'dosage_form', 'base_unit', 'route_choices', 'acquisition_value', 'teaching_sale_value', 'state', 'version', 'current_content_digest'];

    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /** @return HasMany<PharmacyMedicineVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(PharmacyMedicineVersion::class, 'medicine_id');
    }

    protected function casts(): array
    {
        return ['route_choices' => 'array', 'acquisition_value' => 'integer', 'teaching_sale_value' => 'integer', 'version' => 'integer'];
    }
}
