<?php

namespace App\Support\Inpatient;

use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Auth\Access\AuthorizationException;

final class InpatientRmActorPolicy
{
    public function authorizeCoding(User $actor): void
    {
        $this->authorize($actor, Capability::RMIK_CODING_WRITE);
    }

    public function authorizeReview(User $actor): void
    {
        $this->authorize($actor, Capability::RMIK_REVIEW);
    }

    public function authorizeSignoff(User $actor): void
    {
        $this->authorize($actor, Capability::RMIK_COMPLETENESS_SIGNOFF);
    }

    private function authorize(User $actor, string $capability): void
    {
        if ($actor->is_system_administrator) {
            return;
        }

        if ($actor->hasRole(RoleCapabilityMatrix::ROLE_ADMIN)
            || ! $actor->hasRole(RoleCapabilityMatrix::ROLE_RMIK)
            || ! $actor->canCapability($capability)) {
            throw new AuthorizationException;
        }
    }
}
