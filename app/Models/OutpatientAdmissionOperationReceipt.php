<?php

namespace App\Models;

use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class OutpatientAdmissionOperationReceipt extends Model
{
    use UsesSchemaQualifiedTable;

    public $timestamps = false;

    protected $fillable = ['actor_user_id', 'operation', 'idempotency_key', 'payload_digest', 'result_type', 'result_public_id', 'result_digest', 'request_correlation_id', 'completed_at'];

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Outpatient admission receipts are immutable.'));
        self::deleting(fn () => throw new LogicException('Outpatient admission receipts cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['actor_user_id' => 'integer', 'completed_at' => 'datetime'];
    }
}
