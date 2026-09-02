<?php

namespace App\Models;

use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;

/**
 * Persistent, patient-scoped mutex used to serialize every operation that can
 * create, move, or release an active inpatient admission claim.
 *
 * @property int $patient_id
 */
class InpatientPatientClaimMutex extends Model
{
    use UsesSchemaQualifiedTable;

    protected $primaryKey = 'patient_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['patient_id'];
}
