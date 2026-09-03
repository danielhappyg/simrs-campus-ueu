<?php

namespace Tests\Feature\Authorization;

use App\Models\TeachingRoleAccessLease;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Authorization\TeachingRoleAccessLeaseGuard;
use App\Support\Authorization\TeachingRoleAccessManager;
use App\Support\Warehouse\WarehouseActorPolicy;
use Database\Seeders\DemoActorsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class WarehouseTeachingRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private const DEMO_ROSTER = [
        'procurement.officer.demo@example.invalid' => RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER,
        'procurement.approver.demo@example.invalid' => RoleCapabilityMatrix::ROLE_PROCUREMENT_APPROVER,
        'warehouse.receiver.demo@example.invalid' => RoleCapabilityMatrix::ROLE_WAREHOUSE_RECEIVER,
        'warehouse.inventory.controller.demo@example.invalid' => RoleCapabilityMatrix::ROLE_WAREHOUSE_INVENTORY_CONTROLLER,
        'warehouse.inventory.supervisor.demo@example.invalid' => RoleCapabilityMatrix::ROLE_WAREHOUSE_INVENTORY_SUPERVISOR,
    ];

    /** @var list<string> */
    private const WAREHOUSE_CAPABILITIES = [
        Capability::WAREHOUSE_SUPPLIER_VIEW,
        Capability::WAREHOUSE_SUPPLIER_MANAGE,
        Capability::WAREHOUSE_PURCHASE_ORDER_VIEW,
        Capability::WAREHOUSE_PURCHASE_ORDER_CREATE,
        Capability::WAREHOUSE_PURCHASE_ORDER_SUBMIT,
        Capability::WAREHOUSE_PURCHASE_ORDER_REVIEW,
        Capability::WAREHOUSE_RECEIPT_VIEW,
        Capability::WAREHOUSE_RECEIPT_RECORD,
        Capability::WAREHOUSE_TRANSFER_VIEW,
        Capability::WAREHOUSE_TRANSFER_DISPATCH,
        Capability::WAREHOUSE_TRANSFER_ACCEPT,
        Capability::WAREHOUSE_RETURN_SUPPLIER,
        Capability::WAREHOUSE_RETURN_UNIT,
        Capability::WAREHOUSE_STOCK_CARD_VIEW,
        Capability::WAREHOUSE_CORRECTION_REQUEST,
        Capability::WAREHOUSE_CORRECTION_REVIEW,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'simulation.warehouse_capability_enabled' => true,
            'simulation.demo_seed_enabled' => true,
            'simulation.mode' => 'SIMULATION',
            'simulation.synthetic_only' => true,
            'simulation.demo_account_password' => Str::random(32),
            'simulation.teaching_role_access_password' => Str::random(32),
            'simulation.teaching_role_access_commitment_key' => Str::random(48),
            'simulation.teaching_role_access_environment' => 'test-simulation',
            'simulation.teaching_role_access_release_sha' => str_repeat('d', 40),
            'simulation.teaching_role_access_deployment_url' => 'test-deployment.vercel.app',
            'simulation.teaching_role_access_canonical_host' => 'localhost',
            'simulation.teaching_role_access_max_ttl_minutes' => 30,
            'simulation.rebuild_admin_email' => 'rebuild.admin@example.invalid',
            'session.driver' => 'database',
            'session.table' => 'sessions',
            'session.connection' => config('database.default'),
        ]);

        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Warehouse teaching identities require the intentionally unrun exact-engine warehouse migrations.');
        }

        $this->seed(RbacSeeder::class);
        $this->seed(DemoActorsSeeder::class);
    }

    public function test_documented_warehouse_capabilities_have_exact_role_assignments(): void
    {
        $this->assertSame(
            self::WAREHOUSE_CAPABILITIES,
            array_values(array_filter(
                Capability::all(),
                static fn (string $capability): bool => str_starts_with($capability, 'warehouse.'),
            )),
        );

        $this->assertSame(
            [
                Capability::WAREHOUSE_SUPPLIER_VIEW,
                Capability::WAREHOUSE_SUPPLIER_MANAGE,
                Capability::WAREHOUSE_PURCHASE_ORDER_VIEW,
                Capability::WAREHOUSE_PURCHASE_ORDER_CREATE,
                Capability::WAREHOUSE_PURCHASE_ORDER_SUBMIT,
            ],
            RoleCapabilityMatrix::capabilitiesFor(RoleCapabilityMatrix::ROLE_PROCUREMENT_OFFICER),
        );
        $this->assertSame(
            [
                Capability::WAREHOUSE_PURCHASE_ORDER_VIEW,
                Capability::WAREHOUSE_PURCHASE_ORDER_REVIEW,
                Capability::WAREHOUSE_RETURN_SUPPLIER,
            ],
            RoleCapabilityMatrix::capabilitiesFor(RoleCapabilityMatrix::ROLE_PROCUREMENT_APPROVER),
        );
        $this->assertSame(
            [Capability::WAREHOUSE_RECEIPT_VIEW, Capability::WAREHOUSE_RECEIPT_RECORD],
            RoleCapabilityMatrix::capabilitiesFor(RoleCapabilityMatrix::ROLE_WAREHOUSE_RECEIVER),
        );
        $this->assertSame(
            [
                Capability::WAREHOUSE_TRANSFER_VIEW,
                Capability::WAREHOUSE_TRANSFER_DISPATCH,
                Capability::WAREHOUSE_RETURN_SUPPLIER,
                Capability::WAREHOUSE_RETURN_UNIT,
                Capability::WAREHOUSE_STOCK_CARD_VIEW,
                Capability::WAREHOUSE_CORRECTION_REQUEST,
            ],
            RoleCapabilityMatrix::capabilitiesFor(RoleCapabilityMatrix::ROLE_WAREHOUSE_INVENTORY_CONTROLLER),
        );
        $this->assertSame(
            [Capability::WAREHOUSE_STOCK_CARD_VIEW, Capability::WAREHOUSE_CORRECTION_REVIEW],
            RoleCapabilityMatrix::capabilitiesFor(RoleCapabilityMatrix::ROLE_WAREHOUSE_INVENTORY_SUPERVISOR),
        );

        $this->assertContains(
            Capability::WAREHOUSE_TRANSFER_VIEW,
            RoleCapabilityMatrix::capabilitiesFor(RoleCapabilityMatrix::ROLE_PHARMACY_INVENTORY_CONTROLLER),
        );
        $this->assertContains(
            Capability::WAREHOUSE_TRANSFER_ACCEPT,
            RoleCapabilityMatrix::capabilitiesFor(RoleCapabilityMatrix::ROLE_PHARMACY_INVENTORY_CONTROLLER),
        );
        $this->assertContains(
            Capability::WAREHOUSE_RETURN_UNIT,
            RoleCapabilityMatrix::capabilitiesFor(RoleCapabilityMatrix::ROLE_PHARMACY_INVENTORY_CONTROLLER),
        );
        $this->assertSame([], array_values(array_intersect(
            self::WAREHOUSE_CAPABILITIES,
            RoleCapabilityMatrix::capabilitiesFor(RoleCapabilityMatrix::ROLE_ADMIN),
        )));
    }

    public function test_warehouse_demo_identities_are_exact_disabled_roster_members(): void
    {
        $this->assertSame(
            self::DEMO_ROSTER,
            array_intersect_key(TeachingRoleAccessManager::ROSTER, self::DEMO_ROSTER),
        );

        $expectedNames = [
            'procurement.officer.demo@example.invalid' => 'Demo Petugas Pengadaan',
            'procurement.approver.demo@example.invalid' => 'Demo Penyetuju Pengadaan',
            'warehouse.receiver.demo@example.invalid' => 'Demo Penerima Gudang Farmasi',
            'warehouse.inventory.controller.demo@example.invalid' => 'Demo Pengelola Persediaan Gudang',
            'warehouse.inventory.supervisor.demo@example.invalid' => 'Demo Supervisor Persediaan Gudang',
        ];

        foreach (self::DEMO_ROSTER as $email => $role) {
            $actor = User::query()->where('email', $email)->sole();

            $this->assertSame($expectedNames[$email], $actor->name);
            $this->assertSame('DISABLED', $actor->status);
            $this->assertNull($actor->email_verified_at);
            $this->assertFalse($actor->is_system_administrator);
            $this->assertSame($role, $actor->teaching_access_roster_key);
            $this->assertSame([$role], $actor->roleSlugs());
        }

        $receiver = User::query()->where('email', 'warehouse.receiver.demo@example.invalid')->sole();
        $controller = User::query()->where('email', 'warehouse.inventory.controller.demo@example.invalid')->sole();
        $pharmacyController = User::query()->where('email', 'pharmacy.inventory.demo@example.invalid')->sole();

        $this->assertTrue($receiver->canCapability(Capability::WAREHOUSE_RECEIPT_RECORD));
        $this->assertFalse($receiver->canCapability(Capability::WAREHOUSE_TRANSFER_DISPATCH));
        $this->assertTrue($controller->canCapability(Capability::WAREHOUSE_TRANSFER_DISPATCH));
        $this->assertFalse($controller->canCapability(Capability::WAREHOUSE_TRANSFER_ACCEPT));
        $this->assertTrue($pharmacyController->canCapability(Capability::WAREHOUSE_TRANSFER_ACCEPT));
    }

    public function test_managed_warehouse_policy_requires_a_current_host_bound_lease(): void
    {
        $policy = app(WarehouseActorPolicy::class);
        $email = 'warehouse.receiver.demo@example.invalid';
        $receiver = User::query()->where('email', $email)->sole();
        $this->assertFalse($policy->canRecordReceipt($receiver));

        $this->assertSame(0, Artisan::call('teaching:role-access', $this->accessArguments('activate', $email)), Artisan::output());
        $receiver = $receiver->fresh();
        $this->assertTrue($policy->canRecordReceipt($receiver));

        config(['simulation.teaching_role_access_canonical_host' => 'drifted.example.invalid']);
        $this->assertFalse($policy->canRecordReceipt($receiver));
        config(['simulation.teaching_role_access_canonical_host' => 'localhost']);
        $this->assertTrue($policy->canRecordReceipt($receiver));

        $expiredAt = app(TeachingRoleAccessLeaseGuard::class)->databaseEpoch() - 1;
        TeachingRoleAccessLease::query()
            ->where('user_id', $receiver->id)
            ->where('status', 'ACTIVE')
            ->update(['expires_at_epoch' => $expiredAt]);
        User::query()->whereKey($receiver->id)->update(['teaching_access_expires_at_epoch' => $expiredAt]);
        $this->assertFalse($policy->canRecordReceipt($receiver->fresh()));

        $this->assertSame(0, Artisan::call('teaching:role-access', $this->accessArguments('revoke', $email)), Artisan::output());
        $this->assertFalse($policy->canRecordReceipt($receiver->fresh()));
    }

    public function test_demo_actor_seeder_enforces_all_three_safety_switches_directly(): void
    {
        foreach ([
            ['simulation.demo_seed_enabled' => false],
            ['simulation.mode' => 'PRODUCTION'],
            ['simulation.synthetic_only' => false],
        ] as $unsafe) {
            config([
                'simulation.demo_seed_enabled' => true,
                'simulation.mode' => 'SIMULATION',
                'simulation.synthetic_only' => true,
                ...$unsafe,
            ]);

            try {
                $this->seed(DemoActorsSeeder::class);
                $this->fail('Direct demo actor seeding must enforce every simulation safety switch.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('enabled demo seeding in synthetic-only SIMULATION mode', $exception->getMessage());
            }
        }
    }

    /** @return array<string, mixed> */
    private function accessArguments(string $action, string $email): array
    {
        return [
            'action' => $action,
            'email' => $email,
            '--confirm' => TeachingRoleAccessManager::confirmationPhrase($action, $email),
            '--operator' => 'SIMRS Campus Warehouse Test Operator',
            '--reason' => 'Temporary warehouse teaching workflow verification',
            '--expected-environment' => 'test-simulation',
            '--expected-release-sha' => str_repeat('d', 40),
            '--expected-deployment-url' => 'test-deployment.vercel.app',
            '--expected-canonical-host' => 'localhost',
            '--ttl-minutes' => 15,
        ];
    }
}
