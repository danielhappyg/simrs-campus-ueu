<?php

namespace App\Support\Simulation;

use App\Models\Patient;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SyntheticResetService
{
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

        DB::transaction(function () use ($purgeAudit, $actor, $reason): void {
            $started = $this->auditRecorder->record(
                action: 'teaching.reset.started',
                resourceType: 'simulation',
                resourceId: 'synthetic-reset',
                actor: $actor instanceof User ? $actor : null,
                outcome: 'SUCCESS',
                reason: $reason,
                metadata: [
                    'purge_audit' => $purgeAudit,
                    'boundary' => 'synthetic_patient_graph',
                ],
                includeRequestFingerprint: false,
            );

            if ($started === null) {
                throw new RuntimeException('Synthetic reset refused because its start audit event could not be recorded.');
            }

            $deleted = Patient::query()->syntheticOnly()->delete();

            if ($purgeAudit) {
                DB::table('audit_events')->delete();
            }

            $completed = $this->auditRecorder->record(
                action: 'teaching.reset.completed',
                resourceType: 'simulation',
                resourceId: 'synthetic-reset',
                actor: $actor instanceof User ? $actor : null,
                outcome: 'SUCCESS',
                reason: $reason,
                metadata: [
                    'purge_audit' => $purgeAudit,
                    'boundary' => 'synthetic_patient_graph',
                    'deleted_patients' => $deleted,
                ],
                includeRequestFingerprint: false,
            );

            if ($completed === null) {
                throw new RuntimeException('Synthetic reset rolled back because its completion audit event could not be recorded.');
            }
        });
    }
}
