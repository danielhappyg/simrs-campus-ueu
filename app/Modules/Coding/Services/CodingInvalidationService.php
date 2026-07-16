<?php

namespace App\Modules\Coding\Services;

use App\Modules\Clinical\Models\ClinicalEntryVersion;
use App\Modules\Clinical\Models\EncounterClosure;
use App\Modules\Coding\Enums\CodingAssignmentStatus;
use App\Modules\Coding\Enums\CodingSourceType;
use App\Modules\Coding\Models\CodingAssignment;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Teaching\Enums\WorkTaskStatus;
use App\Modules\Teaching\Enums\WorkTaskType;
use App\Modules\Teaching\Models\WorkTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CodingInvalidationService
{
    public function invalidateForMedicalAmendment(Encounter $encounter, ClinicalEntryVersion $replacement): int
    {
        return DB::transaction(function () use ($encounter, $replacement): int {
            $assignments = CodingAssignment::query()
                ->where('encounter_id', $encounter->getKey())
                ->where('source_type', CodingSourceType::Diagnosis)
                ->where('source_entry_version_id', '!=', $replacement->getKey())
                ->where('status', '!=', CodingAssignmentStatus::ReviewRequired->value)
                ->lockForUpdate()
                ->get();

            foreach ($assignments as $assignment) {
                $assignment->persistStatus(CodingAssignmentStatus::ReviewRequired, CarbonImmutable::now());
                $this->cancelPendingReviewTask($encounter, $assignment);
            }

            return $assignments->count();
        });
    }

    public function invalidateForClosureAmendment(Encounter $encounter, EncounterClosure $replacement): int
    {
        return DB::transaction(function () use ($encounter, $replacement): int {
            $assignments = CodingAssignment::query()
                ->where('encounter_id', $encounter->getKey())
                ->where('source_type', CodingSourceType::Procedure)
                ->whereHas(
                    'sourceProcedure',
                    fn ($query) => $query->where('encounter_closure_id', '!=', $replacement->getKey()),
                )
                ->where('status', '!=', CodingAssignmentStatus::ReviewRequired->value)
                ->lockForUpdate()
                ->get();

            foreach ($assignments as $assignment) {
                $assignment->persistStatus(CodingAssignmentStatus::ReviewRequired, CarbonImmutable::now());
                $this->cancelPendingReviewTask($encounter, $assignment);
            }

            return $assignments->count();
        });
    }

    private function cancelPendingReviewTask(Encounter $encounter, CodingAssignment $assignment): void
    {
        WorkTask::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('task_type', WorkTaskType::CodingReview)
            ->whereNotIn('status', [WorkTaskStatus::Complete->value, WorkTaskStatus::Cancelled->value])
            ->get()
            ->filter(fn (WorkTask $task): bool => data_get($task->context, 'codingAssignmentPublicId') === $assignment->public_id)
            ->each(function (WorkTask $task): void {
                $task->forceFill([
                    'status' => WorkTaskStatus::Cancelled,
                    'completed_at' => now(),
                ])->save();
            });
    }
}
