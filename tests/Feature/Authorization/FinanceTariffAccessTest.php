<?php

namespace Tests\Feature\Authorization;

use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Authorization\TeachingRoleAccessManager;
use App\Support\Finance\FinanceTariffActorPolicy;
use Database\Seeders\DemoActorsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FinanceTariffAccessTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'finance.steward.demo@example.invalid';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'simulation.demo_seed_enabled' => true,
            'simulation.mode' => 'SIMULATION',
            'simulation.synthetic_only' => true,
            'simulation.demo_account_password' => Str::random(32),
            'simulation.teaching_role_access_password' => Str::random(32),
            'simulation.teaching_role_access_commitment_key' => Str::random(48),
            'simulation.teaching_role_access_environment' => 'test-simulation',
            'simulation.teaching_role_access_release_sha' => str_repeat('f', 40),
            'simulation.teaching_role_access_deployment_url' => 'test-deployment.vercel.app',
            'simulation.teaching_role_access_canonical_host' => 'localhost',
            'simulation.teaching_role_access_max_ttl_minutes' => 30,
            'session.driver' => 'database',
            'session.table' => 'sessions',
            'session.connection' => config('database.default'),
        ]);

        $this->seed(RbacSeeder::class);
        $this->seed(DemoActorsSeeder::class);
    }

    public function test_tariff_capabilities_are_assigned_only_to_the_exact_finance_roles(): void
    {
        $this->assertSame('Pengelola Tarif', RoleCapabilityMatrix::roles()[RoleCapabilityMatrix::ROLE_FINANCE_STEWARD]['name']);
        $this->assertSame(
            [
                Capability::FINANCE_TARIFF_VIEW,
                Capability::FINANCE_TARIFF_MANAGE,
                Capability::FINANCE_RADIOLOGY_TARIFF_VIEW,
                Capability::FINANCE_RADIOLOGY_TARIFF_MANAGE,
                Capability::FINANCE_LABORATORY_TARIFF_VIEW,
                Capability::FINANCE_LABORATORY_TARIFF_MANAGE,
                Capability::FINANCE_ACCOMMODATION_TARIFF_VIEW,
                Capability::FINANCE_ACCOMMODATION_TARIFF_MANAGE,
            ],
            RoleCapabilityMatrix::capabilitiesFor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD),
        );
        $this->assertContains(
            Capability::FINANCE_TARIFF_VIEW,
            RoleCapabilityMatrix::capabilitiesFor(RoleCapabilityMatrix::ROLE_CASHIER),
        );
        $this->assertNotContains(
            Capability::FINANCE_TARIFF_MANAGE,
            RoleCapabilityMatrix::capabilitiesFor(RoleCapabilityMatrix::ROLE_CASHIER),
        );
        $this->assertContains(
            Capability::FINANCE_RADIOLOGY_TARIFF_VIEW,
            RoleCapabilityMatrix::capabilitiesFor(RoleCapabilityMatrix::ROLE_CASHIER),
        );
        $this->assertNotContains(
            Capability::FINANCE_RADIOLOGY_TARIFF_MANAGE,
            RoleCapabilityMatrix::capabilitiesFor(RoleCapabilityMatrix::ROLE_CASHIER),
        );
        $this->assertContains(
            Capability::FINANCE_LABORATORY_TARIFF_VIEW,
            RoleCapabilityMatrix::capabilitiesFor(RoleCapabilityMatrix::ROLE_CASHIER),
        );
        $this->assertNotContains(
            Capability::FINANCE_LABORATORY_TARIFF_MANAGE,
            RoleCapabilityMatrix::capabilitiesFor(RoleCapabilityMatrix::ROLE_CASHIER),
        );
        $this->assertContains(
            Capability::FINANCE_ACCOMMODATION_TARIFF_VIEW,
            RoleCapabilityMatrix::capabilitiesFor(RoleCapabilityMatrix::ROLE_CASHIER),
        );
        $this->assertNotContains(
            Capability::FINANCE_ACCOMMODATION_TARIFF_MANAGE,
            RoleCapabilityMatrix::capabilitiesFor(RoleCapabilityMatrix::ROLE_CASHIER),
        );

        foreach (RoleCapabilityMatrix::matrix() as $role => $capabilities) {
            if (! in_array($role, [RoleCapabilityMatrix::ROLE_FINANCE_STEWARD, RoleCapabilityMatrix::ROLE_CASHIER], true)) {
                $this->assertNotContains(Capability::FINANCE_TARIFF_VIEW, $capabilities, $role);
                $this->assertNotContains(Capability::FINANCE_RADIOLOGY_TARIFF_VIEW, $capabilities, $role);
                $this->assertNotContains(Capability::FINANCE_LABORATORY_TARIFF_VIEW, $capabilities, $role);
                $this->assertNotContains(Capability::FINANCE_ACCOMMODATION_TARIFF_VIEW, $capabilities, $role);
            }

            if ($role !== RoleCapabilityMatrix::ROLE_FINANCE_STEWARD) {
                $this->assertNotContains(Capability::FINANCE_TARIFF_MANAGE, $capabilities, $role);
                $this->assertNotContains(Capability::FINANCE_RADIOLOGY_TARIFF_MANAGE, $capabilities, $role);
                $this->assertNotContains(Capability::FINANCE_LABORATORY_TARIFF_MANAGE, $capabilities, $role);
                $this->assertNotContains(Capability::FINANCE_ACCOMMODATION_TARIFF_MANAGE, $capabilities, $role);
            }
        }
    }

    public function test_policy_allows_steward_management_and_cashier_read_only_without_admin_or_mixed_role_bypass(): void
    {
        $policy = app(FinanceTariffActorPolicy::class);
        $steward = $this->actor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD);
        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);

        $this->assertTrue($policy->canView($steward));
        $this->assertTrue($policy->canManage($steward));
        $this->assertTrue($policy->canView($cashier));
        $this->assertFalse($policy->canManage($cashier));
        $this->assertFalse($policy->canView($admin));
        $this->assertFalse($policy->canManage($admin));

        $admin->forceFill(['is_system_administrator' => true])->save();
        $this->assertFalse($policy->canView($admin->fresh()));
        $this->assertFalse($policy->canManage($admin->fresh()));

        $mixed = $this->actor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD);
        $mixed->roles()->attach(Role::query()->where('slug', RoleCapabilityMatrix::ROLE_CASHIER)->sole());
        $this->assertFalse($policy->canView($mixed->fresh()));
        $this->assertFalse($policy->canManage($mixed->fresh()));

        $this->expectException(AuthorizationException::class);
        $policy->manage($cashier);
    }

    public function test_finance_steward_is_an_exact_disabled_roster_identity_that_admin_can_activate_and_revoke(): void
    {
        $this->assertSame(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD, TeachingRoleAccessManager::ROSTER[self::EMAIL]);

        $steward = User::query()->where('email', self::EMAIL)->sole();
        $this->assertSame('Demo Pengelola Tarif', $steward->name);
        $this->assertSame('DISABLED', $steward->status);
        $this->assertNull($steward->email_verified_at);
        $this->assertFalse($steward->is_system_administrator);
        $this->assertSame([RoleCapabilityMatrix::ROLE_FINANCE_STEWARD], $steward->roleSlugs());

        $this->assertSame(0, Artisan::call('teaching:role-access', $this->arguments('activate')), Artisan::output());
        $this->assertDatabaseHas('users', [
            'email' => self::EMAIL,
            'status' => 'TEACHING_ACTIVE',
            'teaching_access_roster_key' => RoleCapabilityMatrix::ROLE_FINANCE_STEWARD,
        ]);

        $this->assertSame(0, Artisan::call('teaching:role-access', $this->arguments('revoke')), Artisan::output());
        $this->assertDatabaseHas('users', [
            'email' => self::EMAIL,
            'status' => 'DISABLED',
            'email_verified_at' => null,
            'teaching_access_roster_key' => RoleCapabilityMatrix::ROLE_FINANCE_STEWARD,
        ]);
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create(['is_system_administrator' => false]);
        $user->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $user->fresh();
    }

    /** @return array<string, mixed> */
    private function arguments(string $action): array
    {
        return [
            'action' => $action,
            'email' => self::EMAIL,
            '--confirm' => TeachingRoleAccessManager::confirmationPhrase($action, self::EMAIL),
            '--operator' => 'SIMRS Campus Test Operator',
            '--reason' => 'Temporary finance tariff stewardship verification',
            '--expected-environment' => 'test-simulation',
            '--expected-release-sha' => str_repeat('f', 40),
            '--expected-deployment-url' => 'test-deployment.vercel.app',
            '--expected-canonical-host' => 'localhost',
            '--ttl-minutes' => 15,
        ];
    }
}
