<?php

namespace App\Support\Audit;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
    ): AuditEvent {
        $request ??= request();

        return AuditEvent::query()->create([
            'id' => (string) Str::ulid(),
            'recorded_at' => now(),
            'actor_user_id' => $actor?->getKey(),
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'outcome' => $outcome,
            'reason' => $reason,
            'request_correlation_id' => $request->attributes->get('request_id'),
            'ip_hash' => $includeRequestFingerprint ? $this->hashIp($request) : null,
            'user_agent' => $includeRequestFingerprint
                ? Str::limit((string) $request->userAgent(), 255, '')
                : null,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
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
}
