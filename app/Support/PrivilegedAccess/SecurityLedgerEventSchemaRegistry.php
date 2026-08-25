<?php

namespace App\Support\PrivilegedAccess;

use Carbon\CarbonImmutable;
use Throwable;

final class SecurityLedgerEventSchemaRegistry
{
    /** @var array<string, array{actor_type: string, resource_type: string, outcome: string, schema_version: int, keys: list<string>}> */
    private const SCHEMAS = [
        'break_glass.activation.created' => [
            'actor_type' => 'USER',
            'resource_type' => 'break_glass_activation',
            'outcome' => 'SUCCESS',
            'schema_version' => 1,
            'keys' => [
                'request_public_id',
                'decision_public_id',
                'activation_public_id',
                'requester_public_id',
                'approver_public_id',
                'subject_public_id',
                'scope_key',
                'capability_snapshot',
                'request_digest',
                'decision_digest',
                'assurance_method',
                'assured_at',
                'change_reference',
                'sessions_revoked',
                'starts_at',
                'expires_at',
            ],
        ],
        'break_glass.activation.expired' => [
            'actor_type' => 'SERVICE',
            'resource_type' => 'break_glass_activation',
            'outcome' => 'SUCCESS',
            'schema_version' => 1,
            'keys' => [
                'activation_public_id',
                'subject_public_id',
                'activation_digest',
                'expired_at',
                'sessions_revoked',
            ],
        ],
    ];

    public function assertValid(SecurityLedgerRecord $record): void
    {
        $schema = self::SCHEMAS[$record->eventType] ?? null;

        if ($schema === null) {
            throw new UnsafeSecurityLedgerPayload('Security-ledger event type is not registered.');
        }

        if (
            $record->actorType !== $schema['actor_type']
            || $record->resourceType !== $schema['resource_type']
            || $record->outcome !== $schema['outcome']
            || $record->schemaVersion !== $schema['schema_version']
        ) {
            throw new UnsafeSecurityLedgerPayload('Security-ledger event envelope does not match its registered schema.');
        }

        $payload = $record->payload;
        $actualKeys = array_keys($payload);
        sort($actualKeys);
        $requiredKeys = $schema['keys'];
        sort($requiredKeys);

        if ($actualKeys !== $requiredKeys) {
            throw new UnsafeSecurityLedgerPayload(
                'Security-ledger event payload must contain exactly its registered fields.',
            );
        }

        $this->assertUlids($payload, array_values(array_filter(
            $schema['keys'],
            static fn (string $key): bool => str_ends_with($key, '_public_id'),
        )));

        $activationPublicId = $payload['activation_public_id'];

        if (! is_string($activationPublicId) || ! hash_equals($activationPublicId, $record->resourcePublicId)) {
            throw new UnsafeSecurityLedgerPayload(
                'Security-ledger activation resource ID must match its registered payload identity.',
            );
        }

        foreach (array_values(array_filter(
            $schema['keys'],
            static fn (string $key): bool => str_ends_with($key, '_digest'),
        )) as $key) {
            if (! is_string($payload[$key]) || preg_match('/\A[0-9a-f]{64}\z/', $payload[$key]) !== 1) {
                throw new UnsafeSecurityLedgerPayload("Security-ledger event field [{$key}] must be a SHA-256 digest.");
            }
        }

        foreach (array_values(array_filter(
            $schema['keys'],
            static fn (string $key): bool => str_ends_with($key, '_at'),
        )) as $key) {
            $this->assertTimestamp($payload[$key], $key);
        }

        if (! is_int($payload['sessions_revoked']) || $payload['sessions_revoked'] < 0) {
            throw new UnsafeSecurityLedgerPayload('Security-ledger sessions_revoked must be a non-negative integer.');
        }

        if (array_key_exists('capability_snapshot', $payload)) {
            $capabilities = $payload['capability_snapshot'];

            if (
                ! is_array($capabilities)
                || ! array_is_list($capabilities)
                || $capabilities === []
            ) {
                throw new UnsafeSecurityLedgerPayload('Security-ledger capability snapshot must be a non-empty unique list.');
            }

            foreach ($capabilities as $capability) {
                if (! is_string($capability) || preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/', $capability) !== 1) {
                    throw new UnsafeSecurityLedgerPayload('Security-ledger capability snapshot contains an invalid capability.');
                }
            }

            if (count($capabilities) !== count(array_unique($capabilities, SORT_STRING))) {
                throw new UnsafeSecurityLedgerPayload('Security-ledger capability snapshot must be a non-empty unique list.');
            }
        }

        foreach (['scope_key', 'assurance_method', 'change_reference'] as $key) {
            if (array_key_exists($key, $payload) && (! is_string($payload[$key]) || trim($payload[$key]) === '')) {
                throw new UnsafeSecurityLedgerPayload("Security-ledger event field [{$key}] must be a non-empty string.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function assertUlids(array $payload, array $keys): void
    {
        foreach ($keys as $key) {
            if (! is_string($payload[$key]) || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $payload[$key]) !== 1) {
                throw new UnsafeSecurityLedgerPayload("Security-ledger event field [{$key}] must be a ULID.");
            }
        }
    }

    private function assertTimestamp(mixed $value, string $key): void
    {
        if (
            ! is_string($value)
            || preg_match(
                '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/',
                $value,
            ) !== 1
        ) {
            throw new UnsafeSecurityLedgerPayload("Security-ledger event field [{$key}] must be an ISO-8601 timestamp.");
        }

        try {
            CarbonImmutable::parse($value);
        } catch (Throwable) {
            throw new UnsafeSecurityLedgerPayload("Security-ledger event field [{$key}] must be an ISO-8601 timestamp.");
        }
    }
}
