<?php

namespace Tests\Feature\Database;

use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class WarehouseTeachingRoleAccessMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Warehouse roster migration rehearsal remains deferred on exact-engine application connections.');
        }
    }

    public function test_warehouse_roster_expansion_can_roll_back_and_reapply_without_evidence(): void
    {
        $migration = require base_path(
            'database/migrations/2026_09_03_000100_expand_warehouse_teaching_role_access_roster.php',
        );

        $migration->down();
        $migration->up();

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('teaching_role_access_leases'));
    }

    public function test_warehouse_roster_expansion_rollback_refuses_identity_evidence(): void
    {
        User::factory()->unverified()->create([
            'email' => 'warehouse.inventory.controller.demo@example.invalid',
            'status' => 'DISABLED',
            'teaching_access_roster_key' => RoleCapabilityMatrix::ROLE_WAREHOUSE_INVENTORY_CONTROLLER,
            'is_system_administrator' => false,
        ]);
        $migration = require base_path(
            'database/migrations/2026_09_03_000100_expand_warehouse_teaching_role_access_roster.php',
        );

        try {
            $migration->down();
            $this->fail('Rollback should preserve the warehouse roster identities.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('rollback refused', $exception->getMessage());
        }

        $this->assertDatabaseHas('users', [
            'email' => 'warehouse.inventory.controller.demo@example.invalid',
            'teaching_access_roster_key' => RoleCapabilityMatrix::ROLE_WAREHOUSE_INVENTORY_CONTROLLER,
        ]);
    }
}
