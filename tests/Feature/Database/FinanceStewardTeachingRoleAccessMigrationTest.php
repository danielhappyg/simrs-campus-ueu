<?php

namespace Tests\Feature\Database;

use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class FinanceStewardTeachingRoleAccessMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_steward_roster_expansion_can_roll_back_and_reapply_without_evidence(): void
    {
        $migration = require base_path('database/migrations/2026_09_02_000200_expand_finance_steward_teaching_role_access_roster.php');

        $migration->down();
        $migration->up();

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('teaching_role_access_leases'));
    }

    public function test_finance_steward_roster_expansion_rollback_refuses_identity_evidence(): void
    {
        User::factory()->unverified()->create([
            'email' => 'finance.steward.demo@example.invalid',
            'status' => 'DISABLED',
            'teaching_access_roster_key' => RoleCapabilityMatrix::ROLE_FINANCE_STEWARD,
            'is_system_administrator' => false,
        ]);
        $migration = require base_path('database/migrations/2026_09_02_000200_expand_finance_steward_teaching_role_access_roster.php');

        try {
            $migration->down();
            $this->fail('Rollback should preserve the finance-steward roster identity.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('rollback refused', $exception->getMessage());
        }

        $this->assertDatabaseHas('users', [
            'email' => 'finance.steward.demo@example.invalid',
            'teaching_access_roster_key' => RoleCapabilityMatrix::ROLE_FINANCE_STEWARD,
        ]);
    }
}
