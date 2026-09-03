<?php

namespace App\Support\Finance;

use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Auth\Access\AuthorizationException;

final class FinanceCashierCollectionActorPolicy
{
    public function view(User $actor): void
    {
        if ($actor->is_system_administrator) {
            return;
        }

        $roles = $actor->roleSlugs();
        if (count($roles) !== 1
            || ! in_array($roles[0], [RoleCapabilityMatrix::ROLE_CASHIER, RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR], true)
            || ! $actor->canCapability(Capability::FINANCE_CASHIER_COLLECTION_VIEW)) {
            throw new AuthorizationException;
        }
    }

    public function viewHandoff(User $actor): void
    {
        $this->view($actor);
        if ($actor->is_system_administrator) {
            return;
        }
        if (! $actor->canCapability(Capability::FINANCE_CASH_DEPOSIT_HANDOFF_VIEW)) {
            throw new AuthorizationException;
        }
    }

    public function open(User $actor): void
    {
        $this->exact($actor, RoleCapabilityMatrix::ROLE_CASHIER, Capability::FINANCE_CASHIER_COLLECTION_OPEN);
    }

    public function closeRequest(User $actor): void
    {
        $this->exact($actor, RoleCapabilityMatrix::ROLE_CASHIER, Capability::FINANCE_CASHIER_COLLECTION_CLOSE_REQUEST);
    }

    public function recount(User $actor): void
    {
        $this->exact($actor, RoleCapabilityMatrix::ROLE_CASHIER, Capability::FINANCE_CASHIER_COLLECTION_RECOUNT);
    }

    public function verify(User $actor): void
    {
        $this->exact($actor, RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR, Capability::FINANCE_CASHIER_COLLECTION_VERIFY);
    }

    public function createHandoff(User $actor): void
    {
        $this->exact($actor, RoleCapabilityMatrix::ROLE_CASHIER, Capability::FINANCE_CASH_DEPOSIT_HANDOFF_CREATE);
    }

    private function exact(User $actor, string $role, string $capability): void
    {
        if ($actor->is_system_administrator) {
            return;
        }

        if ($actor->roleSlugs() !== [$role] || ! $actor->canCapability($capability)) {
            throw new AuthorizationException;
        }
    }
}
