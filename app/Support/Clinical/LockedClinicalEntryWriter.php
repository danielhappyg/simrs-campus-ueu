<?php

namespace App\Support\Clinical;

use App\Models\ClinicalEntry;
use App\Models\Encounter;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

final class LockedClinicalEntryWriter
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function write(
        Encounter $encounter,
        User $actor,
        string $expectedCareSetting,
        string $entryType,
        string $body,
    ): ClinicalEntry {
        try {
            return DB::transaction(function () use (
                $encounter,
                $actor,
                $expectedCareSetting,
                $entryType,
                $body,
            ): ClinicalEntry {
                $lockedEncounter = Encounter::query()
                    ->whereKey($encounter->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_unless(
                    $lockedEncounter->patient()
                        ->where('is_synthetic', true)
                        ->exists(),
                    404,
                );
                abort_unless($lockedEncounter->care_setting === $expectedCareSetting, 404);
                if ($lockedEncounter->isCancelled()) {
                    throw new CancelledClinicalEntryDenied(
                        encounterPublicId: $lockedEncounter->public_id,
                        careSetting: $lockedEncounter->care_setting,
                    );
                }
                abort_unless(
                    in_array($lockedEncounter->status, Encounter::EXAMINATION_STATUSES, true),
                    422,
                    'Kunjungan tidak dapat menerima catatan klinis.',
                );

                $entry = ClinicalEntry::query()->create([
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

                $event = $this->auditRecorder->record(
                    action: 'clinical.note.write',
                    resourceType: 'encounter',
                    resourceId: $lockedEncounter->public_id,
                    actor: $actor,
                    outcome: 'SUCCESS',
                    metadata: [
                        'care_setting' => $expectedCareSetting,
                        'entry_type' => $entryType,
                    ],
                );

                abort_if($event === null, 503, 'Aksi tidak dapat diselesaikan karena audit gagal direkam.');

                return $entry;
            }, 3);
        } catch (CancelledClinicalEntryDenied $denial) {
            $event = $this->auditRecorder->record(
                action: 'clinical.note.write',
                resourceType: 'encounter',
                resourceId: $denial->encounterPublicId,
                actor: $actor,
                outcome: 'DENIED',
                reason: 'encounter_cancelled',
                metadata: ['care_setting' => $denial->careSetting],
            );

            abort_if($event === null, 503, 'Penolakan catatan klinis tidak dapat direkam dalam audit.');
            abort(422, $denial->getMessage());
        }
    }
}
