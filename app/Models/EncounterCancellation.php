<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $encounter_id
 * @property int $cancelled_by_user_id
 * @property string $reason_code
 * @property string|null $note
 * @property string $idempotency_key
 * @property string $payload_digest
 * @property string|null $request_correlation_id
 * @property Carbon $cancelled_at
 */
class EncounterCancellation extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const REASON_WRONG_REGISTRATION = 'SALAH_PENDAFTARAN';

    public const REASON_DUPLICATE_ENCOUNTER = 'DUPLIKAT_KUNJUNGAN';

    public const REASON_PATIENT_DID_NOT_CONTINUE = 'PASIEN_TIDAK_MELANJUTKAN';

    public const REASON_PLAN_CHANGED_BEFORE_SERVICE = 'PERUBAHAN_RENCANA_SEBELUM_PELAYANAN';

    /** @var list<string> */
    public const REASON_CODES = [
        self::REASON_WRONG_REGISTRATION,
        self::REASON_DUPLICATE_ENCOUNTER,
        self::REASON_PATIENT_DID_NOT_CONTINUE,
        self::REASON_PLAN_CHANGED_BEFORE_SERVICE,
    ];

    protected $fillable = [
        'encounter_id',
        'cancelled_by_user_id',
        'reason_code',
        'note',
        'idempotency_key',
        'payload_digest',
        'request_correlation_id',
        'cancelled_at',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Encounter cancellation facts are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Encounter cancellation facts cannot be deleted by ordinary application workflow.');
        });
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<User, $this> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cancelled_at' => 'datetime',
        ];
    }
}
