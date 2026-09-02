<?php

namespace App\Support\Finance;

use App\Support\Database\SchemaQualifier;
use Illuminate\Support\Facades\DB;
use LogicException;

final class FinanceAccommodationTariffAppendOnlyGuard
{
    public const TABLES = ['finance_accommodation_tariff_binding_versions', 'finance_accommodation_tariff_operation_receipts', 'finance_accommodation_source_events'];

    public static function install(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            foreach (self::TABLES as $table) {
                foreach (['UPDATE' => 'update', 'DELETE' => 'delete'] as $verb => $suffix) {
                    DB::statement("CREATE TRIGGER {$table}_immutable_{$suffix} BEFORE {$verb} ON ".SchemaQualifier::table($table)." BEGIN SELECT RAISE(ABORT, 'accommodation tariff evidence is immutable'); END");
                }
            }
        } elseif ($driver === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION finance_accommodation_tariff_append_guard() RETURNS trigger AS $$ BEGIN IF current_setting('simrs.synthetic_reset', true) = '1' THEN IF TG_OP = 'DELETE' THEN RETURN OLD; END IF; RETURN NEW; END IF; RAISE EXCEPTION 'accommodation tariff evidence is immutable'; END; $$ LANGUAGE plpgsql");
            DB::unprepared("CREATE OR REPLACE FUNCTION finance_accommodation_tariff_truncate_guard() RETURNS trigger AS $$ BEGIN IF current_setting('simrs.synthetic_reset', true) = '1' THEN RETURN NULL; END IF; RAISE EXCEPTION 'accommodation tariff evidence is immutable'; END; $$ LANGUAGE plpgsql");
            foreach (self::TABLES as $table) {
                DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON ".SchemaQualifier::table($table).' FOR EACH ROW EXECUTE FUNCTION finance_accommodation_tariff_append_guard()');
                DB::statement("CREATE TRIGGER {$table}_truncate_guard BEFORE TRUNCATE ON ".SchemaQualifier::table($table).' FOR EACH STATEMENT EXECUTE FUNCTION finance_accommodation_tariff_truncate_guard()');
            }
        } elseif ($driver === 'mysql') {
            foreach (self::TABLES as $table) {
                foreach (['update', 'delete'] as $operation) {
                    DB::statement("CREATE TRIGGER {$table}_immutable_{$operation} BEFORE ".strtoupper($operation).' ON '.SchemaQualifier::table($table)." FOR EACH ROW BEGIN IF COALESCE(@simrs_synthetic_reset, 0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='accommodation tariff evidence is immutable'; END IF; END");
                }
            }
        }
    }

    public static function remove(): void
    {
        $driver = DB::connection()->getDriverName();
        foreach (self::TABLES as $table) {
            if ($driver === 'pgsql') {
                DB::statement("DROP TRIGGER IF EXISTS {$table}_immutable ON ".SchemaQualifier::table($table));
                DB::statement("DROP TRIGGER IF EXISTS {$table}_truncate_guard ON ".SchemaQualifier::table($table));
            } else {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_update");
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_delete");
            }
        }
    }

    public static function runSyntheticReset(callable $callback): mixed
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            FinanceTariffSchemaMutationScope::run(fn () => self::remove());
            try {
                return $callback();
            } finally {
                FinanceTariffSchemaMutationScope::run(fn () => self::install());
            }
        }
        if ($driver === 'pgsql') {
            if (DB::connection()->transactionLevel() < 1) {
                throw new LogicException('PostgreSQL accommodation tariff reset requires a transaction.');
            }
            DB::statement("SET LOCAL simrs.synthetic_reset = '1'");
        } elseif ($driver === 'mysql') {
            DB::statement('SET @simrs_synthetic_reset = 1');
        }
        try {
            return $callback();
        } finally {
            if ($driver === 'mysql') {
                DB::statement('SET @simrs_synthetic_reset = 0');
            }
        }
    }
}
