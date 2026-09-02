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
        return $this->recordUsing(
            null,
            $action,
            $resourceType,
            $resourceId,
            $actor,
            $outcome,
            $reason,
            $metadata,
            $request,
            $includeRequestFingerprint,
            [],
        );
    }

    /**
     * Persist an audit on an explicitly named connection so a caller can bind
     * it to domain evidence in one database transaction.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $warehouseBinding
     */
    public function recordOnConnection(
        string $connectionName,
        string $action,
        string $resourceType,
        ?string $resourceId = null,
        ?User $actor = null,
        string $outcome = 'SUCCESS',
        ?string $reason = null,
        array $metadata = [],
        ?Request $request = null,
        bool $includeRequestFingerprint = true,
        array $warehouseBinding = [],
    ): ?AuditEvent {
        if ($connectionName === '') {
            return null;
        }

        return $this->recordUsing(
            $connectionName,
            $action,
            $resourceType,
            $resourceId,
            $actor,
            $outcome,
            $reason,
            $metadata,
            $request,
            $includeRequestFingerprint,
            $warehouseBinding,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $warehouseBinding
     */
    private function recordUsing(
        ?string $connectionName,
        string $action,
        string $resourceType,
        ?string $resourceId,
        ?User $actor,
        string $outcome,
        ?string $reason,
        array $metadata,
        ?Request $request,
        bool $includeRequestFingerprint,
        array $warehouseBinding,
    ): ?AuditEvent {
        $request ??= request();

        try {
            $correlationId = $this->correlationId($request);
            $userAgent = $includeRequestFingerprint ? $this->hashUserAgent($request) : null;
            $attribution = app(AuditActorAttribution::class)->forRecording($action, $actor);

            $query = $connectionName === null
                ? AuditEvent::query()
                : AuditEvent::on($connectionName);

            $event = $query->make([
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
            if ($warehouseBinding !== []) {
                $event->forceFill($this->warehouseBindingAttributes(
                    $action,
                    $outcome,
                    $metadata,
                    $warehouseBinding,
                ));
            }
            $event->save();

            return $event;
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

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $binding
     * @return array<string, mixed>
     */
    private function warehouseBindingAttributes(string $action, string $outcome, array $metadata, array $binding): array
    {
        $keys = array_keys($binding);
        sort($keys);
        if ($action !== 'warehouse.workflow.mutate'
            || $outcome !== 'SUCCESS'
            || $keys !== ['control_total', 'operation', 'result_digest', 'result_version']
            || ! is_string($binding['operation'])
            || $binding['operation'] === ''
            || mb_strlen($binding['operation']) > 64
            || ! is_int($binding['result_version'])
            || $binding['result_version'] < 1
            || ($metadata['operation'] ?? null) !== $binding['operation']
            || ($metadata['version'] ?? null) !== $binding['result_version']
            || ! is_string($binding['result_digest'])
            || preg_match('/\A[a-f0-9]{64}\z/', $binding['result_digest']) !== 1
            || ! is_int($binding['control_total'])
            || $binding['control_total'] < 0) {
            throw new InvalidAuditEvent('Warehouse audit persistence binding is invalid.');
        }

        return [
            'warehouse_operation_snapshot' => $binding['operation'],
            'warehouse_result_version_snapshot' => $binding['result_version'],
            'warehouse_result_digest_snapshot' => $binding['result_digest'],
            'warehouse_control_total_snapshot' => $binding['control_total'],
        ];
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
