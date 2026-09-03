<?php

namespace Tests\Feature\Laboratory;

use App\Models\LaboratoryExaminationMaster;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Database\SchemaQualifier;
use Database\Seeders\LaboratoryMastersSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class LaboratorySchemaAndSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_fresh_schema_preserves_legacy_tables_and_has_every_governed_table(): void
    {
        foreach (['lab_service_requests', 'lab_diagnostic_results'] as $legacy) {
            $this->assertTrue(Schema::hasTable($legacy), $legacy);
        }
        foreach (['laboratory_master_code_reservations', 'laboratory_examination_masters', 'laboratory_examination_master_versions', 'laboratory_orders', 'laboratory_order_cancellations', 'laboratory_specimen_attempts', 'laboratory_specimen_events', 'laboratory_result_versions', 'laboratory_critical_communications', 'laboratory_result_acknowledgements', 'laboratory_operation_receipts'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
    }

    public function test_empty_migration_can_down_and_reapply_without_touching_legacy_tables(): void
    {
        $migration = require base_path('database/migrations/2026_09_01_000200_create_cross_setting_laboratory_tables.php');

        if (DB::connection()->getDriverName() !== 'sqlite') {
            // Exact engines correctly refuse an out-of-order rollback while the later finance migration retains FKs.
            $this->assertTrue(Schema::hasTable('finance_laboratory_source_events'));
            $this->assertTrue(Schema::hasTable('laboratory_orders'));
            $this->assertTrue(collect(Schema::getForeignKeys(
                SchemaQualifier::table('finance_laboratory_source_events'),
            ))->contains(fn (array $foreignKey): bool => $foreignKey['foreign_table'] === 'laboratory_orders'));

            return;
        }

        $migration->down();
        $this->assertFalse(Schema::hasTable('laboratory_orders'));
        $this->assertTrue(Schema::hasTable('lab_service_requests'));
        $this->assertTrue(Schema::hasTable('lab_diagnostic_results'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('laboratory_orders'));
    }

    public function test_master_seeder_is_idempotent_and_uses_immutable_versions_and_receipts(): void
    {
        $admin = User::factory()->unverified()->create(['email' => LaboratoryMastersSeeder::ACTOR_EMAIL, 'status' => 'DISABLED', 'is_system_administrator' => false]);
        $admin->roles()->sync([Role::query()->where('slug', RoleCapabilityMatrix::ROLE_ADMIN)->sole()->id]);
        $this->seed(LaboratoryMastersSeeder::class);
        $count = count(LaboratoryMastersSeeder::catalogue());
        $this->assertDatabaseCount('laboratory_examination_masters', $count);
        $this->assertDatabaseCount('laboratory_examination_master_versions', $count);
        $this->assertDatabaseCount('laboratory_master_code_reservations', $count);
        $this->assertDatabaseCount('laboratory_operation_receipts', $count);
        $this->assertTrue(LaboratoryExaminationMaster::query()->get()->every(fn ($master) => count($master->components) >= 1));
        $this->seed(LaboratoryMastersSeeder::class);
        $this->assertDatabaseCount('laboratory_examination_masters', $count);
        $this->assertDatabaseCount('laboratory_operation_receipts', $count);
    }

    public function test_rollback_refuses_retained_business_or_audit_evidence(): void
    {
        $admin = User::factory()->unverified()->create(['email' => LaboratoryMastersSeeder::ACTOR_EMAIL, 'status' => 'DISABLED', 'is_system_administrator' => false]);
        $admin->roles()->sync([Role::query()->where('slug', RoleCapabilityMatrix::ROLE_ADMIN)->sole()->id]);
        $this->seed(LaboratoryMastersSeeder::class);
        $migration = require base_path('database/migrations/2026_09_01_000200_create_cross_setting_laboratory_tables.php');
        try {
            $migration->down();
            $this->fail('Rollback should preserve laboratory evidence.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('correlated audit evidence', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('laboratory_orders'));
        $this->assertDatabaseCount('laboratory_examination_masters', count(LaboratoryMastersSeeder::catalogue()));
    }
}
