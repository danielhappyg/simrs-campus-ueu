<?php

namespace Tests\Feature\Authorization;

use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Authorization\TeachingRoleAccessManager;
use App\Support\Finance\FinanceCashSettlementCorrectionActorPolicy;
use Database\Seeders\DemoActorsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CashierSupervisorAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_demo_supervisor_is_seeded_as_an_exact_disabled_governed_identity(): void
    {
        config([
            'simulation.demo_seed_enabled' => true,
            'simulation.mode' => 'SIMULATION',
            'simulation.synthetic_only' => true,
            'simulation.demo_account_password' => Str::random(32),
            'simulation.rebuild_admin_email' => 'rebuild.admin@example.invalid',
        ]);
        $this->seed(DemoActorsSeeder::class);

        $supervisor = User::query()->where('email', 'cashier.supervisor.demo@example.invalid')->sole();
        $this->assertSame('Demo Supervisor Kasir', $supervisor->name);
        $this->assertSame('DISABLED', $supervisor->status);
        $this->assertFalse($supervisor->is_system_administrator);
        $this->assertSame(RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR, $supervisor->teaching_access_roster_key);
        $this->assertSame([RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR], $supervisor->roleSlugs());
    }

    public function test_cashier_and_supervisor_have_separated_exact_capabilities(): void
    {
        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $supervisor = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR);

        $this->assertTrue($cashier->canCapability(Capability::FINANCE_SETTLEMENT_CORRECTION_REQUEST));
        $this->assertFalse($cashier->canCapability(Capability::FINANCE_SETTLEMENT_CORRECTION_REVIEW));
        $this->assertFalse($cashier->canCapability(Capability::FINANCE_SETTLEMENT_REFUND_COMPLETE));
        $this->assertFalse($supervisor->canCapability(Capability::FINANCE_SETTLEMENT_CORRECTION_REQUEST));
        $this->assertTrue($supervisor->canCapability(Capability::FINANCE_SETTLEMENT_CORRECTION_REVIEW));
        $this->assertTrue($supervisor->canCapability(Capability::FINANCE_SETTLEMENT_REFUND_COMPLETE));
        $this->assertTrue($cashier->canCapability(Capability::FINANCE_CASHIER_COLLECTION_OPEN));
        $this->assertFalse($cashier->canCapability(Capability::FINANCE_CASHIER_COLLECTION_VERIFY));
        $this->assertFalse($supervisor->canCapability(Capability::FINANCE_CASHIER_COLLECTION_OPEN));
        $this->assertTrue($supervisor->canCapability(Capability::FINANCE_CASHIER_COLLECTION_VERIFY));
        $this->assertFalse($supervisor->canCapability(Capability::FINANCE_CASH_DEPOSIT_HANDOFF_CREATE));
        $this->assertSame(
            RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR,
            TeachingRoleAccessManager::ROSTER['cashier.supervisor.demo@example.invalid'],
        );
    }

    public function test_admin_system_admin_and_mixed_role_accounts_fail_closed(): void
    {
        $policy = app(FinanceCashSettlementCorrectionActorPolicy::class);
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $systemAdmin = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR);
        $systemAdmin->forceFill(['is_system_administrator' => true])->save();
        $mixed = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR);
        $mixed->roles()->attach(Role::query()->where('slug', RoleCapabilityMatrix::ROLE_CASHIER)->sole()->id);

        foreach ([$admin, $systemAdmin->fresh(), $mixed->fresh()] as $actor) {
            try {
                $policy->review($actor);
                $this->fail('Expected exact-role correction denial.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
    }

    private function actor(string $role): User
    {
        $actor = User::factory()->create();
        $actor->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $actor->fresh();
    }
}
