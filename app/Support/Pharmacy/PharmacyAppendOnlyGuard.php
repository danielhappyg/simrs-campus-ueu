<?php

namespace App\Support\Pharmacy;

use App\Support\Database\SchemaQualifier;
use Illuminate\Support\Facades\DB;

final class PharmacyAppendOnlyGuard
{
    public const TABLES = [
        'pharmacy_medicine_code_reservations', 'pharmacy_medicine_versions', 'pharmacy_depot_code_reservations',
        'pharmacy_depot_versions', 'pharmacy_prescription_versions', 'pharmacy_prescription_items',
        'pharmacy_verifications', 'pharmacy_preparation_allocations',
        'pharmacy_handovers', 'pharmacy_handover_items', 'pharmacy_returns', 'pharmacy_return_items',
        'pharmacy_stock_movements', 'pharmacy_financial_source_events', 'pharmacy_operation_receipts',
    ];

    public static function install(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            foreach (self::TABLES as $table) {
                foreach (['UPDATE' => 'update', 'DELETE' => 'delete'] as $verb => $suffix) {
                    DB::statement("CREATE TRIGGER {$table}_immutable_{$suffix} BEFORE {$verb} ON ".SchemaQualifier::table($table)." BEGIN SELECT RAISE(ABORT, 'pharmacy append-only evidence is immutable'); END");
                }
            }

            return;
        }
        if ($driver === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION pharmacy_append_only_guard() RETURNS trigger AS $$ BEGIN IF current_setting('simrs.synthetic_reset', true) = '1' THEN IF TG_OP = 'DELETE' THEN RETURN OLD; END IF; RETURN NEW; END IF; RAISE EXCEPTION 'pharmacy append-only evidence is immutable'; END; $$ LANGUAGE plpgsql");
            DB::unprepared("CREATE OR REPLACE FUNCTION pharmacy_append_only_truncate_guard() RETURNS trigger AS $$ BEGIN IF current_setting('simrs.synthetic_reset', true) = '1' THEN RETURN NULL; END IF; RAISE EXCEPTION 'pharmacy append-only evidence is immutable'; END; $$ LANGUAGE plpgsql");
            foreach (self::TABLES as $table) {
                DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON ".SchemaQualifier::table($table).' FOR EACH ROW EXECUTE FUNCTION pharmacy_append_only_guard()');
                DB::statement("CREATE TRIGGER {$table}_truncate_guard BEFORE TRUNCATE ON ".SchemaQualifier::table($table).' FOR EACH STATEMENT EXECUTE FUNCTION pharmacy_append_only_truncate_guard()');
            }

            return;
        }
        if ($driver === 'mysql') {
            foreach (self::TABLES as $table) {
                DB::statement("CREATE TRIGGER {$table}_immutable_update BEFORE UPDATE ON ".SchemaQualifier::table($table)." FOR EACH ROW BEGIN IF COALESCE(@simrs_synthetic_reset, 0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='pharmacy append-only evidence is immutable'; END IF; END");
                DB::statement("CREATE TRIGGER {$table}_immutable_delete BEFORE DELETE ON ".SchemaQualifier::table($table)." FOR EACH ROW BEGIN IF COALESCE(@simrs_synthetic_reset, 0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='pharmacy append-only evidence is immutable'; END IF; END");
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
            PharmacySchemaMutationScope::run(fn () => self::remove());
            try {
                return $callback();
            } finally {
                PharmacySchemaMutationScope::run(fn () => self::install());
            }
        }
        if ($driver === 'pgsql') {
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
