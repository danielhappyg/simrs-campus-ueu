<?php

namespace App\Support\Audit;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Returns null when validation or persistence fails.
 *
 * Mutation callers must check that result inside their database transaction so
 * domain state cannot commit when its required audit evidence is unavailable.
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
            $attribution = app(AuditActorAttribution::class)->forRecording($action, $actor);

            return AuditEvent::query()->create([
                'id' => (string) Str::ulid(),
                'recorded_at' => now(),
                ...$attribution,
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
