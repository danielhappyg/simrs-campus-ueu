<?php

namespace App\Support\Finance;

use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Auth\Access\AuthorizationException;

final class FinanceLaboratoryTariffActorPolicy
{
    public function canView(User $actor): bool
    {
        if ($actor->is_system_administrator) {
            return true;
        }

        return ($this->isExactRole($actor, RoleCapabilityMatrix::ROLE_FINANCE_STEWARD)
                || $this->isExactRole($actor, RoleCapabilityMatrix::ROLE_CASHIER))
            && $actor->canCapability(Capability::FINANCE_LABORATORY_TARIFF_VIEW);
    }

    public function canManage(User $actor): bool
    {
        if ($actor->is_system_administrator) {
            return true;
        }

        return $this->isExactRole($actor, RoleCapabilityMatrix::ROLE_FINANCE_STEWARD)
            && $actor->canCapability(Capability::FINANCE_LABORATORY_TARIFF_MANAGE);
    }

    public function view(User $actor): void
    {
        if (! $this->canView($actor)) {
            throw new AuthorizationException;
        }
    }

    public function manage(User $actor): void
    {
        if (! $this->canManage($actor)) {
            throw new AuthorizationException;
        }
    }

    private function isExactRole(User $actor, string $role): bool
    {
        return ! $actor->is_system_administrator && $actor->roleSlugs() === [$role];
    }
}
