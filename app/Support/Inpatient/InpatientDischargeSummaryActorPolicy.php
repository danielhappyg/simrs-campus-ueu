<?php

namespace App\Support\Inpatient;

use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Auth\Access\AuthorizationException;

final class InpatientDischargeSummaryActorPolicy
{
    public function authorize(User $actor): void
    {
        if (! $this->can($actor)) {
            throw new AuthorizationException;
        }
    }

    public function can(User $actor): bool
    {
        return ! $actor->is_system_administrator
            && ! $actor->hasRole(RoleCapabilityMatrix::ROLE_ADMIN)
            && $actor->hasRole(RoleCapabilityMatrix::ROLE_PHYSICIAN)
            && $actor->canCapability(Capability::INPATIENT_DISCHARGE_SUMMARY_WRITE);
    }
}
