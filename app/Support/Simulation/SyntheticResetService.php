<?php

namespace App\Support\Simulation;

use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SyntheticResetService
{
    /**
     * Domain tables truncated on reset. Empty until Phase 3 clinical tables exist.
     * Preserve: users, roles, permissions, permission_role, role_user, audit_events (unless purged).
     *
     * @var list<string>
     */
    private const DOMAIN_TABLE_ALLOWLIST = [
        // Future: patients, encounters, clinical_notes, orders, queues, charges, …
    ];

    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    /**
     * @param  array{purge_audit?: bool, actor?: ?User, reason?: ?string}  $options
     */
    public function reset(array $options = []): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Synthetic reset requires SIMULATION mode with synthetic-only data enforced.');
        }

        $purgeAudit = (bool) ($options['purge_audit'] ?? false);
        $actor = $options['actor'] ?? null;
        $reason = $options['reason'] ?? 'simulation_reset';

        $this->auditRecorder->record(
            action: 'teaching.reset.started',
            resourceType: 'simulation',
            resourceId: 'synthetic-reset',
            actor: $actor instanceof User ? $actor : null,
            outcome: 'SUCCESS',
            reason: $reason,
            metadata: [
                'purge_audit' => $purgeAudit,
                'domain_tables' => self::DOMAIN_TABLE_ALLOWLIST,
            ],
            includeRequestFingerprint: false,
        );

        Schema::disableForeignKeyConstraints();

        try {
            foreach (self::DOMAIN_TABLE_ALLOWLIST as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        if ($purgeAudit) {
            DB::table('audit_events')->delete();
        }

        $this->auditRecorder->record(
            action: 'teaching.reset.completed',
            resourceType: 'simulation',
            resourceId: 'synthetic-reset',
            actor: $actor instanceof User ? $actor : null,
            outcome: 'SUCCESS',
            reason: $reason,
            metadata: [
                'purge_audit' => $purgeAudit,
                'domain_tables' => self::DOMAIN_TABLE_ALLOWLIST,
            ],
            includeRequestFingerprint: false,
        );
    }
}
