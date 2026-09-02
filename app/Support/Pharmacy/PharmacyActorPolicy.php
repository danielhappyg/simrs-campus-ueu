<?php

namespace App\Support\Pharmacy;

use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Auth\Access\AuthorizationException;

final class PharmacyActorPolicy
{
    public function can(User $actor, string $role, string $capability): bool
    {
        return ! $actor->is_system_administrator
            && $actor->roleSlugs() === [$role]
            && $actor->canCapability($capability);
    }

    public function authorize(User $actor, string $role, string $capability): void
    {
        if (! $this->can($actor, $role, $capability)) {
            throw new AuthorizationException;
        }
    }

    public function prescribe(User $actor): void
    {
        $this->authorize($actor, RoleCapabilityMatrix::ROLE_PHYSICIAN, Capability::PHARMACY_PRESCRIPTION_WRITE);
    }

    public function verify(User $actor): void
    {
        $this->authorize($actor, RoleCapabilityMatrix::ROLE_PHARMACIST, Capability::PHARMACY_PRESCRIPTION_VERIFY);
    }

    public function prepare(User $actor): void
    {
        $this->authorize($actor, RoleCapabilityMatrix::ROLE_PHARMACY_TECHNICIAN, Capability::PHARMACY_DISPENSE_PREPARE);
    }

    public function handover(User $actor): void
    {
        $this->authorize($actor, RoleCapabilityMatrix::ROLE_PHARMACIST, Capability::PHARMACY_DISPENSE_HANDOVER);
    }

    public function return(User $actor): void
    {
        $this->authorize($actor, RoleCapabilityMatrix::ROLE_PHARMACIST, Capability::PHARMACY_RETURN_RECORD);
    }

    public function inventory(User $actor): void
    {
        $this->authorize($actor, RoleCapabilityMatrix::ROLE_PHARMACY_INVENTORY_CONTROLLER, Capability::PHARMACY_INVENTORY_MANAGE);
    }
}
