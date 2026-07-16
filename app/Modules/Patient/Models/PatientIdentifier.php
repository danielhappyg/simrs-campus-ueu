<?php

namespace App\Modules\Patient\Models;

use App\Modules\Patient\Enums\IdentifierStatus;
use App\Modules\Patient\Enums\IdentifierType;
use App\Support\Models\HasPublicUlid;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property IdentifierType $type
 * @property string $system
 * @property string $value
 * @property bool $synthetic_flag
 * @property IdentifierStatus $status
 */
class PatientIdentifier extends Model
{
    use HasPublicUlid;

    public const SYNTHETIC_SYSTEM_PREFIX = 'urn:ueu:simrs-campus:synthetic:';

    protected $fillable = [
        'patient_id',
        'type',
        'system',
        'value',
        'synthetic_flag',
        'valid_from',
        'valid_until',
        'status',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $identifier): void {
            if ($identifier->synthetic_flag !== true) {
                throw new DomainException('Patient identifiers must be explicitly synthetic.');
            }

            if (! Str::startsWith($identifier->system, self::SYNTHETIC_SYSTEM_PREFIX)) {
                throw new DomainException('Production or unclassified identifier namespaces are forbidden in the reference MVP.');
            }
        });
    }

    /**
     * @return BelongsTo<SyntheticPatient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(SyntheticPatient::class, 'patient_id');
    }

    protected function casts(): array
    {
        return [
            'type' => IdentifierType::class,
            'synthetic_flag' => 'boolean',
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'status' => IdentifierStatus::class,
        ];
    }
}
