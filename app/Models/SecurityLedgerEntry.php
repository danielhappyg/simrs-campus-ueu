<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class SecurityLedgerEntry extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'public_id',
        'recorded_at',
        'actor_user_id',
        'actor_type',
        'actor_reference',
        'event_type',
        'resource_type',
        'resource_public_id',
        'outcome',
        'reason',
        'environment',
        'release_sha',
        'request_correlation_id',
        'schema_version',
        'payload',
        'payload_digest',
        'semantic_key',
        'integrity_digest',
    ];

    protected $hidden = [
        'payload_digest',
        'semantic_key',
        'integrity_digest',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Security ledger entries are append-only and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('Security ledger entries are append-only and cannot be deleted.');
        });
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return HasOne<SecurityLedgerOutbox, $this> */
    public function outbox(): HasOne
    {
        return $this->hasOne(SecurityLedgerOutbox::class);
    }

    protected function casts(): array
    {
        return [
            'recorded_at' => 'immutable_datetime',
            'schema_version' => 'integer',
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
