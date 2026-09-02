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
 * @property int $medicine_id
 * @property int $actor_user_id
 * @property int $version
 * @property string $generic_name
 * @property string|null $brand_name
 * @property string $strength_text
 * @property string $dosage_form
 * @property string $base_unit
 * @property list<string> $route_choices
 * @property int $acquisition_value
 * @property int $teaching_sale_value
 * @property string $state
 * @property string $content_digest
 * @property Carbon $created_at
 */
class PharmacyMedicineVersion extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = ['medicine_id', 'actor_user_id', 'version', 'generic_name', 'brand_name', 'strength_text', 'dosage_form', 'base_unit', 'route_choices', 'acquisition_value', 'teaching_sale_value', 'state', 'content_digest', 'created_at'];

    /** @return BelongsTo<PharmacyMedicine, $this> */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(PharmacyMedicine::class, 'medicine_id');
    }

    protected function casts(): array
    {
        return ['route_choices' => 'array', 'acquisition_value' => 'integer', 'teaching_sale_value' => 'integer', 'version' => 'integer', 'created_at' => 'datetime'];
    }
}
