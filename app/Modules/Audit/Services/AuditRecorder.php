<?php

namespace App\Modules\Audit\Services;

use App\Models\User;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
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
        ?Assignment $assignment = null,
        ?SimulationSession $session = null,
        string $outcome = 'SUCCESS',
        ?string $reason = null,
        array $metadata = [],
        ?Request $request = null,
    ): AuditEvent {
        $request ??= request();

        return AuditEvent::query()->create([
            'actor_user_id' => $actor?->getKey(),
            'assignment_id' => $assignment?->getKey(),
            'session_id' => $session?->getKey(),
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'outcome' => $outcome,
            'reason' => $reason,
            'request_correlation_id' => $request->attributes->get('request_id'),
            'ip_hash' => $this->hashIp($request),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
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
