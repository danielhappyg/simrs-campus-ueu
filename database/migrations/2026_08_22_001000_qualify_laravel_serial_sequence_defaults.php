<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PgBouncer / pooled connections can drop search_path mid-request.
 * Unqualified nextval('*_id_seq') then fails even when Eloquent writes
 * schema-qualified tables (laravel.patients). Pin defaults to laravel.*.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = [
        'patients',
        'encounters',
        'clinical_entries',
        'clinics',
        'doctors',
        'clinic_schedules',
        'users',
        'roles',
        'permissions',
        'jobs',
        'failed_jobs',
        'passkeys',
        'migrations',
    ];

    public function up(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tables as $table) {
            $this->assertSequenceContract($connection, $table);
        }

        foreach ($this->tables as $table) {
            $qualifiedTable = $this->quotePostgresIdentifier('laravel').'.'.$this->quotePostgresIdentifier($table);
            $qualifiedSeq = 'laravel.'.$table.'_id_seq';

            $connection->statement(sprintf(
                'ALTER TABLE %s ALTER COLUMN id SET DEFAULT nextval(%s::regclass)',
                $qualifiedTable,
                $this->quotePostgresLiteral($qualifiedSeq),
            ));
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        throw new RuntimeException(
            'The PostgreSQL sequence-qualification migration is forward-only; refusing to restore search-path-dependent defaults.',
        );
    }

    private function assertSequenceContract(Connection $connection, string $table): void
    {
        $sequence = $table.'_id_seq';
        $evidence = $connection->selectOne(<<<'SQL'
            select
                c.relkind as table_kind,
                format_type(a.atttypid, a.atttypmod) as id_type,
                a.attnotnull as id_not_null,
                pg_get_expr(ad.adbin, ad.adrelid) as default_expression,
                s.oid as sequence_oid,
                s.relkind as sequence_kind,
                c.relowner = s.relowner as owners_match,
                exists (
                    select 1
                    from pg_depend owned
                    where owned.classid = 'pg_class'::regclass
                      and owned.objid = s.oid
                      and owned.refclassid = 'pg_class'::regclass
                      and owned.refobjid = c.oid
                      and owned.refobjsubid = a.attnum
                      and owned.deptype in ('a', 'i')
                ) as sequence_owned_by_id,
                exists (
                    select 1
                    from pg_depend default_dependency
                    where default_dependency.classid = 'pg_attrdef'::regclass
                      and default_dependency.objid = ad.oid
                      and default_dependency.refclassid = 'pg_class'::regclass
                      and default_dependency.refobjid = s.oid
                      and default_dependency.deptype = 'n'
                ) as default_depends_on_sequence
            from (
                select cast(? as text) as table_name, cast(? as text) as sequence_name
            ) expected
            left join pg_namespace n
              on n.nspname = 'laravel'
            left join pg_class c
              on c.relnamespace = n.oid
             and c.relname = expected.table_name
             and c.relkind in ('r', 'p')
            left join pg_attribute a
              on a.attrelid = c.oid
             and a.attname = 'id'
             and a.attnum > 0
             and not a.attisdropped
            left join pg_attrdef ad
              on ad.adrelid = c.oid
             and ad.adnum = a.attnum
            left join pg_class s
              on s.relnamespace = n.oid
             and s.relname = expected.sequence_name
             and s.relkind = 'S'
            SQL, [$table, $sequence]);

        $defaultExpression = is_string($evidence->default_expression ?? null)
            ? trim($evidence->default_expression)
            : null;
        $expectedDefault = '/\Anextval\(\'(?:(?:"?laravel"?\.)?"?'.preg_quote($sequence, '/').'"?)\'::regclass\)\z/';
        $valid = ($evidence->table_kind ?? null) === 'r'
            && in_array($evidence->id_type ?? null, ['integer', 'bigint'], true)
            && $this->databaseBoolean($evidence->id_not_null ?? null)
            && ($evidence->sequence_oid ?? null) !== null
            && ($evidence->sequence_kind ?? null) === 'S'
            && $this->databaseBoolean($evidence->owners_match ?? null)
            && $this->databaseBoolean($evidence->sequence_owned_by_id ?? null)
            && $this->databaseBoolean($evidence->default_depends_on_sequence ?? null)
            && $defaultExpression !== null
            && preg_match($expectedDefault, $defaultExpression) === 1;

        if (! $valid) {
            throw new RuntimeException(
                "Refusing to qualify unsafe PostgreSQL sequence drift for laravel.{$table}.id.",
            );
        }
    }

    private function databaseBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private function quotePostgresIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function quotePostgresLiteral(string $literal): string
    {
        return "'".str_replace("'", "''", $literal)."'";
    }
};
