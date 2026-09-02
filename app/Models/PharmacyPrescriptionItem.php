<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $prescription_id
 * @property int $medicine_id
 * @property int $line_number
 * @property int $medicine_version
 * @property string $medicine_version_public_id
 * @property string $medicine_content_digest
 * @property string $medicine_code
 * @property string $medicine_name
 * @property string $strength_text
 * @property string $dosage_form
 * @property string $base_unit
 * @property string $dose_text
 * @property string $route
 * @property string $frequency_text
 * @property string $duration_text
 * @property int $requested_quantity
 * @property int|null $verified_quantity
 * @property int $sale_value_snapshot
 * @property string $instruction
 * @property string $content_digest
 * @property Carbon $created_at
 */
class PharmacyPrescriptionItem extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = ['prescription_id', 'medicine_id', 'line_number', 'medicine_version', 'medicine_version_public_id', 'medicine_content_digest', 'medicine_code', 'medicine_name', 'strength_text', 'dosage_form', 'base_unit', 'dose_text', 'route', 'frequency_text', 'duration_text', 'requested_quantity', 'verified_quantity', 'sale_value_snapshot', 'instruction', 'content_digest', 'created_at'];

    /** @return BelongsTo<PharmacyPrescription, $this> */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(PharmacyPrescription::class, 'prescription_id');
    }

    /** @return HasMany<PharmacyHandoverItem, $this> */
    public function handoverItems(): HasMany
    {
        return $this->hasMany(PharmacyHandoverItem::class, 'prescription_item_id');
    }

    protected function casts(): array
    {
        return ['line_number' => 'integer', 'medicine_version' => 'integer', 'requested_quantity' => 'integer', 'verified_quantity' => 'integer', 'sale_value_snapshot' => 'integer', 'created_at' => 'datetime'];
    }
}
