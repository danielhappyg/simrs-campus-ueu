<?php

namespace App\Support\Emergency;

use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Auth\Access\AuthorizationException;

final class EmergencyActorPolicy
{
    public function can(User $actor, string $role, string $capability): bool
    {
        return ! $actor->is_system_administrator
            && $actor->status === 'ACTIVE'
            && $actor->roleSlugs() === [$role]
            && $actor->canCapability($capability);
    }

    public function authorize(User $actor, string $role, string $capability): void
    {
        if (! $this->can($actor, $role, $capability)) {
            throw new AuthorizationException;
        }
    }

    public function master(User $actor): void
    {
        $this->authorize($actor, RoleCapabilityMatrix::ROLE_ADMIN, Capability::EMERGENCY_TRIAGE_MASTER_MANAGE);
    }

    public function triage(User $actor): void
    {
        $this->authorize($actor, RoleCapabilityMatrix::ROLE_NURSE, Capability::EMERGENCY_TRIAGE_WRITE);
    }

    public function nursingDocument(User $actor): void
    {
        $this->triage($actor);
    }

    public function medicalDocument(User $actor): void
    {
        $this->authorize($actor, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::EMERGENCY_DISPOSITION_WRITE);
    }

    public function disposition(User $actor): void
    {
        $this->medicalDocument($actor);
    }

    public function followUp(User $actor): void
    {
        $this->authorize($actor, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::EMERGENCY_RESULT_FOLLOW_UP);
    }

    public function inpatientHandoff(User $actor): void
    {
        $this->authorize($actor, RoleCapabilityMatrix::ROLE_REGISTRAR, Capability::EMERGENCY_INPATIENT_HANDOFF);
    }

    public function dispositionCompensation(User $actor): void
    {
        $this->authorize($actor, RoleCapabilityMatrix::ROLE_REGISTRAR, Capability::EMERGENCY_DISPOSITION_COMPENSATE);
    }
}
