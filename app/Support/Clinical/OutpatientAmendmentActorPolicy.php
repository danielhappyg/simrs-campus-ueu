<?php

namespace App\Support\Clinical;

use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Auth\Access\AuthorizationException;

final class OutpatientAmendmentActorPolicy
{
    public function authorizePhysician(User $actor, string $capability): void
    {
        if (! $this->canPhysician($actor, $capability)) {
            throw new AuthorizationException;
        }
    }

    public function canPhysician(User $actor, string $capability): bool
    {
        if ($actor->is_system_administrator) {
            return true;
        }

        return ! $actor->hasRole(RoleCapabilityMatrix::ROLE_ADMIN)
            && $actor->hasRole(RoleCapabilityMatrix::ROLE_PHYSICIAN)
            && $actor->canCapability($capability);
    }

    public function authorizeRmik(User $actor, string $capability): void
    {
        if (! $this->canRmik($actor, $capability)) {
            throw new AuthorizationException;
        }
    }

    public function canRmik(User $actor, string $capability): bool
    {
        if ($actor->is_system_administrator) {
            return true;
        }

        return ! $actor->hasRole(RoleCapabilityMatrix::ROLE_ADMIN)
            && $actor->hasRole(RoleCapabilityMatrix::ROLE_RMIK)
            && $actor->canCapability($capability);
    }
}
