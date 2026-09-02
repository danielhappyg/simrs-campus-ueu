<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class OutpatientClinicalDocumentAddendumVersion extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'addendum_id', 'actor_user_id', 'version', 'addendum_state',
        'definition_version', 'fields', 'finalized_at',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Outpatient addendum versions are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Outpatient addendum versions cannot be deleted by ordinary workflow.');
        });
    }

    /** @return BelongsTo<OutpatientClinicalDocumentAddendum, $this> */
    public function addendum(): BelongsTo
    {
        return $this->belongsTo(OutpatientClinicalDocumentAddendum::class, 'addendum_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'fields' => 'array',
            'finalized_at' => 'datetime',
        ];
    }
}
