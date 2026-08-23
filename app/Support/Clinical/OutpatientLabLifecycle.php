<?php

namespace App\Support\Clinical;

use App\Models\ClinicalEntry;
use App\Models\Encounter;
use App\Models\LabDiagnosticResult;
use App\Models\LabServiceRequest;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OutpatientLabLifecycle
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function writeClinicalEntry(
        Encounter $encounter,
        User $actor,
        string $entryType,
        string $body,
    ): void {
        try {
            DB::transaction(function () use ($encounter, $actor, $entryType, $body): void {
                $lockedEncounter = $this->lockEncounter($encounter);

                if ($lockedEncounter->status === Encounter::STATUS_CLOSED) {
                    throw new OutpatientLifecycleDenial(
                        reason: 'encounter_closed',
                        message: 'Kunjungan sudah ditutup.',
                    );
                }

                ClinicalEntry::query()->create([
                    'encounter_id' => $lockedEncounter->id,
                    'author_user_id' => $actor->id,
                    'entry_type' => $entryType,
                    'body' => $body,
                ]);

                if ($entryType === ClinicalEntry::TYPE_MEDICAL_ASSESSMENT) {
                    $lockedEncounter->update(['status' => Encounter::STATUS_READY_FOR_RM]);
                } elseif ($lockedEncounter->status === Encounter::STATUS_REGISTERED) {
                    $lockedEncounter->update(['status' => Encounter::STATUS_IN_EXAMINATION]);
                }

                $this->recordSuccessOrFail(
                    action: 'clinical.note.write',
                    resourceType: 'encounter',
                    resourceId: $lockedEncounter->public_id,
                    actor: $actor,
                    metadata: ['entry_type' => $entryType],
                );
            });
        } catch (OutpatientLifecycleDenial $denial) {
            $this->recordDenialOrFail(
                action: 'clinical.note.write',
                resourceType: 'encounter',
                resourceId: $encounter->public_id,
                actor: $actor,
                denial: $denial,
                metadata: ['entry_type' => $entryType],
            );

            abort($denial->status, $denial->getMessage());
        }
    }

    /**
     * @param  array{code: string, label: string}  $test
     */
    public function createLabOrder(
        Encounter $encounter,
        User $actor,
        array $test,
        ?string $clinicalQuestion,
    ): LabServiceRequest {
        try {
            return DB::transaction(function () use ($encounter, $actor, $test, $clinicalQuestion): LabServiceRequest {
                $lockedEncounter = $this->lockEncounter($encounter);

                if ($lockedEncounter->status === Encounter::STATUS_CLOSED) {
                    throw new OutpatientLifecycleDenial(
                        reason: 'encounter_closed',
                        message: 'Kunjungan sudah ditutup.',
                    );
                }

                $order = LabServiceRequest::query()->create([
                    'encounter_id' => $lockedEncounter->id,
                    'requested_by_user_id' => $actor->id,
                    'test_code' => $test['code'],
                    'test_label' => $test['label'],
                    'clinical_question' => $clinicalQuestion,
                    'status' => LabServiceRequest::STATUS_ACTIVE,
                    'requested_at' => now(),
                ]);

                $this->recordSuccessOrFail(
                    action: 'clinical.lab.order.create',
                    resourceType: 'lab_service_request',
                    resourceId: $order->public_id,
                    actor: $actor,
                    metadata: [
                        'encounter_id' => $lockedEncounter->public_id,
                        'test_code' => $test['code'],
                    ],
                );

                return $order;
            });
        } catch (OutpatientLifecycleDenial $denial) {
            $this->recordDenialOrFail(
                action: 'clinical.lab.order.create',
                resourceType: 'encounter',
                resourceId: $encounter->public_id,
                actor: $actor,
                denial: $denial,
                metadata: ['test_code' => $test['code']],
            );

            abort($denial->status, $denial->getMessage());
        }
    }

    public function writeFinalLabResult(
        LabServiceRequest $order,
        User $actor,
        string $resultText,
    ): void {
        try {
            DB::transaction(function () use ($order, $actor, $resultText): void {
                // Every lifecycle writer locks the encounter first. This makes RM
                // closure serialize with result entry and order creation.
                $lockedEncounter = Encounter::query()
                    ->whereKey($order->encounter_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_unless(
                    $lockedEncounter->care_setting === Encounter::CARE_SETTING_OUTPATIENT,
                    404,
                );

                $lockedOrder = LabServiceRequest::query()
                    ->whereKey($order->id)
                    ->where('encounter_id', $lockedEncounter->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedEncounter->status === Encounter::STATUS_CLOSED) {
                    throw new OutpatientLifecycleDenial(
                        reason: 'encounter_closed',
                        message: 'Kunjungan sudah ditutup; hasil lab terlambat tidak dapat dicatat.',
                    );
                }

                if ($lockedOrder->result()->exists()) {
                    throw new OutpatientLifecycleDenial(
                        reason: 'result_already_final',
                        message: 'Hasil final sudah tercatat dan tidak dapat diubah.',
                    );
                }

                if ($lockedOrder->status !== LabServiceRequest::STATUS_ACTIVE) {
                    throw new OutpatientLifecycleDenial(
                        reason: 'order_not_active',
                        message: 'Order lab sudah selesai atau dibatalkan.',
                    );
                }

                LabDiagnosticResult::query()->create([
                    'lab_service_request_id' => $lockedOrder->id,
                    'entered_by_user_id' => $actor->id,
                    'status' => LabDiagnosticResult::STATUS_FINAL,
                    'result_text' => $resultText,
                    'issued_at' => now(),
                ]);

                $lockedOrder->update(['status' => LabServiceRequest::STATUS_COMPLETED]);

                $this->recordSuccessOrFail(
                    action: 'clinical.lab.result.write',
                    resourceType: 'lab_service_request',
                    resourceId: $lockedOrder->public_id,
                    actor: $actor,
                    metadata: [
                        'encounter_id' => $lockedEncounter->public_id,
                        'test_code' => $lockedOrder->test_code,
                        'result_status' => LabDiagnosticResult::STATUS_FINAL,
                    ],
                );
            });
        } catch (OutpatientLifecycleDenial $denial) {
            $this->recordDenialOrFail(
                action: 'clinical.lab.result.write',
                resourceType: 'lab_service_request',
                resourceId: $order->public_id,
                actor: $actor,
                denial: $denial,
                metadata: ['encounter_id' => $order->encounter?->public_id],
            );

            abort($denial->status, $denial->getMessage());
        }
    }

    public function closeEncounter(Encounter $encounter, User $actor): void
    {
        try {
            DB::transaction(function () use ($encounter, $actor): void {
                $lockedEncounter = $this->lockEncounter($encounter);

                abort_unless(
                    $lockedEncounter->status === Encounter::STATUS_READY_FOR_RM,
                    422,
                    'Kunjungan belum siap untuk penutupan RM.',
                );

                $activeOrderIds = LabServiceRequest::query()
                    ->where('encounter_id', $lockedEncounter->id)
                    ->where('status', LabServiceRequest::STATUS_ACTIVE)
                    ->lockForUpdate()
                    ->pluck('id');

                if ($activeOrderIds->isNotEmpty()) {
                    throw new OutpatientLifecycleDenial(
                        reason: 'active_lab_orders',
                        message: 'Kunjungan belum dapat ditutup karena masih ada order lab aktif.',
                        metadata: ['active_lab_order_count' => $activeOrderIds->count()],
                    );
                }

                $lockedEncounter->update(['status' => Encounter::STATUS_CLOSED]);

                $this->recordSuccessOrFail(
                    action: 'rmik.review.complete',
                    resourceType: 'encounter',
                    resourceId: $lockedEncounter->public_id,
                    actor: $actor,
                    metadata: [
                        'previous_status' => Encounter::STATUS_READY_FOR_RM,
                        'new_status' => Encounter::STATUS_CLOSED,
                    ],
                );
            });
        } catch (OutpatientLifecycleDenial $denial) {
            $this->recordDenialOrFail(
                action: 'rmik.review.complete',
                resourceType: 'encounter',
                resourceId: $encounter->public_id,
                actor: $actor,
                denial: $denial,
            );

            abort($denial->status, $denial->getMessage());
        }
    }

    private function lockEncounter(Encounter $encounter): Encounter
    {
        $lockedEncounter = Encounter::query()
            ->whereKey($encounter->id)
            ->lockForUpdate()
            ->firstOrFail();

        abort_unless(
            $lockedEncounter->care_setting === Encounter::CARE_SETTING_OUTPATIENT,
            404,
        );

        return $lockedEncounter;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordSuccessOrFail(
        string $action,
        string $resourceType,
        string $resourceId,
        User $actor,
        array $metadata,
    ): void {
        $event = $this->auditRecorder->record(
            action: $action,
            resourceType: $resourceType,
            resourceId: $resourceId,
            actor: $actor,
            outcome: 'SUCCESS',
            metadata: $metadata,
        );

        if ($event === null) {
            throw new RuntimeException("Audit wajib gagal direkam untuk aksi {$action}.");
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordDenialOrFail(
        string $action,
        string $resourceType,
        string $resourceId,
        User $actor,
        OutpatientLifecycleDenial $denial,
        array $metadata = [],
    ): void {
        $event = $this->auditRecorder->record(
            action: $action,
            resourceType: $resourceType,
            resourceId: $resourceId,
            actor: $actor,
            outcome: 'DENIED',
            reason: $denial->reason,
            metadata: array_merge($metadata, $denial->metadata),
        );

        if ($event === null) {
            throw new RuntimeException("Audit penolakan gagal direkam untuk aksi {$action}.");
        }
    }
}

final class OutpatientLifecycleDenial extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
        public readonly array $metadata = [],
    ) {
        parent::__construct($message);
    }
}
