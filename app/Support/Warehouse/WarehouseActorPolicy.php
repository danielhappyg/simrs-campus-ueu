<?php

namespace App\Support\Warehouse;

use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Authorization\TeachingRoleAccessLeaseGuard;
use App\Support\Authorization\TeachingRoleAccessManager;
use App\Support\Database\SchemaQualifier;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Authorization boundary for the medication replenishment warehouse slice.
 *
 * This policy deliberately uses exact role sets. A capability by itself is
 * not sufficient for a warehouse operation, and a user with multiple roles
 * cannot use a shared capability to bypass the separation of duties.
 */
final class WarehouseActorPolicy
{
    public function __construct(private readonly TeachingRoleAccessLeaseGuard $leaseGuard) {}

    /**
     * Check one exact role and capability without loading any domain resource.
     */
    public function can(User $actor, string $role, string $capability): bool
    {
        try {
            return $this->authoritativeDecision(
                $actor,
                [$role],
                $capability,
                $this->requestHost(),
                null,
                null,
                false,
                DB::connection(),
            )['allowed'];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check a capability for one of several explicitly permitted exact roles.
     *
     * This is intended for read operations whose capability is shared by
     * separate warehouse roles. It still rejects mixed-role accounts.
     *
     * @param  list<string>  $roles
     */
    public function canForRoles(User $actor, array $roles, string $capability): bool
    {
        try {
            return $this->authoritativeDecision(
                $actor,
                $roles,
                $capability,
                $this->requestHost(),
                null,
                null,
                false,
                DB::connection(),
            )['allowed'];
        } catch (\Throwable) {
            return false;
        }
    }

    public function authorizeSupplierOperation(User $actor, string $operation): WarehouseOperationAuthorization
    {
        try {
            [$sessionEpoch, $sessionLease] = $this->requestLeaseContext();
            $decision = $this->authoritativeDecision(
                $actor,
                [RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER],
                Capability::WAREHOUSE_SUPPLIER_MANAGE,
                $this->requestHost(),
                $sessionEpoch,
                $sessionLease,
                true,
                DB::connection(),
            );
        } catch (\Throwable) {
            throw new AuthorizationException;
        }
        if (! $decision['allowed']) {
            throw new AuthorizationException;
        }

        return WarehouseOperationAuthorization::supplierManagement(
            $decision['actor_id'],
            $decision['actor_public_id'],
            $operation,
            $decision['state_digest'],
            $decision['roster_account'],
            $decision['teaching_access_epoch'],
            $decision['teaching_access_mutex'],
            $decision['lease_public_id'],
            $decision['request_host'],
        );
    }

    public function claimRemainsValid(
        WarehouseOperationAuthorization $authorization,
        Connection $connection,
    ): bool {
        try {
            $actor = new User;
            $actor->setRawAttributes([
                'id' => $authorization->actorId,
                'public_id' => $authorization->actorPublicId,
            ], true);
            $decision = $this->authoritativeDecision(
                $actor,
                [$authorization->role],
                $authorization->capability,
                $authorization->requestHost,
                $authorization->rosterAccount ? $authorization->teachingAccessEpoch : null,
                $authorization->rosterAccount ? $authorization->leasePublicId : null,
                true,
                $connection,
            );

            return $decision['allowed']
                && hash_equals($authorization->stateDigest, $decision['state_digest'])
                && $authorization->rosterAccount === $decision['roster_account']
                && $authorization->teachingAccessEpoch === $decision['teaching_access_epoch']
                && $authorization->teachingAccessMutex === $decision['teaching_access_mutex']
                && $authorization->leasePublicId === $decision['lease_public_id'];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  list<string>  $roles
     * @return array{allowed: bool, actor_id: int, actor_public_id: string, state_digest: string, roster_account: bool, teaching_access_epoch: int, teaching_access_mutex: int, lease_public_id: string|null, request_host: string|null}
     */
    private function authoritativeDecision(
        User $actor,
        array $roles,
        string $capability,
        ?string $requestHost,
        ?int $sessionEpoch,
        ?string $sessionLease,
        bool $requireSessionBinding,
        Connection $connection,
    ): array {
        $actorId = (int) $actor->getKey();
        if (config('simulation.warehouse_capability_enabled') !== true) {
            return $this->deniedDecision($actorId, $requestHost);
        }

        $users = SchemaQualifier::table('users');
        $roleUser = SchemaQualifier::table('role_user');
        $roleTable = SchemaQualifier::table('roles');
        $permissionRole = SchemaQualifier::table('permission_role');
        $permissions = SchemaQualifier::table('permissions');
        $leases = SchemaQualifier::table('teaching_role_access_leases');
        $user = $actorId > 0
            ? $connection->table($users)->where('id', $actorId)->first()
            : null;
        if (! is_object($user)) {
            return $this->deniedDecision($actorId, $requestHost);
        }

        $roleSlugs = $connection->table("{$roleUser} as ru")
            ->join("{$roleTable} as r", 'r.id', '=', 'ru.role_id')
            ->where('ru.user_id', $actorId)
            ->orderBy('r.slug')
            ->pluck('r.slug')
            ->map(static fn (mixed $slug): string => (string) $slug)
            ->unique()
            ->values()
            ->all();
        $hasCapability = $connection->table("{$roleUser} as ru")
            ->join("{$permissionRole} as pr", 'pr.role_id', '=', 'ru.role_id')
            ->join("{$permissions} as p", 'p.id', '=', 'pr.permission_id')
            ->where('ru.user_id', $actorId)
            ->where('p.name', $capability)
            ->exists();
        $email = mb_strtolower((string) data_get($user, 'email'));
        $rosterKey = data_get($user, 'teaching_access_roster_key');
        $effectiveRoster = TeachingRoleAccessManager::effectiveRoster();
        $emailRole = $effectiveRoster[$email] ?? null;
        $knownRosterRole = TeachingRoleAccessManager::ROSTER[$email] ?? null;
        $rosterAccount = $knownRosterRole !== null
            || (is_string($rosterKey) && in_array($rosterKey, array_values(TeachingRoleAccessManager::ROSTER), true));
        $singleRoleAllowed = count($roleSlugs) === 1 && in_array($roleSlugs[0], $roles, true);
        $admin = (bool) data_get($user, 'is_system_administrator');
        $status = (string) data_get($user, 'status');
        $epoch = (int) data_get($user, 'teaching_access_epoch', 0);
        $mutex = (int) data_get($user, 'teaching_access_mutex', 0);
        $leasePublicId = data_get($user, 'teaching_access_lease_public_id');
        $leasePublicId = is_string($leasePublicId) ? $leasePublicId : null;
        $normalizedHost = is_string($requestHost) ? mb_strtolower($requestHost) : null;
        $leaseSnapshot = null;
        $accountAllowed = ! $rosterAccount && $status === 'ACTIVE';

        if ($rosterAccount) {
            $activeLeases = $connection->table($leases)
                ->where('user_id', $actorId)
                ->where('status', 'ACTIVE')
                ->orderBy('id')
                ->get();
            $lease = $activeLeases->count() === 1 ? $activeLeases->first() : null;
            $bindings = $this->leaseGuard->runtimeBindings();
            $expectedExpiry = data_get($user, 'teaching_access_expires_at_epoch');
            $leaseSnapshot = is_object($lease) ? [
                'public_id' => (string) data_get($lease, 'public_id'),
                'expected_role' => (string) data_get($lease, 'expected_role'),
                'password_state_commitment' => (string) data_get($lease, 'password_state_commitment'),
                'environment' => (string) data_get($lease, 'environment'),
                'release_sha' => (string) data_get($lease, 'release_sha'),
                'deployment_url' => (string) data_get($lease, 'deployment_url'),
                'canonical_host' => (string) data_get($lease, 'canonical_host'),
                'status' => (string) data_get($lease, 'status'),
                'active_slot' => (int) data_get($lease, 'active_slot'),
                'expires_at_epoch' => (int) data_get($lease, 'expires_at_epoch'),
            ] : null;
            $accountAllowed = is_object($lease)
                && $status === 'TEACHING_ACTIVE'
                && $emailRole !== null
                && $rosterKey === $emailRole
                && $roleSlugs === [$emailRole]
                && $leasePublicId !== null
                && $epoch >= 1
                && (! $requireSessionBinding
                    || ($sessionEpoch === $epoch && $sessionLease === $leasePublicId))
                && (int) data_get($lease, 'active_slot') === 1
                && data_get($lease, 'public_id') === $leasePublicId
                && data_get($lease, 'expected_role') === $emailRole
                && is_string(data_get($lease, 'password_state_commitment'))
                && hash_equals(
                    (string) data_get($lease, 'password_state_commitment'),
                    $this->leaseGuard->passwordStateCommitment((string) data_get($user, 'password')),
                )
                && (int) data_get($lease, 'expires_at_epoch') > $this->databaseEpoch($connection)
                && (int) data_get($lease, 'expires_at_epoch') === (int) $expectedExpiry
                && data_get($lease, 'environment') === $bindings['environment']
                && data_get($lease, 'release_sha') === $bindings['release_sha']
                && data_get($lease, 'deployment_url') === $bindings['deployment_url']
                && data_get($lease, 'canonical_host') === $bindings['canonical_host']
                && $normalizedHost !== null
                && $normalizedHost === $bindings['canonical_host'];
        }

        $snapshot = [
            'actor_id' => $actorId,
            'actor_public_id' => (string) data_get($user, 'public_id'),
            'email' => $email,
            'status' => $status,
            'is_system_administrator' => $admin,
            'role_slugs' => $roleSlugs,
            'required_roles' => $roles,
            'required_capability' => $capability,
            'has_capability' => $hasCapability,
            'roster_account' => $rosterAccount,
            'teaching_access_epoch' => $epoch,
            'teaching_access_mutex' => $mutex,
            'teaching_access_roster_key' => is_string($rosterKey) ? $rosterKey : null,
            'teaching_access_lease_public_id' => $leasePublicId,
            'teaching_access_expires_at_epoch' => data_get($user, 'teaching_access_expires_at_epoch') === null
                ? null
                : (int) data_get($user, 'teaching_access_expires_at_epoch'),
            'request_host' => $normalizedHost,
            'session_epoch' => $sessionEpoch,
            'session_lease_public_id' => $sessionLease,
            'session_binding_required' => $requireSessionBinding,
            'lease' => $leaseSnapshot,
        ];

        return [
            'allowed' => ! $admin && $accountAllowed && $singleRoleAllowed && $hasCapability,
            'actor_id' => $actorId,
            'actor_public_id' => (string) data_get($user, 'public_id'),
            'state_digest' => WarehouseCanonicalJson::digest($snapshot),
            'roster_account' => $rosterAccount,
            'teaching_access_epoch' => $epoch,
            'teaching_access_mutex' => $mutex,
            'lease_public_id' => $leasePublicId,
            'request_host' => $normalizedHost,
        ];
    }

    /** @return array{allowed: false, actor_id: int, actor_public_id: string, state_digest: string, roster_account: false, teaching_access_epoch: 0, teaching_access_mutex: 0, lease_public_id: null, request_host: string|null} */
    private function deniedDecision(int $actorId, ?string $requestHost): array
    {
        return [
            'allowed' => false,
            'actor_id' => $actorId,
            'actor_public_id' => '',
            'state_digest' => str_repeat('0', 64),
            'roster_account' => false,
            'teaching_access_epoch' => 0,
            'teaching_access_mutex' => 0,
            'lease_public_id' => null,
            'request_host' => $requestHost,
        ];
    }

    private function requestHost(): ?string
    {
        return app()->bound('request') ? request()->getHost() : null;
    }

    /** @return array{int|null, string|null} */
    private function requestLeaseContext(): array
    {
        if (! app()->bound('session')) {
            return [null, null];
        }

        $epoch = session()->get(TeachingRoleAccessLeaseGuard::SESSION_EPOCH_KEY);
        $lease = session()->get(TeachingRoleAccessLeaseGuard::SESSION_LEASE_KEY);

        return [is_int($epoch) ? $epoch : null, is_string($lease) ? $lease : null];
    }

    private function databaseEpoch(Connection $connection): int
    {
        $expression = $connection->getDriverName() === 'pgsql' ? 'clock_timestamp()' : 'CURRENT_TIMESTAMP';
        $row = $connection->selectOne("SELECT {$expression} AS db_clock_value");
        $value = data_get($row, 'db_clock_value');
        if (! is_string($value)) {
            throw new \RuntimeException('Warehouse authorization could not obtain authoritative database time.');
        }

        return CarbonImmutable::parse($value, (string) config('app.timezone', 'UTC'))->getTimestamp();
    }

    /**
     * Authorize one exact role and capability before route/domain lookup.
     */
    public function authorize(User $actor, string $role, string $capability): void
    {
        if (! $this->can($actor, $role, $capability)) {
            throw new AuthorizationException;
        }
    }

    /**
     * Authorize a shared capability for one of several exact roles before
     * route/domain lookup.
     *
     * @param  list<string>  $roles
     */
    public function authorizeForRoles(User $actor, array $roles, string $capability): void
    {
        if (! $this->canForRoles($actor, $roles, $capability)) {
            throw new AuthorizationException;
        }
    }

    public function manageSupplier(User $actor): void
    {
        if (! $this->canManageSupplier($actor)) {
            throw new AuthorizationException;
        }
    }

    public function canManageSupplier(User $actor): bool
    {
        return $this->can($actor, RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER, Capability::WAREHOUSE_SUPPLIER_MANAGE);
    }

    public function viewSupplier(User $actor): void
    {
        if (! $this->canViewSupplier($actor)) {
            throw new AuthorizationException;
        }
    }

    public function canViewSupplier(User $actor): bool
    {
        return $this->can($actor, RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER, Capability::WAREHOUSE_SUPPLIER_VIEW);
    }

    public function createPurchaseOrder(User $actor): void
    {
        if (! $this->canCreatePurchaseOrder($actor)) {
            throw new AuthorizationException;
        }
    }

    public function canCreatePurchaseOrder(User $actor): bool
    {
        return $this->can($actor, RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER, Capability::WAREHOUSE_PURCHASE_ORDER_CREATE);
    }

    public function submitPurchaseOrder(User $actor): void
    {
        if (! $this->canSubmitPurchaseOrder($actor)) {
            throw new AuthorizationException;
        }
    }

    public function canSubmitPurchaseOrder(User $actor): bool
    {
        return $this->can($actor, RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER, Capability::WAREHOUSE_PURCHASE_ORDER_SUBMIT);
    }

    public function reviewPurchaseOrder(User $actor): void
    {
        if (! $this->canReviewPurchaseOrder($actor)) {
            throw new AuthorizationException;
        }
    }

    public function canReviewPurchaseOrder(User $actor): bool
    {
        return $this->can($actor, RoleCapabilityMatrix::ROLE_PROCUREMENT_APPROVER, Capability::WAREHOUSE_PURCHASE_ORDER_REVIEW);
    }

    public function viewPurchaseOrder(User $actor): void
    {
        if (! $this->canViewPurchaseOrder($actor)) {
            throw new AuthorizationException;
        }
    }

    public function canViewPurchaseOrder(User $actor): bool
    {
        return $this->canForRoles($actor, [
            RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER,
            RoleCapabilityMatrix::ROLE_PROCUREMENT_APPROVER,
        ], Capability::WAREHOUSE_PURCHASE_ORDER_VIEW);
    }

    public function recordReceipt(User $actor): void
    {
        if (! $this->canRecordReceipt($actor)) {
            throw new AuthorizationException;
        }
    }

    public function canRecordReceipt(User $actor): bool
    {
        return $this->can($actor, RoleCapabilityMatrix::ROLE_WAREHOUSE_RECEIVER, Capability::WAREHOUSE_RECEIPT_RECORD);
    }

    public function viewReceipt(User $actor): void
    {
        if (! $this->canViewReceipt($actor)) {
            throw new AuthorizationException;
        }
    }

    public function canViewReceipt(User $actor): bool
    {
        return $this->can($actor, RoleCapabilityMatrix::ROLE_WAREHOUSE_RECEIVER, Capability::WAREHOUSE_RECEIPT_VIEW);
    }

    public function dispatchTransfer(User $actor): void
    {
        if (! $this->canDispatchTransfer($actor)) {
            throw new AuthorizationException;
        }
    }

    public function canDispatchTransfer(User $actor): bool
    {
        return $this->can($actor, RoleCapabilityMatrix::ROLE_WAREHOUSE_INVENTORY_CONTROLLER, Capability::WAREHOUSE_TRANSFER_DISPATCH);
    }

    public function acceptTransfer(User $actor): void
    {
        if (! $this->canAcceptTransfer($actor)) {
            throw new AuthorizationException;
        }
    }

    public function canAcceptTransfer(User $actor): bool
    {
        return $this->can($actor, RoleCapabilityMatrix::ROLE_PHARMACY_INVENTORY_CONTROLLER, Capability::WAREHOUSE_TRANSFER_ACCEPT);
    }

    public function viewTransfer(User $actor): void
    {
        if (! $this->canViewTransfer($actor)) {
            throw new AuthorizationException;
        }
    }

    public function canViewTransfer(User $actor): bool
    {
        return $this->canForRoles($actor, [
            RoleCapabilityMatrix::ROLE_WAREHOUSE_INVENTORY_CONTROLLER,
            RoleCapabilityMatrix::ROLE_PHARMACY_INVENTORY_CONTROLLER,
        ], Capability::WAREHOUSE_TRANSFER_VIEW);
    }

    public function requestSupplierReturn(User $actor): void
    {
        if (! $this->canRequestSupplierReturn($actor)) {
            throw new AuthorizationException;
        }
    }

    public function canRequestSupplierReturn(User $actor): bool
    {
        return $this->can($actor, RoleCapabilityMatrix::ROLE_WAREHOUSE_INVENTORY_CONTROLLER, Capability::WAREHOUSE_RETURN_SUPPLIER);
    }

    public function reviewSupplierReturn(User $actor): void
    {
        if (! $this->canReviewSupplierReturn($actor)) {
            throw new AuthorizationException;
        }
    }

    public function canReviewSupplierReturn(User $actor): bool
    {
        return $this->can($actor, RoleCapabilityMatrix::ROLE_PROCUREMENT_APPROVER, Capability::WAREHOUSE_RETURN_SUPPLIER);
    }

    public function proposeUnitReturn(User $actor): void
    {
        if (! $this->canProposeUnitReturn($actor)) {
            throw new AuthorizationException;
        }
    }

    public function canProposeUnitReturn(User $actor): bool
    {
        return $this->can($actor, RoleCapabilityMatrix::ROLE_PHARMACY_INVENTORY_CONTROLLER, Capability::WAREHOUSE_RETURN_UNIT);
    }

    public function acceptUnitReturn(User $actor): void
    {
        if (! $this->canAcceptUnitReturn($actor)) {
            throw new AuthorizationException;
        }
    }

    public function canAcceptUnitReturn(User $actor): bool
    {
        return $this->can($actor, RoleCapabilityMatrix::ROLE_WAREHOUSE_INVENTORY_CONTROLLER, Capability::WAREHOUSE_RETURN_UNIT);
    }

    public function viewStockCard(User $actor): void
    {
        if (! $this->canViewStockCard($actor)) {
            throw new AuthorizationException;
        }
    }

    public function canViewStockCard(User $actor): bool
    {
        return $this->canForRoles($actor, [
            RoleCapabilityMatrix::ROLE_WAREHOUSE_INVENTORY_CONTROLLER,
            RoleCapabilityMatrix::ROLE_WAREHOUSE_INVENTORY_SUPERVISOR,
        ], Capability::WAREHOUSE_STOCK_CARD_VIEW);
    }

    public function requestCorrection(User $actor): void
    {
        if (! $this->canRequestCorrection($actor)) {
            throw new AuthorizationException;
        }
    }

    public function canRequestCorrection(User $actor): bool
    {
        return $this->can($actor, RoleCapabilityMatrix::ROLE_WAREHOUSE_INVENTORY_CONTROLLER, Capability::WAREHOUSE_CORRECTION_REQUEST);
    }

    public function reviewCorrection(User $actor): void
    {
        if (! $this->canReviewCorrection($actor)) {
            throw new AuthorizationException;
        }
    }

    public function canReviewCorrection(User $actor): bool
    {
        return $this->can($actor, RoleCapabilityMatrix::ROLE_WAREHOUSE_INVENTORY_SUPERVISOR, Capability::WAREHOUSE_CORRECTION_REVIEW);
    }
}
