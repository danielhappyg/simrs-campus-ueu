<?php

namespace App\Support\Audit;

use App\Models\User;
use App\Support\Models\UsesSchemaQualifiedTable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * Append-only audit row for the clean-slate rebuild foundation.
 *
 * @property string $id
 * @property int|null $actor_user_id
 * @property string|null $actor_type
 * @property string|null $actor_reference
 * @property string $action
 * @property string $resource_type
 * @property string|null $resource_id
 * @property string|null $resource_version
 * @property string $outcome
 * @property string|null $reason
 * @property CarbonImmutable $recorded_at
 * @property string|null $request_correlation_id
 * @property string|null $ip_hash
 * @property string|null $user_agent
 * @property array<string, mixed>|null $metadata
 * @property-read User|null $actor
 */
class AuditEvent extends Model
{
    use UsesSchemaQualifiedTable;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'recorded_at',
        'actor_user_id',
        'actor_type',
        'actor_reference',
        'action',
        'resource_type',
        'resource_id',
        'resource_version',
        'outcome',
        'reason',
        'request_correlation_id',
        'ip_hash',
        'user_agent',
        'metadata',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $event->id ??= (string) Str::ulid();
            $event->recorded_at ??= now();

            $metadata = $event->metadata ?? [];

            $action = self::requiredString($event->action, 'action');
            $actorType = self::requiredString($event->getAttribute('actor_type'), 'actor_type');
            $actorReference = self::requiredString($event->getAttribute('actor_reference'), 'actor_reference');
            $resourceType = self::requiredString($event->resource_type, 'resource_type');
            $resourceId = self::nullableString($event->resource_id, 'resource_id');
            $outcome = self::requiredString($event->outcome, 'outcome');
            $reason = self::nullableString($event->reason, 'reason');
            if ($event->resource_version !== null) {
                throw new InvalidAuditEvent('Audit resource_version is not registered for current event families.');
            }

            $safeDataGuard = app(AuditSafeDataGuard::class);
            $safeDataGuard->assertSafeMetadata($metadata);
            $safeDataGuard->assertSafeText($actorReference, 'actor_reference');
            $safeDataGuard->assertSafeText($resourceId, 'resource_id');
            $safeDataGuard->assertSafeText($reason, 'reason');

            app(AuditActorAttribution::class)->assertValid(
                action: $action,
                actorUserId: $event->actor_user_id,
                actorType: $actorType,
                actorReference: $actorReference,
            );

            app(AuditEventSchemaRegistry::class)->assertAllows(
                action: $action,
                resourceType: $resourceType,
                resourceId: $resourceId,
                actorPresent: $event->actor_user_id !== null,
                outcome: $outcome,
                reason: $reason,
                metadata: $metadata,
            );

            self::assertOptionalUlid($event->request_correlation_id, 'request_correlation_id');
            self::assertOptionalDigest($event->ip_hash, 'ip_hash');
            self::assertOptionalDigest($event->user_agent, 'user_agent');
        });

        static::updating(function (): never {
            throw new LogicException('Audit events are append-only and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('Audit events are append-only and cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    private static function requiredString(mixed $value, string $field): string
    {
        if (! is_string($value) || $value === '') {
            throw new InvalidAuditEvent("Audit {$field} must be a non-empty string.");
        }

        return $value;
    }

    private static function nullableString(mixed $value, string $field): ?string
    {
        if ($value !== null && ! is_string($value)) {
            throw new InvalidAuditEvent("Audit {$field} must be null or a string.");
        }

        return $value;
    }

    private static function assertOptionalUlid(mixed $value, string $field): void
    {
        if ($value !== null && (! is_string($value) || ! Str::isUlid($value))) {
            throw new InvalidAuditEvent("Audit {$field} must be null or a ULID.");
        }
    }

    private static function assertOptionalDigest(mixed $value, string $field): void
    {
        if ($value !== null && (! is_string($value) || preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1)) {
            throw new InvalidAuditEvent("Audit {$field} must be null or a SHA-256 hex digest.");
        }
    }
}
