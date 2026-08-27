<?php

namespace Tests\Feature\Database;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class TeachingRoleAccessMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_teaching_role_access_schema_is_present_after_a_fresh_migration(): void
    {
        $this->assertTrue(Schema::hasTable('teaching_role_access_leases'));

        foreach ([
            'teaching_access_epoch',
            'teaching_access_mutex',
            'teaching_access_roster_key',
            'teaching_access_lease_public_id',
            'teaching_access_expires_at_epoch',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('users', $column), $column);
        }

        foreach ([
            'public_id',
            'user_id',
            'expected_role',
            'credential_commitment',
            'password_state_commitment',
            'environment',
            'release_sha',
            'deployment_url',
            'canonical_host',
            'status',
            'active_slot',
            'activated_at_epoch',
            'expires_at_epoch',
            'ended_at_epoch',
            'end_reason',
            'operator',
            'reason',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('teaching_role_access_leases', $column), $column);
        }
    }

    public function test_migration_can_roll_back_and_reapply_cleanly(): void
    {
        $migration = require base_path('database/migrations/2026_08_27_000100_create_teaching_role_access_leases.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('teaching_role_access_leases'));
        $this->assertFalse(Schema::hasColumn('users', 'teaching_access_epoch'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('teaching_role_access_leases'));
        $this->assertTrue(Schema::hasColumn('users', 'teaching_access_epoch'));
    }

    public function test_migration_rollback_refuses_a_drifted_active_roster_identity_and_preserves_evidence(): void
    {
        User::factory()->create([
            'email' => 'registrar.demo@example.invalid',
            'status' => 'TEACHING_ACTIVE',
            'teaching_access_roster_key' => 'registrar',
            'teaching_access_epoch' => 9,
        ]);
        $migration = require base_path('database/migrations/2026_08_27_000100_create_teaching_role_access_leases.php');

        try {
            $migration->down();
            $this->fail('Rollback should refuse an active marker-bound roster identity.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('rollback refused', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('teaching_role_access_leases'));
        $this->assertTrue(Schema::hasColumn('users', 'teaching_access_epoch'));
        $this->assertDatabaseHas('users', [
            'email' => 'registrar.demo@example.invalid',
            'teaching_access_roster_key' => 'registrar',
            'teaching_access_epoch' => 9,
        ]);
    }

    public function test_migration_rollback_refuses_disabled_fencing_evidence_even_if_lease_rows_are_missing(): void
    {
        User::factory()->create([
            'email' => 'nurse.demo@example.invalid',
            'status' => 'DISABLED',
            'teaching_access_roster_key' => 'nurse',
            'teaching_access_epoch' => 11,
            'teaching_access_mutex' => 4,
            'teaching_access_lease_public_id' => '01JTEACHINGFENCE000000001',
            'teaching_access_expires_at_epoch' => 1_788_000_000,
        ]);
        $migration = require base_path('database/migrations/2026_08_27_000100_create_teaching_role_access_leases.php');

        try {
            $migration->down();
            $this->fail('Rollback should preserve disabled-account fencing evidence after lease-row loss.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('rollback refused', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('teaching_role_access_leases'));
        $this->assertTrue(Schema::hasColumn('users', 'teaching_access_mutex'));
        $this->assertDatabaseHas('users', [
            'email' => 'nurse.demo@example.invalid',
            'status' => 'DISABLED',
            'teaching_access_epoch' => 11,
            'teaching_access_mutex' => 4,
            'teaching_access_lease_public_id' => '01JTEACHINGFENCE000000001',
            'teaching_access_expires_at_epoch' => 1_788_000_000,
        ]);
    }
}
