<?php

namespace Tests\Unit\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class MigrationAdoptionContractTest extends TestCase
{
    public function test_foundation_adopts_the_exact_hosted_postgres_contract_with_known_legacy_extras(): void
    {
        $this->mockPostgresConnection();
        $schema = $this->mockSchema();
        $schema->shouldReceive('hasTable')->once()->with('laravel.audit_events')->andReturnTrue();
        $schema->shouldReceive('getColumns')->once()->with('laravel.audit_events')
            ->andReturn($this->hostedAuditColumns());
        $schema->shouldReceive('getIndexes')->once()->with('laravel.audit_events')
            ->andReturn($this->hostedAuditIndexes());
        $schema->shouldReceive('getForeignKeys')->once()->with('laravel.audit_events')
            ->andReturn($this->hostedAuditForeignKeys());
        $schema->shouldReceive('create')->never();

        $this->foundationMigration()->up();

        $this->addToAssertionCount(1);
    }

    public function test_foundation_adopts_the_registered_warehouse_audit_evolution(): void
    {
        $columns = [
            ...$this->hostedAuditColumns(),
            $this->column('warehouse_operation_snapshot', 'varchar', 'character varying(64)', true),
            $this->column('warehouse_result_version_snapshot', 'int4', 'integer', true),
            $this->column('warehouse_result_digest_snapshot', 'varchar', 'character varying(64)', true),
            $this->column('warehouse_control_total_snapshot', 'int8', 'bigint', true),
        ];
        $indexes = [
            ...$this->hostedAuditIndexes(),
            $this->index('ae_warehouse_actor_uq', ['id', 'actor_user_id'], true),
            $this->index('ae_warehouse_action_resource_uq', ['id', 'action', 'resource_type'], true),
            $this->index('ae_warehouse_result_identity_uq', ['id', 'resource_id', 'warehouse_result_version_snapshot'], true),
            $this->index('ae_warehouse_control_uq', ['id', 'warehouse_operation_snapshot', 'warehouse_result_digest_snapshot', 'warehouse_control_total_snapshot'], true),
        ];

        $this->mockPostgresConnection();
        $schema = $this->mockSchema();
        $schema->shouldReceive('hasTable')->once()->with('laravel.audit_events')->andReturnTrue();
        $schema->shouldReceive('getColumns')->once()->with('laravel.audit_events')->andReturn($columns);
        $schema->shouldReceive('getIndexes')->once()->with('laravel.audit_events')->andReturn($indexes);
        $schema->shouldReceive('getForeignKeys')->once()->with('laravel.audit_events')->andReturn($this->hostedAuditForeignKeys());
        $schema->shouldReceive('create')->never();

        $this->foundationMigration()->up();

        $this->addToAssertionCount(1);
    }

    public function test_foundation_rejects_partial_warehouse_audit_evolution(): void
    {
        $columns = [
            ...$this->hostedAuditColumns(),
            $this->column('warehouse_operation_snapshot', 'varchar', 'character varying(64)', true),
        ];

        $this->mockPostgresConnection();
        $schema = $this->mockSchema();
        $schema->shouldReceive('hasTable')->once()->with('laravel.audit_events')->andReturnTrue();
        $schema->shouldReceive('getColumns')->once()->andReturn($columns);
        $schema->shouldReceive('getIndexes')->once()->andReturn($this->hostedAuditIndexes());
        $schema->shouldReceive('getForeignKeys')->once()->andReturn($this->hostedAuditForeignKeys());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('partial-warehouse-audit-columns');

        $this->foundationMigration()->up();
    }

    public function test_foundation_rejects_a_preexisting_table_with_incompatible_required_drift(): void
    {
        $columns = $this->hostedAuditColumns();
        $outcome = array_search('outcome', array_column($columns, 'name'), true);
        $columns[$outcome]['default'] = null;

        $this->mockPostgresConnection();
        $schema = $this->mockSchema();
        $schema->shouldReceive('hasTable')->once()->with('laravel.audit_events')->andReturnTrue();
        $schema->shouldReceive('getColumns')->once()->andReturn($columns);
        $schema->shouldReceive('getIndexes')->once()->andReturn($this->hostedAuditIndexes());
        $schema->shouldReceive('getForeignKeys')->once()->andReturn($this->hostedAuditForeignKeys());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('column:outcome');

        $this->foundationMigration()->up();
    }

