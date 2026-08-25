<?php

namespace App\Support\Audit;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Returns null when validation or persistence fails.
 *
 * The three registration controllers and two emergency/inpatient clinical-note
 * controllers currently treat that result as best-effort and non-atomic. BG-02b
 * does not claim audit completeness for those five existing mutation paths.
 */
class AuditRecorder
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        string $resourceType,
        ?string $resourceId = null,
        ?User $actor = null,
        string $outcome = 'SUCCESS',
        ?string $reason = null,
        array $metadata = [],
        ?Request $request = null,
        bool $includeRequestFingerprint = true,
    ): ?AuditEvent {
        $request ??= request();

        try {
            $correlationId = $this->correlationId($request);
            $userAgent = $includeRequestFingerprint ? $this->hashUserAgent($request) : null;

            if ($actor !== null && (! $actor->exists || $actor->getKey() === null)) {
                throw new InvalidAuditEvent('Audit actor must be a persisted user.');
            }

            return AuditEvent::query()->create([
                'id' => (string) Str::ulid(),
                'recorded_at' => now(),
                'actor_user_id' => $actor?->getKey(),
                'action' => $action,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'outcome' => $outcome,
                'reason' => $reason,
                'request_correlation_id' => $correlationId,
                'ip_hash' => $includeRequestFingerprint ? $this->hashIp($request) : null,
                'user_agent' => $userAgent,
                'metadata' => $metadata === [] ? null : $metadata,
            ]);
        } catch (\Throwable $exception) {
            $failureCode = $exception instanceof InvalidAuditEvent
                ? 'AUDIT_CONTRACT_REJECTED'
                : 'AUDIT_PERSISTENCE_FAILED';
            error_log(sprintf(
                '[simrs] audit record failed (%s; %s)',
                $failureCode,
                $exception::class,
            ));

            return null;
        }
    }

    private function correlationId(Request $request): ?string
    {
        $correlationId = $request->attributes->get('request_id');

        if ($correlationId === null) {
            return null;
        }

        if (! is_string($correlationId) || ! Str::isUlid($correlationId)) {
            throw new InvalidAuditEvent('Audit request correlation ID must be a ULID.');
        }

        return $correlationId;
    }

    private function hashIp(Request $request): ?string
    {
        $ip = $request->ip();
        $key = config('app.key');

        if (! $ip || ! is_string($key) || $key === '') {
            return null;
        }

        return hash_hmac('sha256', $ip, $key);
    }

    private function hashUserAgent(Request $request): ?string
    {
        $userAgent = trim((string) $request->userAgent());
        if ($userAgent === '') {
            return null;
        }

        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            return null;
        }

        return hash_hmac('sha256', $userAgent, $key);
    }
}
