<?php

namespace App\Support\Finance;

use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Auth\Access\AuthorizationException;

final class FinanceCashSettlementCorrectionActorPolicy
{
    public function view(User $actor): void
    {
        $roles = $actor->roleSlugs();
        if ($actor->is_system_administrator
            || count($roles) !== 1
            || ! in_array($roles[0], [RoleCapabilityMatrix::ROLE_CASHIER, RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR], true)
            || ! $actor->canCapability(Capability::FINANCE_SETTLEMENT_CORRECTION_VIEW)) {
            throw new AuthorizationException;
        }
    }

    public function request(User $actor): void
    {
        $this->exact($actor, RoleCapabilityMatrix::ROLE_CASHIER, Capability::FINANCE_SETTLEMENT_CORRECTION_REQUEST);
    }

    public function review(User $actor): void
    {
        $this->exact($actor, RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR, Capability::FINANCE_SETTLEMENT_CORRECTION_REVIEW);
    }

    public function completeRefund(User $actor): void
    {
        $this->exact($actor, RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR, Capability::FINANCE_SETTLEMENT_REFUND_COMPLETE);
    }

    public function viewReceipt(User $actor): void
    {
        $this->view($actor);
        if (! $actor->canCapability(Capability::FINANCE_SETTLEMENT_CORRECTION_RECEIPT_VIEW)) {
            throw new AuthorizationException;
        }
    }

    private function exact(User $actor, string $role, string $capability): void
    {
        if ($actor->is_system_administrator
            || $actor->roleSlugs() !== [$role]
            || ! $actor->canCapability($capability)) {
            throw new AuthorizationException;
        }
    }
}