    public function test_foundation_rejects_cascading_actor_deletion_drift(): void
    {
        $foreignKeys = $this->hostedAuditForeignKeys();
        $foreignKeys[0]['on_delete'] = 'cascade';

        $this->mockPostgresConnection();
        $schema = $this->mockSchema();
        $schema->shouldReceive('hasTable')->once()->with('laravel.audit_events')->andReturnTrue();
        $schema->shouldReceive('getColumns')->once()->andReturn($this->hostedAuditColumns());
        $schema->shouldReceive('getIndexes')->once()->andReturn($this->hostedAuditIndexes());
        $schema->shouldReceive('getForeignKeys')->once()->andReturn($foreignKeys);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('foreign-key:actor_user_id');

        $this->foundationMigration()->up();
    }

    public function test_foundation_rejects_a_legacy_foreign_key_name_that_the_next_migration_cannot_replace(): void
    {
        $foreignKeys = $this->hostedAuditForeignKeys();
        $foreignKeys[0]['name'] = 'platform_actor_user_foreign';

        $this->mockPostgresConnection();
        $schema = $this->mockSchema();
        $schema->shouldReceive('hasTable')->once()->with('laravel.audit_events')->andReturnTrue();
        $schema->shouldReceive('getColumns')->once()->andReturn($this->hostedAuditColumns());
        $schema->shouldReceive('getIndexes')->once()->andReturn($this->hostedAuditIndexes());
        $schema->shouldReceive('getForeignKeys')->once()->andReturn($foreignKeys);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('foreign-key:actor_user_id');

        $this->foundationMigration()->up();
    }

    public function test_foundation_rejects_a_partial_hosted_legacy_context_shape(): void
    {
        $columns = array_values(array_filter(
            $this->hostedAuditColumns(),
            fn (array $column): bool => $column['name'] !== 'encounter_id',
        ));

        $this->mockPostgresConnection();
        $schema = $this->mockSchema();
        $schema->shouldReceive('hasTable')->once()->with('laravel.audit_events')->andReturnTrue();
        $schema->shouldReceive('getColumns')->once()->andReturn($columns);
        $schema->shouldReceive('getIndexes')->once()->andReturn($this->hostedAuditIndexes());
        $schema->shouldReceive('getForeignKeys')->once()->andReturn($this->hostedAuditForeignKeys());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('partial-legacy-audit-context-columns');

        $this->foundationMigration()->up();
    }

    public function test_marital_status_adopts_only_the_hosted_nullable_unbounded_postgres_varchar(): void
    {
        $this->mockPostgresConnection();
        $schema = $this->mockSchema();
        $schema->shouldReceive('hasTable')->once()->with('laravel.patients')->andReturnTrue();
        $schema->shouldReceive('getColumns')->once()->with('laravel.patients')->andReturn([
            $this->column('marital_status', 'varchar', 'character varying', true),
        ]);
        $schema->shouldReceive('table')->never();

        $this->maritalStatusMigration()->up();

        $this->addToAssertionCount(1);
    }

