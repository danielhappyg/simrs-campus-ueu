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

    /** @param array{actor?: ?User, reason?: ?string} $options */
    public function reset(array $options = []): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Synthetic reset requires SIMULATION mode with synthetic-only data enforced.');
        }

        $actor = $options['actor'] ?? null;
        $reason = $options['reason'] ?? 'simulation_reset';

        DB::transaction(function () use ($actor, $reason): void {
            $started = $this->auditRecorder->record(
                action: 'teaching.reset.started',
                resourceType: 'simulation',
                resourceId: 'synthetic-reset',
                actor: $actor instanceof User ? $actor : null,
                outcome: 'SUCCESS',
                reason: $reason,
                metadata: [
                    'boundary' => 'synthetic_patient_graph',
                    'evidence_preserved' => true,
                ],
                includeRequestFingerprint: false,
            );

            if ($started === null) {
                throw new RuntimeException('Synthetic reset refused because its start audit event could not be recorded.');
            }

            $deleted = Patient::query()->syntheticOnly()->delete();

            $completed = $this->auditRecorder->record(
                action: 'teaching.reset.completed',
                resourceType: 'simulation',
                resourceId: 'synthetic-reset',
                actor: $actor instanceof User ? $actor : null,
                outcome: 'SUCCESS',
                reason: $reason,
                metadata: [
                    'boundary' => 'synthetic_patient_graph',
                    'deleted_patients' => $deleted,
                    'evidence_preserved' => true,
                ],
                includeRequestFingerprint: false,
            );

            if ($completed === null) {
                throw new RuntimeException('Synthetic reset rolled back because its completion audit event could not be recorded.');
            }
        });
    }
}
