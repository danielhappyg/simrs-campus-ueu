<?php

namespace App\Support\Inpatient;

use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Auth\Access\AuthorizationException;

final class InpatientMasterActorPolicy
{
    public function authorizeManage(User $actor): void
    {
        if (! $this->canManage($actor)) {
            throw new AuthorizationException;
        }
    }

    public function canManage(User $actor): bool
    {
        return $actor->canCapability(Capability::INPATIENT_WARD_BED_MANAGE)
            && ($actor->is_system_administrator || $actor->hasRole(RoleCapabilityMatrix::ROLE_ADMIN));
    }

    public function authorizeView(User $actor): void
    {
        if (! $this->canView($actor)) {
            throw new AuthorizationException;
        }
    }

    public function canView(User $actor): bool
    {
        if (! $actor->canCapability(Capability::INPATIENT_OCCUPANCY_VIEW)) {
            return false;
        }

        return $actor->is_system_administrator
            || array_intersect($actor->roleSlugs(), [
                RoleCapabilityMatrix::ROLE_REGISTRAR,
                RoleCapabilityMatrix::ROLE_NURSE,
                RoleCapabilityMatrix::ROLE_PHYSICIAN,
                RoleCapabilityMatrix::ROLE_RMIK,
                RoleCapabilityMatrix::ROLE_ADMIN,
            ]) !== [];
    }
}
