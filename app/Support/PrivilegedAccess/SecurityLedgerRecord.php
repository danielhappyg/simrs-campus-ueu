<?php

namespace App\Support\PrivilegedAccess;

use App\Models\User;
use Carbon\CarbonImmutable;

final readonly class SecurityLedgerRecord
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $eventType,
        public string $resourceType,
        public string $resourcePublicId,
        public string $actorType,
        public string $actorReference,
        public string $outcome,
        public string $reason,
        public array $payload,
        public string $environment,
        public string $releaseSha,
        public string $idempotencyKey,
        public ?User $actor = null,
        public ?string $requestCorrelationId = null,
        public int $schemaVersion = 1,
        public ?CarbonImmutable $recordedAt = null,
    ) {}
}
