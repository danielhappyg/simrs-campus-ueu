<?php

namespace Tests\Feature\Authorization;

use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Authorization\TeachingRoleAccessManager;
use Database\Seeders\DemoActorsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CashierTeachingRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'cashier.demo@example.invalid';

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
            'simulation.teaching_role_access_release_sha' => str_repeat('c', 40),
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

    public function test_cashier_is_the_only_exact_role_with_bill_capabilities(): void
    {
        $this->assertSame('Kasir', RoleCapabilityMatrix::roles()[RoleCapabilityMatrix::ROLE_CASHIER]['name']);
        $this->assertSame(
            [
                Capability::FINANCE_BILL_VIEW,
                Capability::FINANCE_BILL_ISSUE,
                Capability::FINANCE_SETTLEMENT_VIEW,
                Capability::FINANCE_SETTLEMENT_CREATE,
                Capability::FINANCE_RECEIPT_VIEW,
                Capability::FINANCE_SETTLEMENT_CORRECTION_VIEW,
                Capability::FINANCE_SETTLEMENT_CORRECTION_REQUEST,
                Capability::FINANCE_SETTLEMENT_CORRECTION_RECEIPT_VIEW,
                Capability::FINANCE_TARIFF_VIEW,
                Capability::FINANCE_RADIOLOGY_TARIFF_VIEW,
                Capability::FINANCE_LABORATORY_TARIFF_VIEW,
                Capability::FINANCE_ACCOMMODATION_TARIFF_VIEW,
                Capability::FINANCE_CASHIER_COLLECTION_VIEW,
                Capability::FINANCE_CASHIER_COLLECTION_OPEN,
                Capability::FINANCE_CASHIER_COLLECTION_CLOSE_REQUEST,
                Capability::FINANCE_CASHIER_COLLECTION_RECOUNT,
                Capability::FINANCE_CASH_DEPOSIT_HANDOFF_VIEW,
                Capability::FINANCE_CASH_DEPOSIT_HANDOFF_CREATE,
            ],
            RoleCapabilityMatrix::capabilitiesFor(RoleCapabilityMatrix::ROLE_CASHIER),
        );

        foreach (RoleCapabilityMatrix::matrix() as $role => $capabilities) {
            if ($role === RoleCapabilityMatrix::ROLE_CASHIER) {
                continue;
            }

            $this->assertNotContains(Capability::FINANCE_BILL_VIEW, $capabilities, $role);
            $this->assertNotContains(Capability::FINANCE_BILL_ISSUE, $capabilities, $role);
            $this->assertNotContains(Capability::FINANCE_SETTLEMENT_VIEW, $capabilities, $role);
            $this->assertNotContains(Capability::FINANCE_SETTLEMENT_CREATE, $capabilities, $role);
            $this->assertNotContains(Capability::FINANCE_RECEIPT_VIEW, $capabilities, $role);
        }

        $cashier = User::query()->where('email', self::EMAIL)->sole();
        $this->assertTrue($cashier->canCapability(Capability::FINANCE_BILL_VIEW));
        $this->assertTrue($cashier->canCapability(Capability::FINANCE_BILL_ISSUE));
        $this->assertTrue($cashier->canCapability(Capability::FINANCE_SETTLEMENT_VIEW));
        $this->assertTrue($cashier->canCapability(Capability::FINANCE_SETTLEMENT_CREATE));
        $this->assertTrue($cashier->canCapability(Capability::FINANCE_RECEIPT_VIEW));
        $this->assertTrue($cashier->canCapability(Capability::FINANCE_TARIFF_VIEW));
        $this->assertTrue($cashier->canCapability(Capability::FINANCE_RADIOLOGY_TARIFF_VIEW));
        $this->assertTrue($cashier->canCapability(Capability::FINANCE_LABORATORY_TARIFF_VIEW));
        $this->assertTrue($cashier->canCapability(Capability::FINANCE_ACCOMMODATION_TARIFF_VIEW));
        $this->assertTrue($cashier->canCapability(Capability::FINANCE_CASHIER_COLLECTION_OPEN));
        $this->assertTrue($cashier->canCapability(Capability::FINANCE_CASHIER_COLLECTION_CLOSE_REQUEST));
        $this->assertTrue($cashier->canCapability(Capability::FINANCE_CASHIER_COLLECTION_RECOUNT));
        $this->assertTrue($cashier->canCapability(Capability::FINANCE_CASH_DEPOSIT_HANDOFF_CREATE));
        $this->assertFalse($cashier->canCapability(Capability::FINANCE_CASHIER_COLLECTION_VERIFY));
        $this->assertFalse($cashier->canCapability(Capability::FINANCE_TARIFF_MANAGE));
        $this->assertFalse($cashier->canCapability(Capability::PATIENT_VIEW));
    }

    public function test_demo_cashier_is_seeded_as_an_exact_disabled_governed_identity(): void
    {
        $this->assertSame(RoleCapabilityMatrix::ROLE_CASHIER, TeachingRoleAccessManager::ROSTER[self::EMAIL]);

        $cashier = User::query()->where('email', self::EMAIL)->sole();
        $this->assertSame('Demo Kasir', $cashier->name);
        $this->assertSame('DISABLED', $cashier->status);
        $this->assertNull($cashier->email_verified_at);
        $this->assertFalse($cashier->is_system_administrator);
        $this->assertSame(RoleCapabilityMatrix::ROLE_CASHIER, $cashier->teaching_access_roster_key);
        $this->assertSame([RoleCapabilityMatrix::ROLE_CASHIER], $cashier->roleSlugs());
        $this->assertSame(1, Role::query()->where('slug', RoleCapabilityMatrix::ROLE_CASHIER)->count());

        $this->seed(DemoActorsSeeder::class);
        $this->assertSame(1, User::query()->where('email', self::EMAIL)->count());
    }

    public function test_demo_cashier_preserves_temporary_activation_and_revocation_contracts(): void
    {
        $this->assertSame(0, Artisan::call('teaching:role-access', $this->arguments('activate')), Artisan::output());
        $this->assertDatabaseHas('users', [
            'email' => self::EMAIL,
            'status' => 'TEACHING_ACTIVE',
            'teaching_access_roster_key' => RoleCapabilityMatrix::ROLE_CASHIER,
        ]);

        $this->assertSame(0, Artisan::call('teaching:role-access', $this->arguments('revoke')), Artisan::output());
        $this->assertDatabaseHas('users', [
            'email' => self::EMAIL,
            'status' => 'DISABLED',
            'email_verified_at' => null,
            'teaching_access_roster_key' => RoleCapabilityMatrix::ROLE_CASHIER,
        ]);
    }

    /** @return array<string, mixed> */
    private function arguments(string $action): array
    {
        return [
            'action' => $action,
            'email' => self::EMAIL,
            '--confirm' => TeachingRoleAccessManager::confirmationPhrase($action, self::EMAIL),
            '--operator' => 'SIMRS Campus Test Operator',
            '--reason' => 'Temporary cashier teaching workflow verification',
            '--expected-environment' => 'test-simulation',
            '--expected-release-sha' => str_repeat('c', 40),
            '--expected-deployment-url' => 'test-deployment.vercel.app',
            '--expected-canonical-host' => 'localhost',
            '--ttl-minutes' => 15,
        ];
    }
}
