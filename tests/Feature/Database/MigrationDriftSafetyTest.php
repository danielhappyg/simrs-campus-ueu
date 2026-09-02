<?php

namespace Tests\Feature\Database;

use App\Support\Database\SchemaQualifier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class MigrationDriftSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_foundation_up_adopts_the_current_backend_contract_without_changing_evidence(): void
    {
        $auditTable = SchemaQualifier::table('audit_events');
        DB::table($auditTable)->insert($this->auditRow());

        $this->foundationMigration()->up();

        $this->assertSame(1, DB::table($auditTable)->count());
        $this->assertTrue(Schema::hasColumn($auditTable, 'actor_user_id'));
    }

    public function test_foundation_up_still_rejects_unregistered_audit_schema_evolution(): void
    {
        $auditTable = SchemaQualifier::table('audit_events');
        Schema::table($auditTable, function (Blueprint $table): void {
            $table->string('unregistered_audit_drift')->nullable();
            $table->unique(['id', 'outcome'], 'unregistered_audit_unique');
        });

        try {
            $this->foundationMigration()->up();
            $this->fail('Unregistered audit schema evolution must remain incompatible.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('unexpected-column:unregistered_audit_drift', $exception->getMessage());
            $this->assertStringContainsString('unexpected-unique-index:unregistered_audit_unique', $exception->getMessage());
        }
    }

    public function test_foundation_down_refuses_to_drop_retained_audit_evidence(): void
    {
        $auditTable = SchemaQualifier::table('audit_events');
        $row = $this->auditRow();
        DB::table($auditTable)->insert($row);

        try {
            $this->foundationMigration()->down();
            $this->fail('A rollback must not drop retained audit evidence.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('retained audit evidence exists', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable($auditTable));
        $this->assertDatabaseHas('audit_events', ['id' => $row['id']]);
    }

    public function test_foundation_down_refuses_an_empty_but_evolved_table_it_did_not_solely_own(): void
    {
        try {
            $this->foundationMigration()->down();
            $this->fail('A base migration must not drop an adopted or subsequently evolved table.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('non-owned or evolved audit_events schema', $exception->getMessage());
        }

        $auditTable = SchemaQualifier::table('audit_events');
        $this->assertTrue(Schema::hasTable($auditTable));
        $this->assertTrue(Schema::hasColumns($auditTable, ['actor_type', 'actor_reference']));
    }

    public function test_empty_foundation_can_roll_back_recreate_and_adopt_its_original_contract(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('MySQL DDL auto-commits; this destructive cycle is covered by clean CI migrations.');
        }

        if (Schema::hasTable(SchemaQualifier::table('warehouse_suppliers'))) {
            $this->warehouseMigration()->down();
        }
        $this->attributionMigration()->down();
        $this->foundationMigration()->down();
        $this->assertFalse(Schema::hasTable(SchemaQualifier::table('audit_events')));

        $migration = $this->foundationMigration();
        $migration->up();
        $migration->up();

        $auditTable = SchemaQualifier::table('audit_events');
        $this->assertTrue(Schema::hasTable($auditTable));
        $this->assertTrue(Schema::hasIndex($auditTable, ['resource_type', 'resource_id']));

        $actorForeign = collect(Schema::getForeignKeys($auditTable))
            ->firstWhere('columns', ['actor_user_id']);
        $this->assertIsArray($actorForeign);
        $this->assertSame('set null', $actorForeign['on_delete']);
    }

    public function test_marital_status_up_is_idempotent_for_the_active_backend(): void
    {
        $patientsTable = SchemaQualifier::table('patients');
        $before = collect(Schema::getColumns($patientsTable))->firstWhere('name', 'marital_status');

        $this->maritalStatusMigration()->up();

        $after = collect(Schema::getColumns($patientsTable))->firstWhere('name', 'marital_status');
        $this->assertSame($before, $after);
    }

    public function test_marital_status_down_then_up_recreates_the_intended_nullable_string(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('MySQL DDL auto-commits; this destructive cycle is covered by clean CI migrations.');
        }

        $migration = $this->maritalStatusMigration();
        $migration->down();
        $this->assertFalse(Schema::hasColumn(SchemaQualifier::table('patients'), 'marital_status'));

        $migration->up();
        $column = collect(Schema::getColumns(SchemaQualifier::table('patients')))
            ->firstWhere('name', 'marital_status');

        $this->assertIsArray($column);
        $this->assertTrue($column['nullable']);
        $this->assertNull($column['default']);
        $this->assertNull($column['generation']);
    }

    public function test_marital_status_down_refuses_to_drop_data_from_retained_patient_records(): void
    {
        $patientsTable = SchemaQualifier::table('patients');
        DB::table($patientsTable)->insert([
            'public_id' => (string) Str::ulid(),
            'medical_record_number' => 'RM-MIGRATION-SAFETY',
            'full_name' => 'Synthetic Migration Safety Patient',
            'date_of_birth' => '2000-01-01',
            'sex' => 'FEMALE',
            'is_synthetic' => true,
        ]);

        try {
            $this->maritalStatusMigration()->down();
            $this->fail('A rollback must not drop marital status from retained patient records.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('retained patient records exist', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn($patientsTable, 'marital_status'));
        $this->assertSame(1, DB::table($patientsTable)->count());
    }

    public function test_postgres_adopts_the_hosted_unbounded_marital_status_without_rewriting_it(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL hosted-reconciliation contract only.');
        }

        $migration = $this->maritalStatusMigration();
        $migration->down();
        $schema = SchemaQualifier::primarySchema() ?? 'public';
        DB::statement(sprintf(
            'ALTER TABLE %s.%s ADD COLUMN %s VARCHAR NULL',
            $this->quotePostgresIdentifier($schema),
            $this->quotePostgresIdentifier('patients'),
            $this->quotePostgresIdentifier('marital_status'),
        ));

        $migration->up();

        $column = collect(Schema::getColumns(SchemaQualifier::table('patients')))
            ->firstWhere('name', 'marital_status');
        $this->assertSame('character varying', $column['type']);
        $this->assertTrue($column['nullable']);
        $this->assertNull($column['default']);

        try {
            $migration->down();
            $this->fail('Rollback must not drop the adopted unbounded hosted column.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('incompatible patients.marital_status schema drift', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn(SchemaQualifier::table('patients'), 'marital_status'));
    }

    public function test_marital_status_up_rejects_incompatible_type_drift(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('MySQL DDL auto-commits; metadata drift is covered by the unit contract test.');
        }

        $migration = $this->maritalStatusMigration();
        $migration->down();
        Schema::table(SchemaQualifier::table('patients'), function (Blueprint $table): void {
            $table->text('marital_status')->nullable();
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('incompatible patients.marital_status schema drift');

        $migration->up();
    }

    public function test_sequence_qualification_is_backend_safe_and_postgres_forward_only(): void
    {
        $migration = $this->sequenceMigration();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $migration->up();
            $migration->down();
            $this->addToAssertionCount(1);

            return;
        }

        $migration->up();
        $migration->up();
        $sequence = DB::scalar(
            <<<'SQL'
                select pg_get_serial_sequence('"laravel"."patients"', 'id')
                SQL,
        );
        $this->assertSame('laravel.patients_id_seq', $sequence);

        try {
            $migration->down();
            $this->fail('PostgreSQL rollback must not restore an unqualified sequence default.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forward-only', $exception->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function auditRow(): array
    {
        return [
            'id' => (string) Str::ulid(),
            'recorded_at' => now(),
            'actor_user_id' => null,
            'action' => 'migration.adoption.tested',
            'resource_type' => 'database_migration',
            'resource_id' => '2026_08_21_000100',
            'resource_version' => null,
            'outcome' => 'SUCCESS',
            'reason' => null,
            'request_correlation_id' => null,
            'ip_hash' => null,
            'user_agent' => null,
            'metadata' => null,
            'actor_type' => null,
            'actor_reference' => null,
        ];
    }

    private function foundationMigration(): object
    {
        return require database_path('migrations/2026_08_21_000100_create_rebuild_foundation_tables.php');
    }

    private function maritalStatusMigration(): object
    {
        return require database_path('migrations/2026_08_22_000800_add_patient_marital_status.php');
    }

    private function attributionMigration(): object
    {
        return require database_path('migrations/2026_08_25_000300_expand_audit_actor_attribution.php');
    }

    private function warehouseMigration(): object
    {
        return require database_path('migrations/2026_09_03_000200_create_medication_replenishment_warehouse_custody_tables.php');
    }

    private function sequenceMigration(): object
    {
        return require database_path('migrations/2026_08_22_001000_qualify_laravel_serial_sequence_defaults.php');
    }

    private function quotePostgresIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
