<?php

namespace App\Models;

use App\Support\Inpatient\InpatientRmMutationScope;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $inpatient_rm_coding_version_id
 * @property int $actor_user_id
 * @property string $source_statement_kind
 * @property int|null $source_statement_index
 * @property string $source_statement_text_hash
 * @property string $source_statement_reference
 * @property string $code_system
 * @property string $profile
 * @property string|null $normalized_code
 * @property string|null $display
 * @property int $ordinal
 * @property bool $is_no_procedure_attestation
 */
class InpatientRmCodingAssignment extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const UPDATED_AT = null;

    public const KIND_PRINCIPAL = 'PRINCIPAL_DIAGNOSIS';

    public const KIND_SECONDARY = 'SECONDARY_DIAGNOSIS';

    public const KIND_PROCEDURE = 'PROCEDURE';

    public const KIND_NO_PROCEDURE = 'NO_PROCEDURE';

    public const KINDS = [
        self::KIND_PRINCIPAL,
        self::KIND_SECONDARY,
        self::KIND_PROCEDURE,
        self::KIND_NO_PROCEDURE,
    ];

    protected $fillable = [
        'inpatient_rm_coding_version_id', 'actor_user_id', 'source_statement_kind',
        'source_statement_index', 'source_statement_text_hash', 'source_statement_reference',
        'code_system', 'profile', 'normalized_code', 'display', 'ordinal',
        'is_no_procedure_attestation',
    ];

    protected static function booted(): void
    {
        static::creating(static fn () => InpatientRmMutationScope::assertActive());
        static::updating(static function (): never {
            throw new LogicException('Inpatient RMIK coding assignments are immutable evidence.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Inpatient RMIK coding assignments cannot be deleted by ordinary workflow.');
        });
    }

    /** @return BelongsTo<InpatientRmCodingVersion, $this> */
    public function codingVersion(): BelongsTo
    {
        return $this->belongsTo(InpatientRmCodingVersion::class, 'inpatient_rm_coding_version_id');
    }

    protected function casts(): array
    {
        return [
            'source_statement_index' => 'integer', 'ordinal' => 'integer',
            'is_no_procedure_attestation' => 'boolean',
        ];
    }
}
