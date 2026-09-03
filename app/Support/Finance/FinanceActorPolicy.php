<?php

namespace App\Support\Finance;

use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Auth\Access\AuthorizationException;

final class FinanceActorPolicy
{
    public function can(User $actor, string $capability): bool
    {
        if ($actor->is_system_administrator) {
            return true;
        }

        return $actor->roleSlugs() === [RoleCapabilityMatrix::ROLE_CASHIER]
            && $actor->canCapability($capability);
    }

    public function view(User $actor): void
    {
        if (! $this->can($actor, Capability::FINANCE_BILL_VIEW)) {
            throw new AuthorizationException;
        }
    }

    public function issue(User $actor): void
    {
        if (! $this->can($actor, Capability::FINANCE_BILL_ISSUE)) {
            throw new AuthorizationException;
        }
    }
}
