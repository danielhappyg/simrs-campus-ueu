<?php

namespace App\Modules\Teaching\Services;

use App\Models\User;
use App\Modules\Encounter\Enums\EncounterStatus;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

class AssignmentContextResolver
{
    public function forSession(User $user, SimulationSession $session, Capability $capability): Assignment
    {
        $this->assertUsableSession($session);

        $assignment = $this->activeAssignments($user, $session)
            ->first(fn (Assignment $assignment): bool => $assignment->hasCapability($capability));

        if (! $assignment) {
            throw new AuthorizationException('The active assignment does not permit this action.');
        }

        return $assignment;
    }

    public function forEncounter(User $user, Encounter $encounter, Capability $capability): Assignment
    {
        return $this->forEncounterAny($user, $encounter, [$capability]);
    }

    public function forDebrief(User $user, Encounter $encounter): Assignment
    {
        $session = $encounter->session;

        if ($session->environment_mode !== EnvironmentMode::Simulation
            || ! in_array($session->status, [SessionStatus::Active, SessionStatus::Completed], true)) {
            throw new AuthorizationException('The simulation session is not available for debrief.');
        }

        $assignment = $this->activeAssignments($user, $session)
            ->first(function (Assignment $assignment) use ($encounter): bool {
                if (! $assignment->hasCapability(Capability::DebriefView)) {
                    return false;
                }

                $matchesCase = $assignment->patient_id === $encounter->patient_id
                    && $assignment->encounter_id === $encounter->getKey();
                $sessionWideDebrief = $assignment->patient_id === null
                    && $assignment->encounter_id === null;

                return $matchesCase || $sessionWideDebrief;
            });

        if (! $assignment) {
            throw new AuthorizationException('The active assignment does not permit access to this debrief.');
        }

        return $assignment;
    }

    public function forRecordTimeline(User $user, Encounter $encounter): Assignment
    {
        $assignment = $this->recordTimelineAssignment($user, $encounter);

        if (! $assignment) {
            throw new AuthorizationException('The active assignment does not permit access to this longitudinal record.');
        }

        return $assignment;
    }

    public function canViewRecordTimeline(User $user, Encounter $encounter): bool
    {
        return $this->recordTimelineAssignment($user, $encounter) instanceof Assignment;
    }

    public function forDebriefWrite(User $user, Encounter $encounter): Assignment
    {
        $session = $encounter->session;

        if ($session->environment_mode !== EnvironmentMode::Simulation
            || ! in_array($session->status, [SessionStatus::Active, SessionStatus::Completed], true)) {
            throw new AuthorizationException('The simulation session is not available for debrief authorship.');
        }

        $assignment = $this->activeAssignments($user, $session)
            ->first(fn (Assignment $assignment): bool => $assignment->hasCapability(Capability::DebriefWrite)
                && $this->matchesDebriefCase($assignment, $encounter));

        if (! $assignment) {
            throw new AuthorizationException('The active assignment does not permit debrief authorship.');
        }

        return $assignment;
    }

    public function forReport(User $user, Encounter $encounter): Assignment
    {
        $session = $encounter->session;

        if ($session->environment_mode !== EnvironmentMode::Simulation
            || ! in_array($session->status, [SessionStatus::Active, SessionStatus::Completed], true)) {
            throw new AuthorizationException('The simulation session is not available for finalized reports.');
        }

        $assignment = $this->activeAssignments($user, $session)
            ->first(fn (Assignment $assignment): bool => $assignment->hasCapability(Capability::ReportView)
                && $this->matchesDebriefCase($assignment, $encounter));

        if (! $assignment) {
            throw new AuthorizationException('The active assignment does not permit access to this finalized report.');
        }

        return $assignment;
    }

    public function writerForDebrief(User $user, Encounter $encounter): ?Assignment
    {
        if ($encounter->status !== EncounterStatus::Finalized
            || $encounter->session->environment_mode !== EnvironmentMode::Simulation
            || $encounter->session->status !== SessionStatus::Active) {
            return null;
        }

        return $this->activeAssignments($user, $encounter->session)
            ->first(fn (Assignment $assignment): bool => $assignment->hasCapability(Capability::DebriefWrite)
                && $this->matchesDebriefCase($assignment, $encounter));
    }

    /** @param  list<Capability>  $capabilities */
    public function forEncounterAny(User $user, Encounter $encounter, array $capabilities): Assignment
    {
        $this->assertUsableSession($encounter->session);

        $assignment = $this->activeAssignments($user, $encounter->session)
            ->first(function (Assignment $assignment) use ($capabilities, $encounter): bool {
                $hasRequestedCapability = collect($capabilities)
                    ->contains(fn (Capability $capability): bool => $assignment->hasCapability($capability));

                if (! $hasRequestedCapability) {
                    return false;
                }

                $matchesCase = $assignment->patient_id === $encounter->patient_id
                    && $assignment->encounter_id === $encounter->getKey();
                $sessionWideRegistration = $assignment->patient_id === null
                    && $assignment->encounter_id === null
                    && ($assignment->hasCapability(Capability::PatientRegister)
                        || $assignment->hasCapability(Capability::SessionFacilitate));

                return $matchesCase || $sessionWideRegistration;
            });

        if (! $assignment) {
            throw new AuthorizationException('The active assignment does not permit access to this encounter.');
        }

        return $assignment;
    }

    /**
     * @return Collection<int, Assignment>
     */
    private function activeAssignments(User $user, SimulationSession $session): Collection
    {
        return Assignment::query()
            ->active()
            ->where('user_id', $user->getKey())
            ->where('session_id', $session->getKey())
            ->get();
    }

    private function matchesDebriefCase(Assignment $assignment, Encounter $encounter): bool
    {
        $matchesCase = $assignment->patient_id === $encounter->patient_id
            && $assignment->encounter_id === $encounter->getKey();
        $sessionWide = $assignment->patient_id === null
            && $assignment->encounter_id === null;

        return $matchesCase || $sessionWide;
    }

    private function recordTimelineAssignment(User $user, Encounter $encounter): ?Assignment
    {
        $session = $encounter->session;

        if ($session->environment_mode !== EnvironmentMode::Simulation
            || ! in_array($session->status, [SessionStatus::Active, SessionStatus::Completed], true)) {
            return null;
        }

        return $this->activeAssignments($user, $session)
            ->first(function (Assignment $assignment) use ($encounter): bool {
                if (! $assignment->hasCapability(Capability::SessionView)) {
                    return false;
                }

                $matchesCase = $assignment->patient_id === $encounter->patient_id
                    && $assignment->encounter_id === $encounter->getKey();
                $sessionWideFacilitator = $assignment->patient_id === null
                    && $assignment->encounter_id === null
                    && $assignment->hasCapability(Capability::SessionFacilitate);

                return $matchesCase || $sessionWideFacilitator;
            });
    }

    private function assertUsableSession(SimulationSession $session): void
    {
        if ($session->environment_mode !== EnvironmentMode::Simulation || $session->status !== SessionStatus::Active) {
            throw new AuthorizationException('The simulation session is not active for patient-context work.');
        }
    }
}
