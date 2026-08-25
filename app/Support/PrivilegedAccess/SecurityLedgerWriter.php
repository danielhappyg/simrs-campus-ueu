<?php

namespace App\Support\PrivilegedAccess;

use App\Models\SecurityLedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SecurityLedgerWriter
{
    public function __construct(
        private readonly SecurityLedgerPayloadGuard $payloadGuard,
        private readonly SecurityLedgerIntegrity $integrity,
        private readonly SecurityLedgerOutboxPersistence $outboxPersistence,
        private readonly SecurityLedgerActorGuard $actorGuard,
        private readonly SecurityLedgerEventSchemaRegistry $eventSchemas,
    ) {}

    public function append(SecurityLedgerRecord $record): SecurityLedgerEntry
    {
        $this->validate($record);
        $semanticKey = hash('sha256', $record->eventType."\0".$record->idempotencyKey);

        try {
            return DB::transaction(function () use ($record, $semanticKey): SecurityLedgerEntry {
                $payloadDigest = $this->integrity->payloadDigest($record->payload);
                $existing = SecurityLedgerEntry::query()->where('semantic_key', $semanticKey)->first();

                if ($existing !== null) {
                    return $this->replayExisting($existing, $record, $payloadDigest);
                }

                $publicId = (string) Str::ulid();
                $recordedAt = ($record->recordedAt ?? CarbonImmutable::now('UTC'))->utc();
                $integrityEnvelope = [
                    'actor_reference' => $record->actorReference,
                    'actor_type' => $record->actorType,
                    'environment' => $record->environment,
                    'event_type' => $record->eventType,
                    'outcome' => $record->outcome,
                    'payload_digest' => $payloadDigest,
                    'public_id' => $publicId,
                    'reason' => $record->reason,
                    'recorded_at' => $recordedAt->format('Y-m-d\TH:i:s.u\Z'),
                    'release_sha' => strtolower($record->releaseSha),
                    'request_correlation_id' => $record->requestCorrelationId,
                    'resource_public_id' => $record->resourcePublicId,
                    'resource_type' => $record->resourceType,
                    'schema_version' => $record->schemaVersion,
                    'semantic_key' => $semanticKey,
                ];

                $entry = SecurityLedgerEntry::query()->create([
                    'public_id' => $publicId,
                    'recorded_at' => $recordedAt,
                    'actor_user_id' => $record->actor?->getKey(),
                    'actor_type' => $record->actorType,
                    'actor_reference' => $record->actorReference,
                    'event_type' => $record->eventType,
                    'resource_type' => $record->resourceType,
                    'resource_public_id' => $record->resourcePublicId,
                    'outcome' => $record->outcome,
                    'reason' => $record->reason,
                    'environment' => $record->environment,
                    'release_sha' => strtolower($record->releaseSha),
                    'request_correlation_id' => $record->requestCorrelationId,
                    'schema_version' => $record->schemaVersion,
                    'payload' => $record->payload,
                    'payload_digest' => $payloadDigest,
                    'semantic_key' => $semanticKey,
                    'integrity_digest' => $this->integrity->integrityDigest($integrityEnvelope),
                ]);

                $outbox = $this->outboxPersistence->persist($entry);
                $entry->setRelation('outbox', $outbox);

                return $entry;
            }, attempts: 1);
        } catch (QueryException $exception) {
            // Resolve only an actual concurrent semantic insert; all other DB failures remain fail-closed.
            $existing = SecurityLedgerEntry::query()->where('semantic_key', $semanticKey)->first();

            if ($existing === null) {
                throw $exception;
            }

            return $this->replayExisting(
                $existing,
                $record,
                $this->integrity->payloadDigest($record->payload),
            );
        }
    }

    private function validate(SecurityLedgerRecord $record): void
    {
        foreach ([
            'event type' => $record->eventType,
            'resource type' => $record->resourceType,
            'resource public ID' => $record->resourcePublicId,
            'actor type' => $record->actorType,
            'actor reference' => $record->actorReference,
            'outcome' => $record->outcome,
            'reason' => $record->reason,
            'environment' => $record->environment,
            'idempotency key' => $record->idempotencyKey,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new UnsafeSecurityLedgerPayload("Security-ledger {$field} is required.");
            }

            $this->payloadGuard->assertSafeText($value, $field);
        }

        if (preg_match('/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/i', $record->releaseSha) !== 1) {
            throw new UnsafeSecurityLedgerPayload('Security-ledger release SHA must be an exact 40- or 64-character hexadecimal digest.');
        }

        if (
            $record->requestCorrelationId !== null
            && preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $record->requestCorrelationId) !== 1
        ) {
            throw new UnsafeSecurityLedgerPayload('Security-ledger request correlation ID must be a ULID.');
        }

        if ($record->schemaVersion < 1) {
            throw new UnsafeSecurityLedgerPayload('Security-ledger schema version must be positive.');
        }

        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,254}\z/', $record->idempotencyKey) !== 1) {
            throw new UnsafeSecurityLedgerPayload(
                'Security-ledger idempotency key must use the registered bounded key format.',
            );
        }

        $this->payloadGuard->assertSafe($record->payload);
        $this->actorGuard->assertValid($record);
        $this->eventSchemas->assertValid($record);
    }

    private function replayExisting(
        SecurityLedgerEntry $entry,
        SecurityLedgerRecord $record,
        string $payloadDigest,
    ): SecurityLedgerEntry {
        $expected = [
            'actor_user_id' => $record->actor?->getKey(),
            'actor_type' => $record->actorType,
            'actor_reference' => $record->actorReference,
            'event_type' => $record->eventType,
            'resource_type' => $record->resourceType,
            'resource_public_id' => $record->resourcePublicId,
            'outcome' => $record->outcome,
            'reason' => $record->reason,
            'environment' => $record->environment,
            'release_sha' => strtolower($record->releaseSha),
            'request_correlation_id' => $record->requestCorrelationId,
            'schema_version' => $record->schemaVersion,
            'payload_digest' => $payloadDigest,
        ];

        foreach ($expected as $attribute => $value) {
            if ((string) $entry->getAttribute($attribute) !== (string) $value) {
                throw new SecurityLedgerSemanticConflict(
                    'Security-ledger idempotency key was reused for different semantic content.',
                );
            }
        }

        $outbox = $entry->outbox()->first();

        if ($outbox === null) {
            throw new SecurityLedgerSemanticConflict(
                'Security-ledger idempotent replay found an entry without its required outbox.',
            );
        }

        $entry->setRelation('outbox', $outbox);

        return $entry;
    }
}
