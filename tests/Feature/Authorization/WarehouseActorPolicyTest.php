<?php

namespace Tests\Feature\Authorization;

use App\Models\Permission;
use App\Models\Role;
use App\Models\TeachingRoleAccessLease;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Authorization\TeachingRoleAccessLeaseGuard;
use App\Support\Warehouse\WarehouseActorPolicy;
use App\Support\Warehouse\WarehouseSupplierService;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WarehouseActorPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const LEASE_PASSWORD = 'Warehouse-Teaching-Access-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        config([
            'simulation.warehouse_capability_enabled' => true,
            'simulation.teaching_role_access_commitment_key' => 'warehouse-policy-test-commitment-key-2026',
            'simulation.teaching_role_access_environment' => 'test-simulation',
            'simulation.teaching_role_access_release_sha' => str_repeat('a', 40),
            'simulation.teaching_role_access_deployment_url' => 'localhost',
            'simulation.teaching_role_access_canonical_host' => 'localhost',
        ]);
    }

    public function test_each_operation_is_allowed_only_to_its_documented_exact_role(): void
    {
        $policy = app(WarehouseActorPolicy::class);
        $officer = $this->actor(RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER);
        $approver = $this->actor(RoleCapabilityMatrix::ROLE_PROCUREMENT_APPROVER);
        $receiver = $this->actor(RoleCapabilityMatrix::ROLE_WAREHOUSE_RECEIVER);
        $warehouse = $this->actor(RoleCapabilityMatrix::ROLE_WAREHOUSE_INVENTORY_CONTROLLER);
        $supervisor = $this->actor(RoleCapabilityMatrix::ROLE_WAREHOUSE_INVENTORY_SUPERVISOR);
        $pharmacy = $this->actor(RoleCapabilityMatrix::ROLE_PHARMACY_INVENTORY_CONTROLLER);

        $allowed = [
            [$officer, 'manageSupplier'],
            [$officer, 'viewSupplier'],
            [$officer, 'createPurchaseOrder'],
            [$officer, 'submitPurchaseOrder'],
            [$officer, 'viewPurchaseOrder'],
            [$approver, 'reviewPurchaseOrder'],
            [$approver, 'viewPurchaseOrder'],
            [$receiver, 'recordReceipt'],
            [$receiver, 'viewReceipt'],
            [$warehouse, 'dispatchTransfer'],
            [$warehouse, 'viewTransfer'],
            [$warehouse, 'requestSupplierReturn'],
            [$warehouse, 'acceptUnitReturn'],
            [$warehouse, 'viewStockCard'],
            [$warehouse, 'requestCorrection'],
            [$supervisor, 'viewStockCard'],
            [$supervisor, 'reviewCorrection'],
            [$pharmacy, 'acceptTransfer'],
            [$pharmacy, 'viewTransfer'],
            [$pharmacy, 'proposeUnitReturn'],
        ];

        foreach ($allowed as [$actor, $method]) {
            $policy->{$method}($actor);
        }

        $this->assertTrue($policy->can($officer, RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER, Capability::WAREHOUSE_SUPPLIER_MANAGE));
        $this->assertTrue($policy->canForRoles(
            $pharmacy,
            [RoleCapabilityMatrix::ROLE_PHARMACY_INVENTORY_CONTROLLER],
            Capability::WAREHOUSE_RETURN_UNIT,
        ));
    }

    public function test_system_administrator_is_allowed_while_mixed_role_and_wrong_exact_role_are_denied(): void
    {
        $policy = app(WarehouseActorPolicy::class);
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $admin->forceFill(['is_system_administrator' => true])->save();
        $this->assertTrue($policy->can($admin->fresh(), RoleCapabilityMatrix::ROLE_ADMIN, Capability::WAREHOUSE_SUPPLIER_MANAGE));
        $policy->manageSupplier($admin->fresh());

        $mixed = $this->actor(RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER);
        $mixed->roles()->attach(Role::query()->where('slug', RoleCapabilityMatrix::ROLE_PROCUREMENT_APPROVER)->sole());
        $this->assertFalse($policy->canForRoles(
            $mixed->fresh(),
            [RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER, RoleCapabilityMatrix::ROLE_PROCUREMENT_APPROVER],
            Capability::WAREHOUSE_PURCHASE_ORDER_VIEW,
        ));
        $this->expectAuthorization(fn () => $policy->viewPurchaseOrder($mixed->fresh()));

        $wrongRole = $this->actor(RoleCapabilityMatrix::ROLE_WAREHOUSE_RECEIVER);
        $this->expectAuthorization(fn () => $policy->dispatchTransfer($wrongRole));
        $this->expectAuthorization(fn () => $policy->acceptTransfer($wrongRole));
    }

    public function test_missing_capability_is_denied_even_when_the_role_is_exact(): void
    {
        $policy = app(WarehouseActorPolicy::class);
        $officerRole = Role::query()->where('slug', RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER)->sole();
        $permission = Permission::query()->where('name', Capability::WAREHOUSE_SUPPLIER_MANAGE)->sole();
        $officerRole->permissions()->detach($permission->id);
        $officer = $this->actor(RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER);

        $this->assertFalse($policy->can($officer, RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER, Capability::WAREHOUSE_SUPPLIER_MANAGE));
        $this->expectAuthorization(fn () => $policy->manageSupplier($officer));
    }

    public function test_disabled_non_roster_actor_is_denied_while_active_factory_actor_remains_allowed(): void
    {
        $policy = app(WarehouseActorPolicy::class);
        $receiver = $this->actor(RoleCapabilityMatrix::ROLE_WAREHOUSE_RECEIVER);
        $this->assertTrue($policy->canRecordReceipt($receiver));

        $receiver->forceFill(['status' => 'DISABLED'])->save();
        $this->assertFalse($policy->canRecordReceipt($receiver->fresh()));
        $this->expectAuthorization(fn () => $policy->recordReceipt($receiver->fresh()));
    }

    public function test_runtime_capability_switch_denies_every_non_roster_warehouse_authorization_and_claim(): void
    {
        $policy = app(WarehouseActorPolicy::class);
        $officer = $this->actor(RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER);

        $this->assertTrue($policy->canManageSupplier($officer));
        $claim = $policy->authorizeSupplierOperation($officer, WarehouseSupplierService::OP_CREATE);
        $this->assertTrue($policy->claimRemainsValid($claim, DB::connection()));

        config(['simulation.warehouse_capability_enabled' => false]);

        $this->assertFalse($policy->canManageSupplier($officer));
        $this->assertFalse($policy->claimRemainsValid($claim, DB::connection()));
        $this->expectAuthorization(
            fn () => $policy->authorizeSupplierOperation($officer, WarehouseSupplierService::OP_CREATE),
        );

        config(['simulation.warehouse_capability_enabled' => true]);
        $this->assertTrue($policy->canManageSupplier($officer));
    }

    public function test_policy_ignores_stale_actor_attributes_cached_roles_and_cached_capabilities(): void
    {
        $policy = app(WarehouseActorPolicy::class);
        $officer = $this->actor(RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER);
        $this->assertTrue($officer->canCapability(Capability::WAREHOUSE_SUPPLIER_MANAGE));
        $this->assertSame([RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER], $officer->roleSlugs());
        $this->assertTrue($policy->canManageSupplier($officer));

        DB::table('users')->where('id', $officer->id)->update(['status' => 'DISABLED']);
        $this->assertSame('ACTIVE', $officer->status);
        $this->assertFalse($policy->canManageSupplier($officer));
        $this->expectAuthorization(fn () => $policy->authorizeSupplierOperation($officer, WarehouseSupplierService::OP_CREATE));

        DB::table('users')->where('id', $officer->id)->update(['status' => 'ACTIVE']);
        DB::table('role_user')->where('user_id', $officer->id)->delete();
        $this->assertSame([RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER], $officer->roleSlugs());
        $this->assertTrue($officer->canCapability(Capability::WAREHOUSE_SUPPLIER_MANAGE));
        $this->assertFalse($policy->canManageSupplier($officer));
        $this->expectAuthorization(fn () => $policy->authorizeSupplierOperation($officer, WarehouseSupplierService::OP_CREATE));
    }

    public function test_roster_claim_is_bound_to_the_current_session_epoch_and_active_lease(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Managed warehouse lease fixtures require the intentionally unrun warehouse roster migration.');
        }

        [$officer, $lease] = $this->activeRosterOfficer();
        session()->put([
            TeachingRoleAccessLeaseGuard::SESSION_EPOCH_KEY => 7,
            TeachingRoleAccessLeaseGuard::SESSION_LEASE_KEY => $lease->public_id,
        ]);
        $policy = app(WarehouseActorPolicy::class);
        $claim = $policy->authorizeSupplierOperation($officer, WarehouseSupplierService::OP_CREATE);
        $this->assertTrue($policy->claimRemainsValid($claim, DB::connection()));

        DB::table('users')->where('id', $officer->id)->increment('teaching_access_epoch');
        $this->assertFalse($policy->claimRemainsValid($claim, DB::connection()));
        $this->expectAuthorization(fn () => $policy->authorizeSupplierOperation($officer, WarehouseSupplierService::OP_CREATE));

        session()->put(TeachingRoleAccessLeaseGuard::SESSION_EPOCH_KEY, 8);
        DB::table('teaching_role_access_leases')->where('id', $lease->id)->update([
            'status' => 'REVOKED',
            'active_slot' => null,
            'ended_at_epoch' => app(TeachingRoleAccessLeaseGuard::class)->databaseEpoch(),
            'end_reason' => 'test revocation',
        ]);
        $this->expectAuthorization(fn () => $policy->authorizeSupplierOperation($officer, WarehouseSupplierService::OP_CREATE));
    }

    public function test_generic_warehouse_gates_allow_system_administrators(): void
    {
        $administrator = User::factory()->create(['is_system_administrator' => true]);

        $this->assertTrue($administrator->canCapability(Capability::WAREHOUSE_SUPPLIER_VIEW));
        $this->assertTrue(Gate::forUser($administrator)->allows(Capability::WAREHOUSE_SUPPLIER_VIEW));
        $this->assertTrue(Gate::forUser($administrator)->allows(Capability::PATIENT_VIEW));
    }

    public function test_authorization_can_be_run_before_a_resource_lookup(): void
    {
        $policy = app(WarehouseActorPolicy::class);
        $wrongActor = $this->actor(RoleCapabilityMatrix::ROLE_PHYSICIAN);
        $lookupCalled = false;

        try {
            $policy->viewReceipt($wrongActor);
            $lookupCalled = true;
        } catch (AuthorizationException) {
            // The actor boundary is intentionally independent of resource lookup.
        }

        $this->assertFalse($lookupCalled);
    }

    private function actor(string $roleSlug): User
    {
        $actor = User::factory()->create(['is_system_administrator' => false]);
        $actor->roles()->sync([Role::query()->where('slug', $roleSlug)->sole()->id]);

        return $actor->fresh();
    }

    /** @return array{User, TeachingRoleAccessLease} */
    private function activeRosterOfficer(): array
    {
        $guard = app(TeachingRoleAccessLeaseGuard::class);
        $now = $guard->databaseEpoch();
        $leasePublicId = (string) Str::ulid();
        $actor = User::factory()->create([
            'email' => 'procurement.officer.demo@example.invalid',
            'password' => self::LEASE_PASSWORD,
            'status' => 'TEACHING_ACTIVE',
            'is_system_administrator' => false,
        ]);
        $actor->forceFill([
            'teaching_access_epoch' => 7,
            'teaching_access_mutex' => 7,
            'teaching_access_roster_key' => RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER,
            'teaching_access_lease_public_id' => $leasePublicId,
            'teaching_access_expires_at_epoch' => $now + 900,
        ])->save();
        $actor->roles()->sync([
            Role::query()->where('slug', RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER)->sole()->id,
        ]);
        $lease = TeachingRoleAccessLease::query()->create([
            'public_id' => $leasePublicId,
            'user_id' => $actor->id,
            'expected_role' => RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER,
            'credential_commitment' => $guard->credentialCommitment(self::LEASE_PASSWORD),
            'password_state_commitment' => $guard->passwordStateCommitment($actor->password),
            'environment' => 'test-simulation',
            'release_sha' => str_repeat('a', 40),
            'deployment_url' => 'localhost',
            'canonical_host' => 'localhost',
            'status' => 'ACTIVE',
            'active_slot' => 1,
            'activated_at_epoch' => $now,
            'expires_at_epoch' => $now + 900,
            'operator' => 'warehouse-policy-test',
            'reason' => 'Verify roster-bound warehouse authorization.',
        ]);

        return [$actor->fresh(), $lease];
    }

    private function expectAuthorization(\Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Expected warehouse authorization denial.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }
}
