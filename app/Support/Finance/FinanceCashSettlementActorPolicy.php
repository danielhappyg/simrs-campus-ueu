<?php

namespace App\Support\Finance;

use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Auth\Access\AuthorizationException;

final class FinanceCashSettlementActorPolicy
{
    public function view(User $actor): void
    {
        $this->authorize($actor, Capability::FINANCE_SETTLEMENT_VIEW);
    }

    public function settle(User $actor): void
    {
        $this->authorize($actor, Capability::FINANCE_SETTLEMENT_CREATE);
    }

    public function viewReceipt(User $actor): void
    {
        $this->authorize($actor, Capability::FINANCE_RECEIPT_VIEW);
    }

    private function authorize(User $actor, string $capability): void
    {
        if ($actor->is_system_administrator
            || $actor->roleSlugs() !== [RoleCapabilityMatrix::ROLE_CASHIER]
            || ! $actor->canCapability($capability)) {
            throw new AuthorizationException;
        }
    }
}
