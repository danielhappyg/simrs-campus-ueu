<?php

namespace App\Support\Laboratory;

use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Auth\Access\AuthorizationException;

final class LaboratoryActorPolicy
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
        $this->authorize($a, RoleCapabilityMatrix::ROLE_ADMIN, Capability::LABORATORY_MASTER_MANAGE);
    }

    public function order(User $a): void
    {
        $this->authorize($a, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::LABORATORY_ORDER_CREATE);
    }

    public function cancel(User $a): void
    {
        $this->authorize($a, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::LABORATORY_ORDER_CANCEL);
    }

    public function collect(User $a): void
    {
        $this->authorize($a, RoleCapabilityMatrix::ROLE_NURSE, Capability::LABORATORY_SPECIMEN_COLLECT);
    }

    public function receive(User $a): void
    {
        $this->authorize($a, RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST, Capability::LABORATORY_SPECIMEN_PROCESS);
    }

    public function assess(User $a): void
    {
        $this->receive($a);
    }

    public function write(User $a): void
    {
        $this->authorize($a, RoleCapabilityMatrix::ROLE_LABORATORY_TECHNOLOGIST, Capability::LABORATORY_RESULT_WRITE);
    }

    public function verify(User $a): void
    {
        $this->authorize($a, RoleCapabilityMatrix::ROLE_LABORATORY_VERIFIER, Capability::LABORATORY_RESULT_VERIFY);
    }

    public function acknowledge(User $a): void
    {
        $this->authorize($a, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::LABORATORY_RESULT_ACKNOWLEDGE);
    }
}
