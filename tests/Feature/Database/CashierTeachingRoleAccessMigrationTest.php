<?php

namespace Tests\Feature\Database;

use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class CashierTeachingRoleAccessMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_roster_expansion_can_roll_back_and_reapply_without_evidence(): void
    {
        $migration = require base_path('database/migrations/2026_09_01_000900_expand_cashier_teaching_role_access_roster.php');

        $migration->down();
        $migration->up();

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('teaching_role_access_leases'));
    }

    public function test_cashier_roster_expansion_rollback_refuses_identity_evidence(): void
    {
        User::factory()->unverified()->create([
            'email' => 'cashier.demo@example.invalid',
            'status' => 'DISABLED',
            'teaching_access_roster_key' => RoleCapabilityMatrix::ROLE_CASHIER,
            'is_system_administrator' => false,
        ]);
        $migration = require base_path('database/migrations/2026_09_01_000900_expand_cashier_teaching_role_access_roster.php');

        try {
            $migration->down();
            $this->fail('Rollback should preserve the cashier roster identity.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('rollback refused', $exception->getMessage());
        }

        $this->assertDatabaseHas('users', [
            'email' => 'cashier.demo@example.invalid',
            'teaching_access_roster_key' => RoleCapabilityMatrix::ROLE_CASHIER,
        ]);
    }
}
