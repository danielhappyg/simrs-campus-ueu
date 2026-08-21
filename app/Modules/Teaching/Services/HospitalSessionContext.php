<?php

namespace App\Modules\Teaching\Services;

use App\Models\User;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use Illuminate\Support\Collection;

class HospitalSessionContext
{
    /**
     * @return Collection<int, Assignment>
     */
    public function activeAssignments(User $user): Collection
    {
        return Assignment::query()
            ->active()
            ->where('user_id', $user->getKey())
            ->whereRelation('session', 'status', SessionStatus::Active->value)
            ->with(['session.scenario'])
            ->orderBy('active_from')
            ->get();
    }

    /**
     * @param  Collection<int, Assignment>  $assignments
     */
    public function resolveSession(
        Collection $assignments,
        ?string $requestedSessionCode = null,
        bool $requireExplicitWhenMultiple = false,
    ): ?SimulationSession {
        if ($assignments->isEmpty()) {
            return null;
        }

        if (is_string($requestedSessionCode) && $requestedSessionCode !== '') {
            $match = $assignments->first(
                fn (Assignment $assignment): bool => $assignment->session->code === $requestedSessionCode,
            );

            return $match?->session;
        }

        $uniqueSessions = $assignments->unique('session_id');

        if ($uniqueSessions->count() === 1) {
            return $uniqueSessions->first()?->session;
        }

        if ($requireExplicitWhenMultiple) {
            return null;
        }

        return $uniqueSessions->sortBy(fn (Assignment $assignment): string => $assignment->session->code)
            ->first()
            ?->session;
    }

    /**
     * @param  Collection<int, Assignment>  $assignments
     * @return list<array{code: string, publicId: string, courseCode: string|null, scenarioTitle: string|null}>
     */
    public function sessionOptions(Collection $assignments): array
    {
        return array_values($assignments
            ->unique('session_id')
            ->map(fn (Assignment $assignment): array => [
                'code' => $assignment->session->code,
                'publicId' => $assignment->session->public_id,
                'courseCode' => $assignment->session->course_code,
                'scenarioTitle' => $assignment->session->scenario->title,
            ])
            ->sortBy('code')
            ->all());
    }
}
