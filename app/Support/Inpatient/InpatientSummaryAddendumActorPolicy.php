<?php

namespace App\Support\Inpatient;

use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Auth\Access\AuthorizationException;

final class InpatientSummaryAddendumActorPolicy
{
    public function physician(User $actor, string $capability): void
    {
        $this->exact($actor, RoleCapabilityMatrix::ROLE_PHYSICIAN, $capability);
    }

    public function rmik(User $actor, string $capability): void
    {
        $this->exact($actor, RoleCapabilityMatrix::ROLE_RMIK, $capability);
    }

    private function exact(User $actor, string $role, string $capability): void
    {
        if ($actor->is_system_administrator || $actor->hasRole(RoleCapabilityMatrix::ROLE_ADMIN) || ! $actor->hasRole($role) || ! $actor->canCapability($capability)) {
            throw new AuthorizationException;
        }
    }
}