    public function test_marital_status_rejects_a_default_even_when_the_type_and_nullability_match(): void
    {
        $this->mockPostgresConnection();
        $schema = $this->mockSchema();
        $schema->shouldReceive('hasTable')->once()->with('laravel.patients')->andReturnTrue();
        $schema->shouldReceive('getColumns')->once()->andReturn([
            $this->column('marital_status', 'varchar', 'character varying', true, "'UNKNOWN'::character varying"),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('incompatible patients.marital_status schema drift');

        $this->maritalStatusMigration()->up();
    }

    public function test_sequence_qualification_validates_every_contract_before_setting_qualified_defaults(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
        $connection->shouldReceive('selectOne')->times(13)
            ->andReturnUsing(fn (string $query, array $bindings): object => $this->sequenceEvidence($bindings[0]));
        $connection->shouldReceive('statement')->times(13)
            ->withArgs(fn (string $query): bool => str_contains(
                $query,
                "SET DEFAULT nextval('laravel.",
            ))
            ->andReturnTrue();
        DB::shouldReceive('connection')->once()->andReturn($connection);

        $this->sequenceMigration()->up();

        $this->addToAssertionCount(1);
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('unsafeSequenceContracts')]
    public function test_sequence_qualification_rejects_unsafe_catalog_contracts(array $overrides): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
        $connection->shouldReceive('selectOne')->once()
            ->andReturn($this->sequenceEvidence('patients', $overrides));
        $connection->shouldReceive('statement')->never();
        DB::shouldReceive('connection')->once()->andReturn($connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsafe PostgreSQL sequence drift for laravel.patients.id');

        $this->sequenceMigration()->up();
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unsafeSequenceContracts(): iterable
    {
        yield 'missing table' => [['table_kind' => null]];
        yield 'wrong id type' => [['id_type' => 'text']];
        yield 'nullable id' => [['id_not_null' => false]];
        yield 'missing sequence' => [['sequence_oid' => null, 'sequence_kind' => null]];
        yield 'different owner' => [['owners_match' => false]];
        yield 'sequence not owned by id' => [['sequence_owned_by_id' => false]];
        yield 'default not dependent on sequence' => [['default_depends_on_sequence' => false]];
        yield 'default uses another sequence' => [[
            'default_expression' => "nextval('laravel.other_id_seq'::regclass)",
        ]];
    }

    public function test_postgres_sequence_qualification_down_is_forward_only(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
        $connection->shouldReceive('statement')->never();
        DB::shouldReceive('connection')->once()->andReturn($connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('forward-only');

        $this->sequenceMigration()->down();
    }

    private function mockPostgresConnection(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.search_path' => 'laravel',
        ]);

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
        DB::shouldReceive('connection')->once()->andReturn($connection);
    }

    private function mockSchema(): Builder
    {
        $schema = Mockery::mock(Builder::class);
        Schema::swap($schema);

        return $schema;
    }

    /** @return list<array<string, mixed>> */
    private function hostedAuditColumns(): array
    {
        return [
            $this->column('id', 'bpchar', 'character(26)', false),
            $this->column('recorded_at', 'timestamp', 'timestamp(6) without time zone', false),
            $this->column('actor_user_id', 'int8', 'bigint', true),
            $this->column('assignment_id', 'int8', 'bigint', true),
            $this->column('session_id', 'int8', 'bigint', true),
            $this->column('action', 'varchar', 'character varying(255)', false),
            $this->column('resource_type', 'varchar', 'character varying(255)', false),
            $this->column('resource_id', 'varchar', 'character varying(255)', true),
            $this->column('resource_version', 'varchar', 'character varying(255)', true),
            $this->column('outcome', 'varchar', 'character varying(255)', false, "'SUCCESS'::character varying"),
            $this->column('reason', 'varchar', 'character varying(255)', true),
            $this->column('request_correlation_id', 'bpchar', 'character(26)', true),
            $this->column('ip_hash', 'varchar', 'character varying(64)', true),
            $this->column('user_agent', 'varchar', 'character varying(255)', true),
            $this->column('metadata', 'json', 'json', true),
            $this->column('patient_id', 'int8', 'bigint', true),
            $this->column('encounter_id', 'int8', 'bigint', true),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function hostedAuditIndexes(): array
    {
        return [
            $this->index('audit_events_pkey', ['id'], true, true),
            $this->index('audit_events_recorded_at_index', ['recorded_at']),
            $this->index('audit_events_action_index', ['action']),
            $this->index('audit_events_outcome_index', ['outcome']),
            $this->index('audit_events_request_correlation_id_index', ['request_correlation_id']),
            $this->index('audit_resource_lookup', ['resource_type', 'resource_id']),
            $this->index('audit_case_context', ['session_id', 'patient_id', 'encounter_id']),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function hostedAuditForeignKeys(): array
    {
        return [[
            'name' => 'audit_events_actor_user_id_foreign',
            'columns' => ['actor_user_id'],
            'foreign_schema' => 'laravel',
            'foreign_table' => 'users',
            'foreign_columns' => ['id'],
            'on_update' => 'no action',
            'on_delete' => 'set null',
        ]];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return object{
     *     table_kind: mixed,
     *     id_type: mixed,
     *     id_not_null: mixed,
     *     default_expression: mixed,
     *     sequence_oid: mixed,
     *     sequence_kind: mixed,
     *     owners_match: mixed,
     *     sequence_owned_by_id: mixed,
     *     default_depends_on_sequence: mixed
     * }
     */
    private function sequenceEvidence(string $table, array $overrides = []): object
    {
        return (object) array_merge([
            'table_kind' => 'r',
            'id_type' => $table === 'migrations' ? 'integer' : 'bigint',
            'id_not_null' => true,
            'default_expression' => "nextval('laravel.{$table}_id_seq'::regclass)",
            'sequence_oid' => 12345,
            'sequence_kind' => 'S',
            'owners_match' => true,
            'sequence_owned_by_id' => true,
            'default_depends_on_sequence' => true,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function column(
        string $name,
        string $typeName,
        string $type,
        bool $nullable,
        mixed $default = null,
    ): array {
        return [
            'name' => $name,
            'type_name' => $typeName,
            'type' => $type,
            'collation' => null,
            'nullable' => $nullable,
            'default' => $default,
            'auto_increment' => false,
            'comment' => null,
            'generation' => null,
        ];
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private function index(string $name, array $columns, bool $unique = false, bool $primary = false): array
    {
        return [
            'name' => $name,
            'columns' => $columns,
            'type' => null,
            'unique' => $unique,
            'primary' => $primary,
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

    private function sequenceMigration(): object
    {
        return require database_path('migrations/2026_08_22_001000_qualify_laravel_serial_sequence_defaults.php');
    }
}
