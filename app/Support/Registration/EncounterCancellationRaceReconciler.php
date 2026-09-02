<?php

namespace App\Support\Registration;

use App\Models\Encounter;
use App\Models\EncounterCancellation;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\UniqueConstraintViolationException;

final class EncounterCancellationRaceReconciler
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function resolve(
        Encounter $encounter,
        User $actor,
        string $idempotencyKey,
        string $digest,
        UniqueConstraintViolationException $race,
    ): EncounterCancellationResult {
        $keyMatch = EncounterCancellation::query()
            ->where('cancelled_by_user_id', $actor->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($keyMatch instanceof EncounterCancellation
            && $keyMatch->encounter_id === $encounter->id
            && hash_equals($keyMatch->payload_digest, $digest)) {
            return new EncounterCancellationResult($keyMatch, replayed: true);
        }

        $denial = $keyMatch instanceof EncounterCancellation
            ? new EncounterCancellationDenied(
                'idempotency_key_conflict',
                'Kunci idempotensi sudah digunakan untuk permintaan pembatalan yang berbeda.',
            )
            : (EncounterCancellation::query()->where('encounter_id', $encounter->id)->exists()
                ? new EncounterCancellationDenied('already_cancelled', 'Kunjungan sudah dibatalkan.')
                : null);

        if (! $denial instanceof EncounterCancellationDenied) {
            throw $race;
        }

        $event = $this->auditRecorder->record(
            action: 'encounter.cancel',
            resourceType: 'encounter',
            resourceId: $encounter->public_id,
            actor: $actor,
            outcome: 'DENIED',
            reason: $denial->reason,
            metadata: ['care_setting' => $encounter->care_setting],
        );

        if ($event === null) {
            throw new EncounterCancellationAuditUnavailable('Penolakan pembatalan tidak dapat direkam dalam audit.');
        }

        throw $denial;
    }
}
