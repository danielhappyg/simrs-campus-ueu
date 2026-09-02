<?php

namespace App\Support\Radiology;

use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Auth\Access\AuthorizationException;

final class RadiologyActorPolicy
{
    public function can(User $actor, string $role, string $capability): bool
    {
        return ! $actor->is_system_administrator && $actor->roleSlugs() === [$role] && $actor->canCapability($capability);
    }

    public function authorize(User $actor, string $role, string $capability): void
    {
        if (! $this->can($actor, $role, $capability)) {
            throw new AuthorizationException;
        }
    }

    public function master(User $a): void
    {
        if ($a->is_system_administrator || $a->roleSlugs() !== [RoleCapabilityMatrix::ROLE_ADMIN] || ! $a->canCapability(Capability::RADIOLOGY_MASTER_MANAGE)) {
            throw new AuthorizationException;
        }
    }

    public function order(User $a): void
    {
        $this->authorize($a, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::RADIOLOGY_ORDER_CREATE);
    }

    public function cancel(User $a): void
    {
        $this->authorize($a, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::RADIOLOGY_ORDER_CANCEL);
    }

    public function perform(User $a): void
    {
        $this->authorize($a, RoleCapabilityMatrix::ROLE_RADIOLOGY_TECHNOLOGIST, Capability::RADIOLOGY_WORKLIST_PERFORM);
    }

    public function write(User $a): void
    {
        $this->authorize($a, RoleCapabilityMatrix::ROLE_RADIOLOGIST, Capability::RADIOLOGY_REPORT_WRITE);
    }

    public function verify(User $a): void
    {
        $this->authorize($a, RoleCapabilityMatrix::ROLE_RADIOLOGIST, Capability::RADIOLOGY_REPORT_VERIFY);
    }

    public function acknowledge(User $a): void
    {
        $this->authorize($a, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::RADIOLOGY_REPORT_ACKNOWLEDGE);
    }
}
